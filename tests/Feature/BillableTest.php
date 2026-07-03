<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Tests\TestCase;
use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Aizuddinmanap\CashierChip\FPX;
use Aizuddinmanap\CashierChip\Http\ChipApi;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Http;

class BillableTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser();
    }

    #[Test]
    public function it_can_create_chip_customer(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/clients/' => Http::response([
                'id' => 'client_123',
                'email' => 'test@example.com',
                'full_name' => 'Test User',
            ]),
        ]);

        $customer = $this->user->createAsChipCustomer();

        $this->assertEquals('client_123', $customer->chipId());
        $this->assertEquals('client_123', $this->user->fresh()->chip_id);
    }

    #[Test]
    public function failed_customer_creation_does_not_persist_a_placeholder_id(): void
    {
        // A failed createClient() must NOT save a local placeholder chip_id —
        // otherwise hasChipId() returns true, the client is never re-created, and
        // Chip rejects every future add_subscriber / charge. chip_id must stay
        // null so the next call retries.
        $this->mockChipApiError(401, 'Unauthorized');

        try {
            $this->user->createAsChipCustomer();
            $this->fail('Expected an exception when Chip client creation fails.');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertFalse($this->user->fresh()->hasChipId());
    }

    #[Test]
    public function it_reuses_an_existing_chip_client_on_duplicate_email(): void
    {
        // Chip rejects a duplicate email (clients_unique_email); createAsChipCustomer
        // must look the existing client up and reuse it instead of failing.
        Http::fake([
            'api.test.chip-in.asia/api/v1/clients/?q=*' => Http::response([
                'results' => [
                    ['id' => 'client_existing', 'email' => 'test@example.com'],
                ],
            ]),
            'api.test.chip-in.asia/api/v1/clients/' => Http::response([
                'email' => ['clients_unique_email'],
            ], 400),
        ]);

        $customer = $this->user->createAsChipCustomer();

        $this->assertEquals('client_existing', $customer->chipId());
        $this->assertEquals('client_existing', $this->user->fresh()->chip_id);
    }

    #[Test]
    public function it_can_update_chip_customer(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        Http::fake([
            'api.test.chip-in.asia/api/v1/clients/client_123/' => Http::response([
                'id' => 'client_123',
                'email' => 'test@example.com',
                'full_name' => 'Updated Name',
            ]),
        ]);

        $customer = $this->user->updateChipCustomer(['name' => 'Updated Name']);

        $this->assertEquals('Updated Name', $customer->name());
    }

    #[Test]
    public function it_can_check_if_user_has_chip_id(): void
    {
        $this->assertFalse($this->user->hasChipId());

        $this->user->update(['chip_id' => 'client_123']);
        
        $this->assertTrue($this->user->hasChipId());
        $this->assertEquals('client_123', $this->user->chipId());
    }

    #[Test]
    public function it_can_create_subscription(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        // Token-based: pass a saved payment token; the subscription record is
        // local (Chip has no /subscriptions/ resource to create remotely).
        $subscription = $this->user->newSubscription('default', 'price_monthly')
            ->create('tok_123');

        $this->assertEquals('default', $subscription->name);
        $this->assertEquals('price_monthly', $subscription->chip_price_id);
        $this->assertTrue($subscription->active());
    }

    #[Test]
    public function creating_a_paid_subscription_without_a_token_directs_to_checkout(): void
    {
        // No card and not a trial → Chip can't tokenize; must use checkout().
        $this->expectException(\LogicException::class);

        $this->user->newSubscription('default', 'price_monthly')->create();
    }

    #[Test]
    public function it_can_create_subscription_with_trial(): void
    {
        // Trial subscriptions are local-only and don't require API calls
        $subscription = $this->user->newSubscription('premium', 'price_yearly')
            ->trialDays(14)
            ->create();

        $this->assertTrue($subscription->onTrial());
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertEquals('trialing', $subscription->chip_status);
        $this->assertStringStartsWith('trial_', $subscription->chip_id);
    }

    #[Test]
    public function it_can_create_paid_subscription_without_trial(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        $subscription = $this->user->newSubscription('premium', 'price_yearly')
            ->skipTrial()
            ->create('tok_123');

        $this->assertFalse($subscription->onTrial());
        $this->assertNull($subscription->trial_ends_at);
        $this->assertEquals('active', $subscription->chip_status);
        $this->assertStringStartsWith('sub_', $subscription->chip_id);
    }

    #[Test]
    public function it_differentiates_between_trial_and_paid_subscriptions(): void
    {
        // Create trial subscription (local-only)
        $trialSubscription = $this->user->newSubscription('trial', 'price_monthly')
            ->trialDays(7)
            ->create();

        $this->assertTrue($trialSubscription->onTrial());
        $this->assertEquals('trialing', $trialSubscription->chip_status);
        $this->assertStringStartsWith('trial_', $trialSubscription->chip_id);

        // Create paid subscription (token-based, local record)
        $this->user->update(['chip_id' => 'client_123']);

        $paidSubscription = $this->user->newSubscription('paid', 'price_yearly')
            ->skipTrial()
            ->create('tok_123');

        $this->assertFalse($paidSubscription->onTrial());
        $this->assertEquals('active', $paidSubscription->chip_status);
        $this->assertStringStartsWith('sub_', $paidSubscription->chip_id);
    }

    #[Test]
    public function it_can_check_subscription_status(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        // Create active subscription
        $subscription = $this->user->subscriptions()->create([
            'name' => 'default',
            'chip_id' => 'sub_123',
            'chip_price_id' => 'price_monthly',
            'chip_status' => 'active',
            'quantity' => 1,
        ]);

        $this->assertTrue($this->user->subscribed('default'));
        $this->assertTrue($this->user->subscription('default')->active());
    }

    #[Test]
    public function it_can_perform_charges(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase_123',
                'checkout_url' => 'https://checkout.chip-in.asia/123',
                'amount' => 10000,
                'currency' => 'MYR',
            ]),
        ]);

        $payment = $this->user->charge(10000);

        $this->assertEquals(10000, $payment->rawAmount());
        $this->assertEquals('myr', $payment->currency);
    }

    #[Test]
    public function it_can_refund_payments(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        // Create a payment record
        $payment = $this->user->transactions()->create([
            'id' => 'pay_123',
            'chip_id' => 'purchase_123',
            'total' => 10000,
            'currency' => 'MYR',
            'status' => 'paid',
        ]);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_123/refund/' => Http::response([
                'id' => 'refund_123',
                'amount' => 5000,
                'currency' => 'MYR',
                'status' => 'refunded',
            ]),
        ]);

        $refund = $this->user->refund('pay_123', 5000);

        $this->assertEquals(5000, $refund->rawAmount());
        $this->assertEquals('refunded', $refund->status);
    }

    #[Test]
    public function it_can_charge_with_token(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        // New flow: create a purchase, then charge it with the recurring token
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase_new_123',
                'status' => 'pending',
            ]),
            'api.test.chip-in.asia/api/v1/purchases/purchase_new_123/charge/' => Http::response([
                'id' => 'charge_123',
                'amount' => 10000,
                'currency' => 'MYR',
                'status' => 'paid',
            ]),
        ]);

        // Pass the recurring token literally via the payment_method option
        $payment = $this->user->chargeWithToken(
            10000,
            'Renewal',
            ['payment_method' => 'token_purchase_123']
        );

        $this->assertEquals(10000, $payment->rawAmount());
        $this->assertEquals('success', $payment->status);

        // Check that it was charged with token via metadata
        $metadata = $payment->metadata();
        $this->assertTrue($metadata['charged_with_token'] ?? false);
        $this->assertEquals('token_purchase_123', $metadata['recurring_token'] ?? null);
    }

    #[Test]
    public function it_can_get_available_payment_methods(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/payment_methods*' => Http::response([
                ['type' => 'card', 'name' => 'Credit Card'],
                ['type' => 'fpx', 'name' => 'FPX'],
            ]),
        ]);

        $methods = $this->user->getAvailablePaymentMethods();

        $this->assertCount(2, $methods);
        $this->assertEquals('card', $methods[0]['type']);
        $this->assertEquals('fpx', $methods[1]['type']);
    }

    #[Test]
    public function it_can_get_fpx_banks(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/payment_methods*' => Http::response([
                [
                    'type' => 'fpx',
                    'banks' => [
                        'maybank2u' => 'Maybank2U',
                        'cimb' => 'CIMB Clicks',
                    ],
                ],
            ]),
        ]);

        $banks = $this->user->getFPXBanks();

        $this->assertArrayHasKey('maybank2u', $banks);
        $this->assertEquals('Maybank2U', $banks['maybank2u']);
    }

    #[Test]
    public function it_can_check_fpx_support(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/payment_methods*' => Http::response([
                ['type' => 'fpx', 'name' => 'FPX'],
            ]),
        ]);

        $this->assertTrue($this->user->supportsFPX());
    }

    #[Test]
    public function it_can_get_fpx_banks_with_status(): void
    {
        Http::fake([
            'api.test.chip-in.asia/fpx_b2c' => Http::response(['status' => 'online']),
            'api.test.chip-in.asia/fpx_b2b1' => Http::response(['status' => 'online']),
        ]);

        $banksWithStatus = $this->user->getFPXBanksWithStatus();

        $this->assertIsArray($banksWithStatus);
        $this->assertArrayHasKey('maybank2u', $banksWithStatus);
        
        $maybankStatus = $banksWithStatus['maybank2u'];
        $this->assertTrue($maybankStatus['b2c_available']);
        $this->assertTrue($maybankStatus['b2b1_available']);
    }

    #[Test]
    public function it_can_check_payment_method_availability(): void
    {
        Http::fake([
            'api.test.chip-in.asia/fpx_b2c' => Http::response(['status' => 'online']),
            'api.test.chip-in.asia/fpx_b2b1' => Http::response(['status' => 'online']),
            'api.test.chip-in.asia/api/v1/payment_methods*' => Http::response([
                ['type' => 'fpx', 'name' => 'FPX'],
            ]),
        ]);

        $this->assertTrue($this->user->isPaymentMethodAvailable('fpx'));
        $this->assertTrue($this->user->isPaymentMethodAvailable('card'));
        $this->assertFalse($this->user->isPaymentMethodAvailable('unknown'));
    }

    #[Test]
    public function it_can_delete_recurring_token(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_123/delete_recurring_token/' => Http::response(['success' => true]),
        ]);

        $result = $this->user->deleteRecurringToken('purchase_123');

        $this->assertTrue($result);
    }

    #[Test]
    public function it_can_find_chip_customer_by_email(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/clients/?q=test%40example.com' => Http::response([
                [
                    'id' => 'client_123',
                    'email' => 'test@example.com',
                    'full_name' => 'Test User',
                ]
            ]),
        ]);

        $customer = \Aizuddinmanap\CashierChip\Concerns\ManagesPaymentMethods::findChipCustomerByEmail('test@example.com');

        $this->assertEquals('client_123', $customer['id']);
        $this->assertEquals('test@example.com', $customer['email']);
    }

    #[Test]
    public function it_handles_trial_subscription(): void
    {
        // Set up a generic trial on the user
        $this->user->update([
            'trial_ends_at' => now()->addDays(14),
        ]);

        $this->assertTrue($this->user->onTrial());
        $this->assertFalse($this->user->onTrial('non-existent-subscription'));
    }

    #[Test]
    public function it_can_manage_payment_method_info(): void
    {
        $this->user->update([
            'pm_type' => 'card',
            'pm_last_four' => '4242',
        ]);

        $this->assertTrue($this->user->hasDefaultPaymentMethod());
        $this->assertEquals('card', $this->user->pmType());
        $this->assertEquals('4242', $this->user->pmLastFour());
    }

    #[Test]
    public function it_can_create_add_payment_method_intent(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase_intent_123',
                'checkout_url' => 'https://checkout.chip-in.asia/pay/intent_123',
            ]),
        ]);

        $intent = $this->user->addPaymentMethodIntent([
            'success_redirect' => 'https://example.com/success',
        ]);

        $this->assertEquals('purchase_intent_123', $intent['id']);
        $this->assertEquals('https://checkout.chip-in.asia/pay/intent_123', $intent['checkout_url']);

        // Verify the payload sent to Chip is RM0 + force_recurring + skip_capture
        Http::assertSent(function ($request) {
            $data = $request->data();
            return ($data['force_recurring'] ?? false) === true
                && ($data['skip_capture'] ?? false) === true
                && ($data['payment_method_whitelist'] ?? []) === ['visa', 'mastercard', 'maestro']
                && $data['purchase']['products'][0]['price'] === 0
                && $data['purchase']['products'][0]['name'] === 'Add payment method';
        });
    }

    #[Test]
    public function it_stores_payment_method_from_chip_response(): void
    {
        $payment = [
            'id' => 'purchase_card_xyz',
            'is_recurring_token' => true,
            'transaction_data' => [
                'extra' => [
                    'card_brand' => 'visa',
                    'masked_pan' => '••••••••1234',
                    'expiry_month' => '06',
                    'expiry_year' => '28',
                    'cardholder_name' => 'JANE DOE',
                    'card_issuer_country' => 'MY',
                    'card_type' => 'credit',
                ],
            ],
        ];

        $pm = $this->user->storePaymentMethodFromChip($payment);

        $this->assertNotNull($pm);
        $this->assertEquals('purchase_card_xyz', $pm->chip_token_id);
        $this->assertTrue($pm->is_default);

        // User columns should be synced
        $this->assertEquals('visa', $this->user->fresh()->pm_type);
        $this->assertEquals('1234', $this->user->fresh()->pm_last_four);
    }

    #[Test]
    public function store_payment_method_returns_null_when_no_recurring_token(): void
    {
        $payment = [
            'id' => 'purchase_no_token',
            'is_recurring_token' => false,
            'recurring_token' => null,
        ];

        $pm = $this->user->storePaymentMethodFromChip($payment);

        $this->assertNull($pm);
    }

    #[Test]
    public function default_payment_method_returns_the_default_record(): void
    {
        \Aizuddinmanap\CashierChip\PaymentMethod::create([
            'billable_type' => $this->user->getMorphClass(),
            'billable_id' => $this->user->getKey(),
            'chip_token_id' => 'token_a',
            'card_brand' => 'visa',
            'is_default' => false,
        ]);

        \Aizuddinmanap\CashierChip\PaymentMethod::create([
            'billable_type' => $this->user->getMorphClass(),
            'billable_id' => $this->user->getKey(),
            'chip_token_id' => 'token_b',
            'card_brand' => 'mastercard',
            'is_default' => true,
        ]);

        $default = $this->user->defaultPaymentMethod();

        $this->assertNotNull($default);
        $this->assertEquals('token_b', $default->chip_token_id);
        $this->assertEquals('mastercard', $default->card_brand);
    }

    #[Test]
    public function update_default_payment_method_swaps_the_default(): void
    {
        $first = \Aizuddinmanap\CashierChip\PaymentMethod::create([
            'billable_type' => $this->user->getMorphClass(),
            'billable_id' => $this->user->getKey(),
            'chip_token_id' => 'token_a',
            'card_brand' => 'visa',
            'card_last_four' => '4242',
            'is_default' => true,
        ]);

        $second = \Aizuddinmanap\CashierChip\PaymentMethod::create([
            'billable_type' => $this->user->getMorphClass(),
            'billable_id' => $this->user->getKey(),
            'chip_token_id' => 'token_b',
            'card_brand' => 'mastercard',
            'card_last_four' => '5555',
            'is_default' => false,
        ]);

        $this->user->updateDefaultPaymentMethod($second->id);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertEquals('mastercard', $this->user->fresh()->pm_type);
        $this->assertEquals('5555', $this->user->fresh()->pm_last_four);
    }

    #[Test]
    public function remove_payment_method_deletes_local_and_clears_default(): void
    {
        $pm = \Aizuddinmanap\CashierChip\PaymentMethod::create([
            'billable_type' => $this->user->getMorphClass(),
            'billable_id' => $this->user->getKey(),
            'chip_token_id' => 'token_to_remove',
            'card_brand' => 'visa',
            'is_default' => true,
        ]);

        $this->user->update(['pm_type' => 'visa', 'pm_last_four' => '4242']);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/token_to_remove/delete_recurring_token/' =>
                Http::response(['success' => true]),
        ]);

        $result = $this->user->removePaymentMethod($pm->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('payment_methods', ['chip_token_id' => 'token_to_remove']);
        $this->assertNull($this->user->fresh()->pm_type);
    }

    #[Test]
    public function subscription_can_be_renewed_with_saved_token(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        // Create a plan so the subscription has an amount
        \Aizuddinmanap\CashierChip\Models\Plan::create([
            'id' => 'plan_basic',
            'chip_price_id' => 'price_monthly',
            'name' => 'Basic',
            'price' => 29.99,
            'currency' => 'MYR',
            'interval' => 'month',
        ]);

        // Create active subscription
        $subscription = $this->user->subscriptions()->create([
            'name' => 'default',
            'chip_id' => 'sub_123',
            'chip_price_id' => 'price_monthly',
            'chip_status' => 'active',
            'quantity' => 1,
        ]);

        // Create default payment method
        \Aizuddinmanap\CashierChip\PaymentMethod::create([
            'billable_type' => $this->user->getMorphClass(),
            'billable_id' => $this->user->getKey(),
            'chip_token_id' => 'token_renew',
            'card_brand' => 'visa',
            'is_default' => true,
        ]);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase_renewal',
                'status' => 'pending',
            ]),
            'api.test.chip-in.asia/api/v1/purchases/purchase_renewal/charge/' => Http::response([
                'id' => 'purchase_renewal',
                'status' => 'paid',
            ]),
        ]);

        $transaction = $subscription->renew();

        $this->assertEquals('success', $transaction->status);
        $this->assertEquals(2999, $transaction->rawAmount());

        // Verify the renewal used the recurring token
        Http::assertSent(function ($request) {
            $url = $request->url();
            if (! str_contains($url, '/charge/')) {
                return true; // Skip the create-purchase request
            }
            $data = $request->data();
            return ($data['recurring_token'] ?? null) === 'token_renew';
        });
    }

    #[Test]
    public function it_can_get_upcoming_invoice_for_trial_subscription(): void
    {
        // Create a trial subscription
        $subscription = $this->user->newSubscription('premium', 'price_monthly')
            ->trialDays(14)
            ->create();

        // Verify the subscription is created with 'trialing' status
        $this->assertEquals('trialing', $subscription->chip_status);
        $this->assertTrue($subscription->onTrial());

        // Test that upcomingInvoice() recognizes the trial subscription
        $upcomingInvoice = $this->user->upcomingInvoice();
        $this->assertNotNull($upcomingInvoice, 'Trial subscription should generate upcoming invoice');
        
        // Verify the invoice contains trial subscription details
        $this->assertEquals($subscription->name, $upcomingInvoice->subscription);
    }
} 