<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Unit;

use Aizuddinmanap\CashierChip\Checkout;
use Aizuddinmanap\CashierChip\FPX;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class FPXTest extends TestCase
{
    /** @test */
    public function it_can_create_fpx_payment(): void
    {
        $checkout = FPX::createPayment(10000, 'MYR');

        $this->assertInstanceOf(Checkout::class, $checkout);
        $this->assertEquals(10000, $checkout->amount);
        $this->assertEquals('MYR', $checkout->currency);
    }

    /** @test */
    public function it_returns_supported_banks(): void
    {
        $banks = FPX::getSupportedBanks();

        $this->assertIsArray($banks);
        $this->assertArrayHasKey('maybank2u', $banks);
        $this->assertArrayHasKey('cimb', $banks);
        $this->assertEquals('Maybank2U', $banks['maybank2u']);
    }

    /** @test */
    public function it_can_check_if_bank_is_supported(): void
    {
        $this->assertTrue(FPX::isBankSupported('maybank2u'));
        $this->assertFalse(FPX::isBankSupported('non_existent_bank'));
    }

    /** @test */
    public function it_can_get_bank_name(): void
    {
        $this->assertEquals('Maybank2U', FPX::getBankName('maybank2u'));
        $this->assertNull(FPX::getBankName('non_existent_bank'));
    }

    /** @test */
    public function it_can_create_payment_with_specific_bank(): void
    {
        $checkout = FPX::payWithBank(10000, 'maybank2u', 'MYR');

        $this->assertInstanceOf(Checkout::class, $checkout);
        $this->assertEquals('maybank2u', $checkout->fpxBank);
    }

    /** @test */
    public function it_throws_exception_for_unsupported_bank(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Bank code 'unsupported_bank' is not supported");

        FPX::payWithBank(10000, 'unsupported_bank');
    }

    /** @test */
    public function it_provides_payment_info(): void
    {
        $info = FPX::getPaymentInfo();

        $this->assertIsArray($info);
        $this->assertEquals('FPX (Financial Process Exchange)', $info['name']);
        $this->assertEquals('MYR', $info['currency']);
        $this->assertEquals(100, $info['min_amount']);
        $this->assertEquals(3000000, $info['max_amount']);
        $this->assertTrue($info['real_time']);
        $this->assertArrayHasKey('fees', $info);
    }

    /** @test */
    public function it_validates_payment_amounts(): void
    {
        $this->assertTrue(FPX::validateAmount(10000)); // RM 100
        $this->assertFalse(FPX::validateAmount(50)); // Below minimum
        $this->assertFalse(FPX::validateAmount(5000000)); // Above maximum
    }

    /** @test */
    public function it_formats_amounts_correctly(): void
    {
        $this->assertEquals(10000, FPX::formatAmount(100.00));
        $this->assertEquals(5050, FPX::formatAmount(50.50));
    }

    /** @test */
    public function it_returns_popular_banks(): void
    {
        $popularBanks = FPX::getPopularBanks();

        $this->assertIsArray($popularBanks);
        $this->assertArrayHasKey('maybank2u', $popularBanks);
        $this->assertArrayHasKey('cimb', $popularBanks);
        $this->assertCount(6, $popularBanks);
    }

    /** @test */
    public function it_can_get_fpx_b2c_status(): void
    {
        Http::fake([
            'api.test.chip-in.asia/fpx_b2c' => Http::response(['status' => 'online']),
        ]);

        $status = FPX::getFpxB2cStatus();

        $this->assertEquals(['status' => 'online'], $status);
    }

    /** @test */
    public function it_handles_fpx_b2c_status_failure(): void
    {
        Http::fake([
            'api.test.chip-in.asia/fpx_b2c' => Http::response([], 500),
        ]);

        $status = FPX::getFpxB2cStatus();

        $this->assertEquals([], $status);
    }

    /** @test */
    public function it_can_get_fpx_b2b1_status(): void
    {
        Http::fake([
            'api.test.chip-in.asia/fpx_b2b1' => Http::response(['status' => 'online']),
        ]);

        $status = FPX::getFpxB2b1Status();

        $this->assertEquals(['status' => 'online'], $status);
    }

    /** @test */
    public function it_checks_b2c_availability(): void
    {
        Http::fake([
            'api.test.chip-in.asia/fpx_b2c' => Http::response(['status' => 'online']),
        ]);

        $this->assertTrue(FPX::isB2cAvailable());
    }

    /** @test */
    public function it_defaults_to_available_when_status_unknown(): void
    {
        Http::fake([
            'api.test.chip-in.asia/fpx_b2c' => Http::response([]),
        ]);

        $this->assertTrue(FPX::isB2cAvailable());
    }

    /** @test */
    public function it_checks_b2b1_availability(): void
    {
        Http::fake([
            'api.test.chip-in.asia/fpx_b2b1' => Http::response(['status' => 'online']),
        ]);

        $this->assertTrue(FPX::isB2b1Available());
    }

    /** @test */
    public function it_provides_comprehensive_system_status(): void
    {
        Http::fake([
            'api.test.chip-in.asia/fpx_b2c' => Http::response(['status' => 'online']),
            'api.test.chip-in.asia/fpx_b2b1' => Http::response(['status' => 'online']),
        ]);

        $systemStatus = FPX::getSystemStatus();

        $this->assertIsArray($systemStatus);
        $this->assertArrayHasKey('b2c', $systemStatus);
        $this->assertArrayHasKey('b2b1', $systemStatus);
        $this->assertArrayHasKey('checked_at', $systemStatus);
        $this->assertTrue($systemStatus['b2c']['available']);
        $this->assertTrue($systemStatus['b2b1']['available']);
    }

    /** @test */
    public function it_provides_banks_with_status(): void
    {
        Http::fake([
            'api.test.chip-in.asia/fpx_b2c' => Http::response(['status' => 'online']),
            'api.test.chip-in.asia/fpx_b2b1' => Http::response(['status' => 'online']),
        ]);

        $banksWithStatus = FPX::getBanksWithStatus();

        $this->assertIsArray($banksWithStatus);
        $this->assertArrayHasKey('maybank2u', $banksWithStatus);
        
        $maybankStatus = $banksWithStatus['maybank2u'];
        $this->assertArrayHasKey('name', $maybankStatus);
        $this->assertArrayHasKey('b2c_available', $maybankStatus);
        $this->assertArrayHasKey('b2b1_available', $maybankStatus);
        $this->assertArrayHasKey('recommended', $maybankStatus);
        $this->assertTrue($maybankStatus['recommended']); // Maybank is in popular banks
    }

    /** @test */
    public function it_gets_available_banks_from_api(): void
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

        $banks = FPX::getAvailableBanks();

        $this->assertIsArray($banks);
        $this->assertArrayHasKey('maybank2u', $banks);
        $this->assertEquals('Maybank2U', $banks['maybank2u']);
    }

    /** @test */
    public function it_falls_back_to_supported_banks_when_api_fails(): void
    {
        Http::fake([
            'api.test.chip-in.asia/*' => Http::response([], 500),
        ]);

        $banks = FPX::getAvailableBanks();

        $this->assertIsArray($banks);
        $this->assertArrayHasKey('maybank2u', $banks);
    }
} 