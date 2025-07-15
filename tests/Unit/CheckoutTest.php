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
} 