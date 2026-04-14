<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Unit;

use Aizuddinmanap\CashierChip\Checkout;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Http;

class CheckoutTest extends TestCase
{
    #[Test]
    public function customer_and_client_methods_are_mutually_exclusive(): void
    {
        $checkout = Checkout::forPayment(10000);
        
        // First, set client details
        $checkout->client('test@example.com', 'John Doe');
        
        $data = $checkout->getData();
        $this->assertEquals('test@example.com', $data['client_email']);
        $this->assertEquals('John Doe', $data['client_name']);
        $this->assertArrayNotHasKey('customer_id', $data);
        
        // Now set customer ID - this should clear client details
        $checkout->customer('customer_123');
        
        $data = $checkout->getData();
        $this->assertEquals('customer_123', $data['customer_id']);
        $this->assertArrayNotHasKey('client_email', $data);
        $this->assertArrayNotHasKey('client_name', $data);
        
        // Set client details again - this should clear customer ID
        $checkout->client('new@example.com', 'Jane Smith');
        
        $data = $checkout->getData();
        $this->assertEquals('new@example.com', $data['client_email']);
        $this->assertEquals('Jane Smith', $data['client_name']);
        $this->assertArrayNotHasKey('customer_id', $data);
    }
    
    #[Test]
    public function build_purchase_data_uses_customer_id_when_available(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase_123',
                'checkout_url' => 'https://checkout.chip-in.asia/123',
            ]),
        ]);
        
        $checkout = Checkout::forPayment(10000)
            ->customer('customer_123');
        
        $response = $checkout->create();
        
        // Check the HTTP request was made with customer ID
        Http::assertSent(function ($request) {
            $data = $request->data();
            return isset($data['client']['id']) && 
                   $data['client']['id'] === 'customer_123' &&
                   !isset($data['client']['email']) &&
                   !isset($data['client']['full_name']);
        });
    }
    
    #[Test]
    public function build_purchase_data_uses_client_details_when_no_customer_id(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase_123',
                'checkout_url' => 'https://checkout.chip-in.asia/123',
            ]),
        ]);

        $checkout = Checkout::forPayment(10000)
            ->client('test@example.com', 'John Doe');

        $response = $checkout->create();

        // Check the HTTP request was made with client details
        Http::assertSent(function ($request) {
            $data = $request->data();
            return isset($data['client']['email']) &&
                   $data['client']['email'] === 'test@example.com' &&
                   isset($data['client']['full_name']) &&
                   $data['client']['full_name'] === 'John Doe' &&
                   !isset($data['client']['id']);
        });
    }

    #[Test]
    public function for_subscription_auto_sets_force_recurring_and_whitelist(): void
    {
        $checkout = Checkout::forSubscription('price_monthly', 1);

        $data = $checkout->getData();
        $this->assertTrue($data['force_recurring']);
        $this->assertEquals(['visa', 'mastercard', 'maestro'], $data['payment_method_whitelist']);
        $this->assertTrue($data['is_subscription']);
    }

    #[Test]
    public function force_recurring_can_be_toggled_explicitly(): void
    {
        $checkout = Checkout::forPayment(10000)->forceRecurring();

        $data = $checkout->getData();
        $this->assertTrue($data['force_recurring']);
        // Whitelist should auto-fill when force_recurring enabled
        $this->assertEquals(['visa', 'mastercard', 'maestro'], $data['payment_method_whitelist']);
    }

    #[Test]
    public function skip_capture_can_be_set_for_rm0_authorization(): void
    {
        $checkout = Checkout::forPayment(0)->skipCapture();

        $data = $checkout->getData();
        $this->assertTrue($data['skip_capture']);
    }

    #[Test]
    public function build_purchase_data_includes_recurring_params(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase_123',
                'checkout_url' => 'https://checkout.chip-in.asia/123',
            ]),
        ]);

        Checkout::forSubscription('price_monthly')
            ->client('test@example.com', 'John Doe')
            ->totalOverride(2999)
            ->platform('laravel-test')
            ->reference('user-42')
            ->create();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['force_recurring'] ?? false) === true
                && ($data['payment_method_whitelist'] ?? []) === ['visa', 'mastercard', 'maestro']
                && ($data['platform'] ?? null) === 'laravel-test'
                && ($data['reference'] ?? null) === 'user-42'
                && ($data['purchase']['total_override'] ?? null) === 2999
                && isset($data['creator_agent']);
        });
    }

    #[Test]
    public function recurring_whitelist_filters_to_card_brands_only(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase_123',
                'checkout_url' => 'https://checkout.chip-in.asia/123',
            ]),
        ]);

        // User passes a mixed whitelist including non-card methods
        Checkout::forPayment(10000)
            ->forceRecurring()
            ->paymentMethodWhitelist(['fpx', 'visa', 'tng', 'mastercard'])
            ->create();

        // Only card brands should remain after filtering
        Http::assertSent(function ($request) {
            $data = $request->data();
            $whitelist = $data['payment_method_whitelist'] ?? [];

            return in_array('visa', $whitelist)
                && in_array('mastercard', $whitelist)
                && ! in_array('fpx', $whitelist)
                && ! in_array('tng', $whitelist);
        });
    }

    #[Test]
    public function zero_amount_subscription_auto_skips_capture(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase_123',
                'checkout_url' => 'https://checkout.chip-in.asia/123',
            ]),
        ]);

        Checkout::forSubscription('price_monthly')
            ->totalOverride(0)
            ->client('test@example.com')
            ->create();

        Http::assertSent(function ($request) {
            $data = $request->data();
            return ($data['skip_capture'] ?? false) === true
                && ($data['force_recurring'] ?? false) === true;
        });
    }
} 