<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\PaymentMethod;
use Aizuddinmanap\CashierChip\Subscription;
use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Aizuddinmanap\CashierChip\Transaction;
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
}
