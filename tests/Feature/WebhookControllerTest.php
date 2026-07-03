<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Events\SubscriptionChargeFailed;
use Aizuddinmanap\CashierChip\PaymentMethod;
use Aizuddinmanap\CashierChip\Subscription;
use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Aizuddinmanap\CashierChip\Transaction;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class WebhookControllerTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable signature verification for tests
        config()->set('cashier.webhook.public_key', null);
        config()->set('cashier.webhook.secret', null);

        // Re-query is exercised in dedicated tests; disable it elsewhere so the
        // other tests don't reach out to the Chip API.
        config()->set('cashier.webhook.requery', false);

        $this->user = $this->createUser(['chip_id' => 'client_123']);
    }

    #[Test]
    public function it_returns_400_when_no_event_type_or_status(): void
    {
        $response = $this->postJson('/chip/webhook', ['id' => 'purchase_123']);

        $response->assertStatus(400);
    }

    #[Test]
    public function purchase_paid_marks_transaction_success(): void
    {
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_paid',
            'chip_id' => 'purchase_paid_1',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        $response = $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.paid',
            'id' => 'purchase_paid_1',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('success', $transaction->fresh()->status);
        $this->assertNotNull($transaction->fresh()->processed_at);
    }

    #[Test]
    public function success_callback_without_event_type_marks_transaction_success(): void
    {
        // Chip's per-purchase success_callback POSTs the raw Purchase object,
        // which has a "status" but no "event_type". This is the official
        // WooCommerce plugin's primary mechanism and must be handled.
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_callback',
            'chip_id' => 'purchase_callback_1',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        $response = $this->postJson('/chip/webhook', [
            'id' => 'purchase_callback_1',
            'status' => 'paid',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('success', $transaction->fresh()->status);
        $this->assertNotNull($transaction->fresh()->processed_at);
    }

    #[Test]
    public function subscription_charge_failure_marks_subscription_past_due(): void
    {
        Event::fake([SubscriptionChargeFailed::class]);

        $subscription = $this->user->subscriptions()->create([
            'name' => 'default',
            'chip_id' => 'btc_fail_1',
            'chip_billing_template_id' => 'bt_fail',
            'chip_status' => 'active',
            'quantity' => 1,
        ]);

        $response = $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.subscription_charge_failure',
            'id' => 'purchase_fail_1',
            'billing_template_id' => 'bt_fail',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('past_due', $subscription->fresh()->chip_status);
        Event::assertDispatched(SubscriptionChargeFailed::class);
    }

    #[Test]
    public function subscription_cycle_charge_reactivates_and_records_transaction(): void
    {
        $subscription = $this->user->subscriptions()->create([
            'name' => 'default',
            'chip_id' => 'btc_ok_1',
            'chip_billing_template_id' => 'bt_ok',
            'chip_status' => 'past_due',
            'quantity' => 1,
        ]);

        $response = $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.paid',
            'id' => 'purchase_cycle_1',
            'billing_template_id' => 'bt_ok',
            'purchase' => ['total' => 5000, 'currency' => 'MYR'],
        ]);

        $response->assertStatus(200);
        $this->assertEquals('active', $subscription->fresh()->chip_status);
        $this->assertDatabaseHas('transactions', [
            'chip_id' => 'purchase_cycle_1',
            'total' => 5000,
            'status' => 'success',
        ]);
    }

    #[Test]
    public function legacy_purchase_completed_event_still_marks_success(): void
    {
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_legacy',
            'chip_id' => 'purchase_completed_1',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        $response = $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.completed',
            'id' => 'purchase_completed_1',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('success', $transaction->fresh()->status);
    }

    #[Test]
    public function duplicate_paid_callback_does_not_redispatch_completed_event(): void
    {
        \Illuminate\Support\Facades\Event::fake([
            \Aizuddinmanap\CashierChip\Events\TransactionCompleted::class,
        ]);

        $this->user->transactions()->create([
            'id' => 'txn_dup',
            'chip_id' => 'purchase_dup_1',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        $payload = ['event_type' => 'purchase.paid', 'id' => 'purchase_dup_1'];

        $this->postJson('/chip/webhook', $payload)->assertStatus(200);
        $this->postJson('/chip/webhook', $payload)->assertStatus(200);

        \Illuminate\Support\Facades\Event::assertDispatchedTimes(
            \Aizuddinmanap\CashierChip\Events\TransactionCompleted::class,
            1
        );
    }

    #[Test]
    public function purchase_completed_stores_recurring_token_when_present(): void
    {
        $payload = [
            'event_type' => 'purchase.completed',
            'id' => 'purchase_with_token',
            'is_recurring_token' => true,
            'client' => ['email' => $this->user->email],
            'transaction_data' => [
                'extra' => [
                    'card_brand' => 'visa',
                    'masked_pan' => '••••••••1234',
                    'expiry_month' => '06',
                    'expiry_year' => '28',
                    'cardholder_name' => 'JOHN DOE',
                    'card_issuer_country' => 'MY',
                    'card_type' => 'credit',
                ],
            ],
        ];

        $response = $this->postJson('/chip/webhook', $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('payment_methods', [
            'chip_token_id' => 'purchase_with_token',
            'card_brand' => 'visa',
            'card_last_four' => '1234',
        ]);
    }

    #[Test]
    public function purchase_payment_failure_marks_transaction_failed(): void
    {
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_failed',
            'chip_id' => 'purchase_failed_1',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.payment_failure',
            'id' => 'purchase_failed_1',
        ])->assertStatus(200);

        $this->assertEquals('failed', $transaction->fresh()->status);
    }

    #[Test]
    public function payment_refunded_marks_transaction_refunded(): void
    {
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_refunded',
            'chip_id' => 'purchase_refunded_1',
            'total' => 1000,
            'status' => 'success',
            'currency' => 'MYR',
        ]);

        $this->postJson('/chip/webhook', [
            'event_type' => 'payment.refunded',
            'id' => 'purchase_refunded_1',
        ])->assertStatus(200);

        $this->assertEquals('refunded', $transaction->fresh()->status);
    }

    #[Test]
    public function purchase_failed_with_invalid_recurring_token_deletes_payment_method(): void
    {
        PaymentMethod::create([
            'billable_type' => $this->user->getMorphClass(),
            'billable_id' => $this->user->getKey(),
            'chip_token_id' => 'invalid_token_xyz',
            'card_brand' => 'visa',
        ]);

        $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.failed',
            'id' => 'purchase_with_invalid_token',
            'recurring_token' => 'invalid_token_xyz',
            '__all__' => [
                ['code' => 'invalid_recurring_token', 'message' => 'token expired'],
            ],
        ])->assertStatus(200);

        $this->assertDatabaseMissing('payment_methods', ['chip_token_id' => 'invalid_token_xyz']);
    }

    #[Test]
    public function purchase_preauthorized_stores_token_and_marks_preauthorized(): void
    {
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_preauth',
            'chip_id' => 'purchase_preauth',
            'total' => 0,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.preauthorized',
            'id' => 'purchase_preauth',
            'is_recurring_token' => true,
            'client' => ['email' => $this->user->email],
            'transaction_data' => [
                'extra' => [
                    'card_brand' => 'mastercard',
                    'masked_pan' => '••••••••5555',
                    'expiry_month' => '11',
                    'expiry_year' => '27',
                    'cardholder_name' => 'JANE DOE',
                ],
            ],
        ])->assertStatus(200);

        $this->assertEquals('preauthorized', $transaction->fresh()->status);
        $this->assertDatabaseHas('payment_methods', [
            'chip_token_id' => 'purchase_preauth',
            'card_brand' => 'mastercard',
        ]);
    }

    #[Test]
    public function purchase_hold_marks_transaction_on_hold(): void
    {
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_hold',
            'chip_id' => 'purchase_hold_1',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.hold',
            'id' => 'purchase_hold_1',
        ])->assertStatus(200);

        $this->assertEquals('on_hold', $transaction->fresh()->status);
    }

    #[Test]
    public function purchase_pending_charge_marks_transaction_pending_charge(): void
    {
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_pending_charge',
            'chip_id' => 'purchase_pending_charge',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.pending_charge',
            'id' => 'purchase_pending_charge',
        ])->assertStatus(200);

        $this->assertEquals('pending_charge', $transaction->fresh()->status);
    }

    #[Test]
    public function subscription_cancelled_sets_ends_at(): void
    {
        $subscription = Subscription::create([
            'user_id' => $this->user->id,
            'name' => 'default',
            'chip_id' => 'sub_cancel_test',
            'chip_status' => 'active',
            'quantity' => 1,
        ]);

        $this->postJson('/chip/webhook', [
            'event_type' => 'subscription.cancelled',
            'subscription' => [
                'id' => 'sub_cancel_test',
                'cancelled_at' => now()->toIso8601String(),
            ],
        ])->assertStatus(200);

        $fresh = $subscription->fresh();
        $this->assertEquals('cancelled', $fresh->chip_status);
        $this->assertNotNull($fresh->ends_at);
    }

    #[Test]
    public function subscription_expired_marks_subscription_expired(): void
    {
        $subscription = Subscription::create([
            'user_id' => $this->user->id,
            'name' => 'default',
            'chip_id' => 'sub_expire_test',
            'chip_status' => 'active',
            'quantity' => 1,
        ]);

        $this->postJson('/chip/webhook', [
            'event_type' => 'subscription.expired',
            'subscription' => ['id' => 'sub_expire_test'],
        ])->assertStatus(200);

        $fresh = $subscription->fresh();
        $this->assertEquals('expired', $fresh->chip_status);
        $this->assertNotNull($fresh->ends_at);
    }

    // ---------------------------------------------------------------------
    // #1 Terminal-state protection: a stale/duplicate callback must never
    //    downgrade an already-successful payment.
    // ---------------------------------------------------------------------

    #[Test]
    public function stale_failure_callback_does_not_overwrite_successful_transaction(): void
    {
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_terminal',
            'chip_id' => 'purchase_terminal_1',
            'total' => 1000,
            'status' => 'success',
            'currency' => 'MYR',
        ]);

        $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.payment_failure',
            'id' => 'purchase_terminal_1',
        ])->assertStatus(200);

        $this->assertEquals('success', $transaction->fresh()->status);
    }

    #[Test]
    public function stale_hold_callback_does_not_overwrite_successful_transaction(): void
    {
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_terminal_hold',
            'chip_id' => 'purchase_terminal_hold',
            'total' => 1000,
            'status' => 'success',
            'currency' => 'MYR',
        ]);

        $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.hold',
            'id' => 'purchase_terminal_hold',
        ])->assertStatus(200);

        $this->assertEquals('success', $transaction->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // #2 Authoritative re-query: the status fetched from Chip wins over the
    //    (replayable) callback body.
    // ---------------------------------------------------------------------

    #[Test]
    public function requery_overrides_spoofed_paid_event_with_authoritative_status(): void
    {
        config()->set('cashier.webhook.requery', true);

        \Illuminate\Support\Facades\Http::fake([
            '*/purchases/*' => \Illuminate\Support\Facades\Http::response([
                'id' => 'purchase_spoof_1',
                'status' => 'error', // Chip says it actually failed
            ], 200),
        ]);

        $transaction = $this->user->transactions()->create([
            'id' => 'txn_spoof',
            'chip_id' => 'purchase_spoof_1',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        // Attacker/stale envelope claims the purchase is paid.
        $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.paid',
            'id' => 'purchase_spoof_1',
        ])->assertStatus(200);

        // Authoritative status wins: marked failed, NOT success.
        $this->assertEquals('failed', $transaction->fresh()->status);
    }

    #[Test]
    public function requery_confirms_paid_status_and_marks_success(): void
    {
        config()->set('cashier.webhook.requery', true);

        \Illuminate\Support\Facades\Http::fake([
            '*/purchases/*' => \Illuminate\Support\Facades\Http::response([
                'id' => 'purchase_confirm_1',
                'status' => 'paid',
            ], 200),
        ]);

        $transaction = $this->user->transactions()->create([
            'id' => 'txn_confirm',
            'chip_id' => 'purchase_confirm_1',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        // success_callback body carries only a status, no event_type.
        $this->postJson('/chip/webhook', [
            'id' => 'purchase_confirm_1',
            'status' => 'paid',
        ])->assertStatus(200);

        $this->assertEquals('success', $transaction->fresh()->status);
        $this->assertNotNull($transaction->fresh()->processed_at);
    }

    #[Test]
    public function requery_falls_back_to_payload_when_api_returns_no_status(): void
    {
        config()->set('cashier.webhook.requery', true);

        // API reachable but returns no usable status -> fall back to the payload.
        \Illuminate\Support\Facades\Http::fake([
            '*/purchases/*' => \Illuminate\Support\Facades\Http::response(['id' => 'purchase_fallback_1'], 200),
        ]);

        $transaction = $this->user->transactions()->create([
            'id' => 'txn_fallback',
            'chip_id' => 'purchase_fallback_1',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.paid',
            'id' => 'purchase_fallback_1',
        ])->assertStatus(200);

        $this->assertEquals('success', $transaction->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // #3 Idempotency lock: a delivery that cannot acquire the per-purchase
    //    lock (because a concurrent delivery holds it) is acknowledged
    //    without double-processing.
    // ---------------------------------------------------------------------

    #[Test]
    public function webhook_skips_processing_when_purchase_lock_is_held(): void
    {
        // Don't wait around for the lock in the test.
        config()->set('cashier.webhook.lock_wait', 0);

        $transaction = $this->user->transactions()->create([
            'id' => 'txn_locked',
            'chip_id' => 'purchase_locked_1',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        // Simulate a concurrent delivery already holding the lock.
        $lock = \Illuminate\Support\Facades\Cache::lock('chip_webhook_purchase_locked_1', 15);
        $this->assertTrue($lock->get());

        try {
            $this->postJson('/chip/webhook', [
                'event_type' => 'purchase.paid',
                'id' => 'purchase_locked_1',
            ])->assertStatus(200);

            // Lock contention -> handler skipped, transaction left untouched.
            $this->assertEquals('pending', $transaction->fresh()->status);
        } finally {
            $lock->release();
        }
    }

    // ---------------------------------------------------------------------
    // #5 Test-mode visibility: is_test is recorded on the transaction.
    // ---------------------------------------------------------------------

    #[Test]
    public function paid_webhook_records_is_test_flag_in_metadata(): void
    {
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_test_flag',
            'chip_id' => 'purchase_test_flag',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
            'metadata' => ['order_id' => 42],
        ]);

        $this->postJson('/chip/webhook', [
            'event_type' => 'purchase.paid',
            'id' => 'purchase_test_flag',
            'is_test' => true,
        ])->assertStatus(200);

        $fresh = $transaction->fresh();
        $this->assertEquals('success', $fresh->status);
        $this->assertTrue($fresh->metadata['is_test']);
        // Existing metadata is preserved.
        $this->assertEquals(42, $fresh->metadata['order_id']);
    }

    // ---------------------------------------------------------------------
    // #4 Public key normalization: a key with literal "\n" escapes still
    //    verifies signatures (instead of silently 403-ing every webhook).
    // ---------------------------------------------------------------------

    #[Test]
    public function webhook_verifies_signature_with_escaped_newline_public_key(): void
    {
        // Generate an RSA keypair and sign a payload, mimicking Chip.
        $keypair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $details = openssl_pkey_get_details($keypair);
        $publicKeyPem = $details['key'];

        // Configure the public key with literal "\n" escapes, as if pasted into .env.
        config()->set('cashier.webhook.public_key', str_replace("\n", '\n', $publicKeyPem));

        $body = json_encode(['event_type' => 'purchase.paid', 'id' => 'purchase_signed_1']);
        openssl_sign($body, $signature, $keypair, OPENSSL_ALGO_SHA256);

        $transaction = $this->user->transactions()->create([
            'id' => 'txn_signed',
            'chip_id' => 'purchase_signed_1',
            'total' => 1000,
            'status' => 'pending',
            'currency' => 'MYR',
        ]);

        $response = $this->call(
            'POST',
            '/chip/webhook',
            [],
            [],
            [],
            ['HTTP_X_SIGNATURE' => base64_encode($signature), 'CONTENT_TYPE' => 'application/json'],
            $body
        );

        $response->assertStatus(200);
        $this->assertEquals('success', $transaction->fresh()->status);
    }
}
