<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class BillableTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser();
    }

    /** @test */
    public function it_can_create_chip_customer(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/clients' => Http::response([
                'id' => 'client_123',
                'email' => 'test@example.com',
                'full_name' => 'Test User',
            ]),
        ]);

        $customer = $this->user->createAsChipCustomer();

        $this->assertEquals('client_123', $customer->chipId());
        $this->assertEquals('client_123', $this->user->fresh()->chip_id);
    }

    /** @test */
    public function it_can_update_chip_customer(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        Http::fake([
            'api.test.chip-in.asia/api/v1/clients/client_123' => Http::response([
                'id' => 'client_123',
                'email' => 'test@example.com',
                'full_name' => 'Updated Name',
            ]),
        ]);

        $customer = $this->user->updateChipCustomer(['name' => 'Updated Name']);

        $this->assertEquals('Updated Name', $customer->name());
    }

    /** @test */
    public function it_can_check_if_user_has_chip_id(): void
    {
        $this->assertFalse($this->user->hasChipId());

        $this->user->update(['chip_id' => 'client_123']);
        
        $this->assertTrue($this->user->hasChipId());
        $this->assertEquals('client_123', $this->user->chipId());
    }

    /** @test */
    public function it_can_create_subscription(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        Http::fake([
            'api.test.chip-in.asia/api/v1/subscriptions' => Http::response([
                'id' => 'sub_123',
                'status' => 'active',
            ]),
        ]);

        $subscription = $this->user->newSubscription('default', 'price_monthly')
            ->create();

        $this->assertEquals('default', $subscription->name);
        $this->assertEquals('price_monthly', $subscription->chip_price_id);
        $this->assertTrue($subscription->active());
    }

    /** @test */
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

    /** @test */
    public function it_can_create_paid_subscription_without_trial(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        Http::fake([
            'api.test.chip-in.asia/api/v1/subscriptions' => Http::response([
                'id' => 'sub_123',
                'status' => 'active',
            ]),
        ]);

        $subscription = $this->user->newSubscription('premium', 'price_yearly')
            ->skipTrial()
            ->create();

        $this->assertFalse($subscription->onTrial());
        $this->assertNull($subscription->trial_ends_at);
        $this->assertEquals('active', $subscription->chip_status);
        $this->assertEquals('sub_123', $subscription->chip_id);
    }

    /** @test */
    public function it_differentiates_between_trial_and_paid_subscriptions(): void
    {
        // Create trial subscription (local-only)
        $trialSubscription = $this->user->newSubscription('trial', 'price_monthly')
            ->trialDays(7)
            ->create();

        $this->assertTrue($trialSubscription->onTrial());
        $this->assertEquals('trialing', $trialSubscription->chip_status);
        $this->assertStringStartsWith('trial_', $trialSubscription->chip_id);

        // Create paid subscription (API call)
        $this->user->update(['chip_id' => 'client_123']);

        Http::fake([
            'api.test.chip-in.asia/api/v1/subscriptions' => Http::response([
                'id' => 'sub_paid_123',
                'status' => 'active',
            ]),
        ]);

        $paidSubscription = $this->user->newSubscription('paid', 'price_yearly')
            ->skipTrial()
            ->create();

        $this->assertFalse($paidSubscription->onTrial());
        $this->assertEquals('active', $paidSubscription->chip_status);
        $this->assertEquals('sub_paid_123', $paidSubscription->chip_id);
    }

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
    public function it_can_charge_with_token(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_123/charge/' => Http::response([
                'id' => 'charge_123',
                'amount' => 10000,
                'currency' => 'MYR',
                'status' => 'paid',
            ]),
        ]);

        $payment = $this->user->chargeWithToken('purchase_123', ['amount' => 10000]);

        $this->assertEquals(10000, $payment->rawAmount());
        $this->assertEquals('paid', $payment->status);
        
        // Check that it was charged with token via metadata
        $metadata = $payment->metadata();
        $this->assertTrue($metadata['charged_with_token'] ?? false);
    }

    /** @test */
    public function it_can_get_available_payment_methods(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/payment_methods' => Http::response([
                ['type' => 'card', 'name' => 'Credit Card'],
                ['type' => 'fpx', 'name' => 'FPX'],
            ]),
        ]);

        $methods = $this->user->getAvailablePaymentMethods();

        $this->assertCount(2, $methods);
        $this->assertEquals('card', $methods[0]['type']);
        $this->assertEquals('fpx', $methods[1]['type']);
    }

    /** @test */
    public function it_can_get_fpx_banks(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/payment_methods' => Http::response([
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

    /** @test */
    public function it_can_check_fpx_support(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/payment_methods' => Http::response([
                ['type' => 'fpx', 'name' => 'FPX'],
            ]),
        ]);

        $this->assertTrue($this->user->supportsFPX());
    }

    /** @test */
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

    /** @test */
    public function it_can_check_payment_method_availability(): void
    {
        Http::fake([
            'api.test.chip-in.asia/fpx_b2c' => Http::response(['status' => 'online']),
            'api.test.chip-in.asia/fpx_b2b1' => Http::response(['status' => 'online']),
            'api.test.chip-in.asia/api/v1/payment_methods' => Http::response([
                ['type' => 'fpx', 'name' => 'FPX'],
            ]),
        ]);

        $this->assertTrue($this->user->isPaymentMethodAvailable('fpx'));
        $this->assertTrue($this->user->isPaymentMethodAvailable('card'));
        $this->assertFalse($this->user->isPaymentMethodAvailable('unknown'));
    }

    /** @test */
    public function it_can_delete_recurring_token(): void
    {
        $this->user->update(['chip_id' => 'client_123']);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_123/delete_recurring_token/' => Http::response(['success' => true]),
        ]);

        $result = $this->user->deleteRecurringToken('purchase_123');

        $this->assertTrue($result);
    }

    /** @test */
    public function it_can_find_chip_customer_by_email(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/clients?q=test%40example.com' => Http::response([
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

    /** @test */
    public function it_handles_trial_subscription(): void
    {
        // Set up a generic trial on the user
        $this->user->update([
            'trial_ends_at' => now()->addDays(14),
        ]);

        $this->assertTrue($this->user->onTrial());
        $this->assertFalse($this->user->onTrial('non-existent-subscription'));
    }

    /** @test */
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
} 