<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class CapturePaymentTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser();
    }

    protected function heldTransaction(string $status = 'preauthorized')
    {
        return $this->user->transactions()->create([
            'id' => 'txn_hold',
            'chip_id' => 'purchase_hold_1',
            'total' => 5000,
            'status' => $status,
            'currency' => 'MYR',
        ]);
    }

    #[Test]
    public function it_can_capture_a_held_payment(): void
    {
        $transaction = $this->heldTransaction();

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_hold_1/capture/' => Http::response([
                'id' => 'purchase_hold_1',
                'status' => 'paid',
            ]),
        ]);

        $result = $this->user->captureCharge($transaction->id);

        $this->assertEquals('success', $result->status);
        $this->assertEquals('success', $transaction->fresh()->status);
        $this->assertNotNull($transaction->fresh()->processed_at);
    }

    #[Test]
    public function it_can_capture_a_partial_amount(): void
    {
        $transaction = $this->heldTransaction();

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_hold_1/capture/' => Http::response([
                'id' => 'purchase_hold_1',
                'status' => 'paid',
            ]),
        ]);

        $transaction->capture(2000);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/capture/')
                && $request['amount'] === 2000;
        });
    }

    #[Test]
    public function it_can_void_a_held_payment(): void
    {
        $transaction = $this->heldTransaction('on_hold');

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_hold_1/release/' => Http::response([
                'id' => 'purchase_hold_1',
                'status' => 'released',
            ]),
        ]);

        $result = $this->user->voidCharge($transaction->id);

        $this->assertEquals('voided', $result->status);
        $this->assertEquals('voided', $transaction->fresh()->status);
    }

    #[Test]
    public function it_cannot_capture_an_already_successful_transaction(): void
    {
        $transaction = $this->heldTransaction('success');

        $this->expectException(\Exception::class);

        $transaction->capture();
    }

    #[Test]
    public function it_throws_when_chip_does_not_confirm_capture(): void
    {
        $transaction = $this->heldTransaction();

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_hold_1/capture/' => Http::response([
                'id' => 'purchase_hold_1',
                'status' => 'pending_capture',
            ]),
        ]);

        $this->expectException(\Exception::class);

        try {
            $transaction->capture();
        } finally {
            // Status must remain unchanged when capture is not confirmed.
            $this->assertEquals('preauthorized', $transaction->fresh()->status);
        }
    }
}
