<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Events\TransactionCompleted;
use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class ReconcileCommandTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser();
    }

    protected function pendingTransaction(string $chipId, string $status = 'pending', int $ageMinutes = 30)
    {
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_' . $chipId,
            'chip_id' => $chipId,
            'total' => 1000,
            'status' => $status,
            'currency' => 'MYR',
        ]);

        // Backdate so it passes the older-than threshold.
        $transaction->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

        return $transaction;
    }

    #[Test]
    public function it_marks_a_pending_transaction_paid_when_chip_says_paid(): void
    {
        $transaction = $this->pendingTransaction('purchase_recon_1');

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_recon_1/' => Http::response([
                'id' => 'purchase_recon_1',
                'status' => 'paid',
            ]),
        ]);

        $this->artisan('cashier:reconcile')->assertExitCode(0);

        $this->assertEquals('success', $transaction->fresh()->status);
        $this->assertNotNull($transaction->fresh()->processed_at);
    }

    #[Test]
    public function it_dispatches_transaction_completed_on_recovery(): void
    {
        Event::fake([TransactionCompleted::class]);

        $this->pendingTransaction('purchase_recon_evt');

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_recon_evt/' => Http::response([
                'id' => 'purchase_recon_evt',
                'status' => 'paid',
            ]),
        ]);

        $this->artisan('cashier:reconcile')->assertExitCode(0);

        Event::assertDispatchedTimes(TransactionCompleted::class, 1);
    }

    #[Test]
    public function it_marks_failed_when_chip_says_error(): void
    {
        $transaction = $this->pendingTransaction('purchase_recon_fail');

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_recon_fail/' => Http::response([
                'id' => 'purchase_recon_fail',
                'status' => 'error',
            ]),
        ]);

        $this->artisan('cashier:reconcile')->assertExitCode(0);

        $this->assertEquals('failed', $transaction->fresh()->status);
    }

    #[Test]
    public function it_leaves_transient_states_untouched(): void
    {
        $transaction = $this->pendingTransaction('purchase_recon_wait');

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_recon_wait/' => Http::response([
                'id' => 'purchase_recon_wait',
                'status' => 'sent', // still in progress
            ]),
        ]);

        $this->artisan('cashier:reconcile')->assertExitCode(0);

        $this->assertEquals('pending', $transaction->fresh()->status);
    }

    #[Test]
    public function it_skips_transactions_newer_than_the_threshold(): void
    {
        // Created 1 minute ago, below the default 5-minute threshold.
        $transaction = $this->pendingTransaction('purchase_recon_new', 'pending', 1);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/*' => Http::response([
                'id' => 'purchase_recon_new',
                'status' => 'paid',
            ]),
        ]);

        $this->artisan('cashier:reconcile')->assertExitCode(0);

        $this->assertEquals('pending', $transaction->fresh()->status);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_does_not_poll_transactions_older_than_max_age(): void
    {
        // 50 hours old — past the 48h (2880 min) max age, so excluded from the sweep.
        $transaction = $this->pendingTransaction('purchase_recon_old', 'pending', 3000);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/*' => Http::response([
                'id' => 'purchase_recon_old',
                'status' => 'paid',
            ]),
        ]);

        $this->artisan('cashier:reconcile')->assertExitCode(0);

        $this->assertEquals('pending', $transaction->fresh()->status);
        Http::assertNothingSent();
    }
}
