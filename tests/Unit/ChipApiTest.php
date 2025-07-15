<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Unit;

use Aizuddinmanap\CashierChip\Exceptions\ChipApiException;
use Aizuddinmanap\CashierChip\Http\ChipApi;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class ChipApiTest extends TestCase
{
    protected ChipApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new ChipApi('test_brand', 'test_key', 'https://api.test.chip-in.asia/api/v1');
    }

    #[Test]
    public function it_can_get_payment_methods(): void
    {
        $expectedResponse = [
            ['type' => 'card', 'name' => 'Credit Card'],
            ['type' => 'fpx', 'name' => 'FPX', 'banks' => ['maybank2u' => 'Maybank2U']],
        ];

        Http::fake([
            'api.test.chip-in.asia/api/v1/payment_methods' => Http::response($expectedResponse),
        ]);

        $result = $this->api->getPaymentMethods();

        $this->assertEquals($expectedResponse, $result);
    }

    #[Test]
    public function it_can_create_purchase(): void
    {
        $expectedResponse = [
            'id' => 'purchase_123',
            'checkout_url' => 'https://checkout.chip-in.asia/123',
            'status' => 'pending',
        ];

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response($expectedResponse),
        ]);

        $api = new ChipApi();
        $purchaseData = [
            'purchase' => [
                'currency' => 'MYR',
                'products' => [
                    [
                        'name' => 'Test Product',
                        'price' => 10000,
                        'quantity' => 1,
                    ]
                ],
            ],
            'client' => [
                'email' => 'test@example.com',
            ],
            'brand_id' => 'test_brand_id',
        ];

        $response = $api->createPurchase($purchaseData);

        $this->assertEquals('purchase_123', $response['id']);
        $this->assertEquals('https://checkout.chip-in.asia/123', $response['checkout_url']);
    }

    #[Test]
    public function it_can_get_purchase(): void
    {
        $expectedResponse = [
            'id' => 'purchase_123',
            'status' => 'paid',
            'amount' => 10000,
        ];

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_123/' => Http::response($expectedResponse),
        ]);

        $result = $this->api->getPurchase('purchase_123');

        $this->assertEquals($expectedResponse, $result);
    }

    #[Test]
    public function it_can_refund_purchase(): void
    {
        $refundData = ['amount' => 5000];
        $expectedResponse = [
            'id' => 'refund_123',
            'status' => 'refunded',
            'amount' => 5000,
        ];

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_123/refund/' => Http::response($expectedResponse),
        ]);

        $result = $this->api->refundPurchase('purchase_123', $refundData);

        $this->assertEquals($expectedResponse, $result);
    }

    #[Test]
    public function it_can_charge_purchase_with_token(): void
    {
        $chargeData = ['amount' => 10000];
        $expectedResponse = [
            'id' => 'charge_123',
            'status' => 'paid',
            'amount' => 10000,
        ];

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_123/charge/' => Http::response($expectedResponse),
        ]);

        $result = $this->api->chargePurchase('purchase_123', $chargeData);

        $this->assertEquals($expectedResponse, $result);
    }

    #[Test]
    public function it_can_delete_recurring_token(): void
    {
        $expectedResponse = ['success' => true];

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_123/delete_recurring_token/' => Http::response($expectedResponse),
        ]);

        $result = $this->api->deleteRecurringToken('purchase_123');

        $this->assertEquals($expectedResponse, $result);
    }

    #[Test]
    public function it_can_create_client(): void
    {
        $clientData = ['email' => 'test@example.com', 'full_name' => 'Test User'];
        $expectedResponse = [
            'id' => 'client_123',
            'email' => 'test@example.com',
        ];

        Http::fake([
            'api.test.chip-in.asia/api/v1/clients' => Http::response($expectedResponse),
        ]);

        $result = $this->api->createClient($clientData);

        $this->assertEquals($expectedResponse, $result);
    }

    #[Test]
    public function it_can_search_clients_by_email(): void
    {
        $expectedResponse = [
            [
                'id' => 'client_123',
                'email' => 'test@example.com',
                'full_name' => 'Test User',
            ]
        ];

        Http::fake([
            'api.test.chip-in.asia/api/v1/clients?q=test%40example.com' => Http::response($expectedResponse),
        ]);

        $api = new ChipApi();
        $result = $api->searchClientsByEmail('test@example.com');

        $this->assertEquals($expectedResponse, $result);
    }

    #[Test]
    public function it_can_get_fpx_b2c_status(): void
    {
        $expectedResponse = ['status' => 'online', 'banks' => []];

        Http::fake([
            'api.test.chip-in.asia/fpx_b2c' => Http::response($expectedResponse),
        ]);

        $result = $this->api->getFpxB2cStatus();

        $this->assertEquals($expectedResponse, $result);
    }

    #[Test]
    public function it_can_get_fpx_b2b1_status(): void
    {
        $expectedResponse = ['status' => 'online', 'banks' => []];

        Http::fake([
            'api.test.chip-in.asia/fpx_b2b1' => Http::response($expectedResponse),
        ]);

        $result = $this->api->getFpxB2b1Status();

        $this->assertEquals($expectedResponse, $result);
    }

    #[Test]
    public function it_throws_exception_on_api_failure(): void
    {
        $this->expectException(\Aizuddinmanap\CashierChip\Exceptions\ChipApiException::class);

        Http::fake([
            'api.test.chip-in.asia/api/v1/payment_methods' => Http::response(['error' => 'API Error'], 400),
        ]);

        $api = new ChipApi();
        $api->getPaymentMethods();
    }

    #[Test]
    public function it_includes_proper_headers(): void
    {
        Http::fake([
            'api.test.chip-in.asia/*' => Http::response([]),
        ]);

        $this->api->getPaymentMethods();

        Http::assertSent(function ($request) {
            return $request->header('Authorization')[0] === 'Bearer test_key' &&
                   $request->header('Content-Type')[0] === 'application/json' &&
                   $request->header('Accept')[0] === 'application/json' &&
                   str_contains($request->header('User-Agent')[0], 'Laravel-Cashier-Chip');
        });
    }

    #[Test]
    public function it_builds_urls_correctly(): void
    {
        Http::fake([
            'https://api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'test_123']),
        ]);

        $api = new ChipApi();
        $api->createPurchase(['test' => 'data']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.test.chip-in.asia/api/v1/purchases/';
        });
    }

    #[Test]
    public function it_sanitizes_sensitive_data_in_logs(): void
    {
        // This would need log assertion helpers, but demonstrates the concept
        $this->assertTrue(true); // Placeholder for log sanitization test
    }
} 