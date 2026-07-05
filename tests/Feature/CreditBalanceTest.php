<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Events\SubscriptionRenewed;
use Aizuddinmanap\CashierChip\Models\Plan;
use Aizuddinmanap\CashierChip\PaymentMethod;
use Aizuddinmanap\CashierChip\Subscription;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class CreditBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Plan::create([
            'id' => 'price_monthly',
            'chip_price_id' => 'price_monthly',
            'name' => 'Monthly',
            'price' => 29.00,
            'currency' => 'MYR',
            'interval' => 'month',
            'interval_count' => 1,
        ]);
    }

    protected function dueSubWithToken(int $credit = 0): Subscription
    {
        $user = $this->createUser(['chip_id' => 'client_' . uniqid()]);

        PaymentMethod::create([
            'billable_id' => $user->getKey(),
            'billable_type' => $user->getMorphClass(),
            'chip_token_id' => 'tok_' . uniqid(),
            'is_default' => true,
        ]);

        return $user->subscriptions()->create([
            'name' => 'default',
            'chip_id' => 'sub_' . uniqid(),
            'chip_status' => 'active',
            'chip_price_id' => 'price_monthly',
            'quantity' => 1,
            'ends_at' => null,
            'renews_at' => Carbon::now()->subDay(),
            'credit_balance' => $credit,
        ]);
    }

    #[Test]
    public function add_credit_increments_and_refreshes(): void
    {
        $sub = $this->dueSubWithToken();

        $sub->addCredit(1500);
        $this->assertSame(1500, $sub->creditBalance());

        $sub->addCredit(500);
        $this->assertSame(2000, $sub->creditBalance());

        // Non-positive inputs are no-ops.
        $sub->addCredit(-100);
        $sub->addCredit(0);
        $this->assertSame(2000, $sub->creditBalance());
    }

    #[Test]
    public function full_credit_renewal_skips_gateway_and_records_credit_txn(): void
    {
        Http::fake();

        Event::fake([SubscriptionRenewed::class]);

        // 2900 cycle, 3000 credit → covers it entirely.
        $sub = $this->dueSubWithToken(credit: 3000);
        $oldRenewsAt = $sub->renews_at->copy();

        $this->artisan('cashier:renew')->assertSuccessful();

        $sub->refresh();

        // No gateway call.
        Http::assertNothingSent();

        // Credit-only transaction of type 'credit', payment_method 'credit_balance'.
        $this->assertDatabaseHas('transactions', [
            'type' => 'credit',
            'payment_method' => 'credit_balance',
            'status' => 'success',
            'total' => 2900,
        ]);

        // Schedule advanced, period columns recorded, credit decremented.
        $this->assertTrue($sub->renews_at->isFuture());
        $this->assertSame($oldRenewsAt->timestamp, $sub->current_period_start->timestamp);
        $this->assertSame(100, $sub->creditBalance(), 'leftover credit retained');

        Event::assertDispatched(SubscriptionRenewed::class);
    }

    #[Test]
    public function partial_credit_renewal_charges_net_and_reconciles_ledger(): void
    {
        // Net 2900, credit 1000 → charge 1900, stamp credit_applied = 1000.
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'purchase_pc', 'status' => 'pending']),
            'api.test.chip-in.asia/api/v1/purchases/purchase_pc/charge/' => Http::response(['status' => 'paid']),
        ]);

        $sub = $this->dueSubWithToken(credit: 1000);

        $this->artisan('cashier:renew')->assertSuccessful();

        $sub->refresh();

        // Charged the NET amount, not gross.
        $this->assertDatabaseHas('transactions', [
            'chip_id' => 'purchase_pc',
            'type' => 'charge',
            'status' => 'success',
            'total' => 1900,
        ]);

        // Ledger reconciles: charge.total + credit_applied === gross.
        $txn = \Aizuddinmanap\CashierChip\Transaction::where('chip_id', 'purchase_pc')->first();
        $this->assertSame(1000, $txn->metadata['credit_applied'] ?? null);
        $this->assertSame(2900, $txn->total + $txn->metadata['credit_applied']);

        // Credit decremented by exactly what was used.
        $this->assertSame(0, $sub->creditBalance());
        $this->assertTrue($sub->renews_at->isFuture());
    }

    #[Test]
    public function no_token_partial_credit_flags_and_leaves_credit_untouched(): void
    {
        Http::fake();

        $sub = $this->dueSubWithToken(credit: 1000);
        $sub->owner->paymentMethods()->delete(); // remove the token

        $this->artisan('cashier:renew')->assertSuccessful();

        $sub->refresh();

        // Flagged, not past_due.
        $this->assertSame('requires_payment_method', $sub->chip_status);

        // Credit NOT consumed (decrement only happens on a successful charge).
        $this->assertSame(1000, $sub->creditBalance());

        // No gateway call attempted.
        Http::assertNothingSent();
    }

    #[Test]
    public function credit_decrement_and_period_advance_are_atomic(): void
    {
        // Same setup as full-credit: assert the DB row reflects BOTH the
        // schedule advance AND the consumed credit after a single renewal,
        // i.e. they landed in the same update().
        Http::fake();

        $sub = $this->dueSubWithToken(credit: 2900);

        $this->artisan('cashier:renew')->assertSuccessful();

        $sub->refresh();

        // After a single successful renewal: period advanced AND balance is 0.
        // If these were separate writes with a crash between, a retry would
        // double-spend; this asserts they're consistent in the success case.
        $this->assertTrue($sub->renews_at->isFuture());
        $this->assertSame(0, $sub->creditBalance());
        $this->assertSame('active', $sub->chip_status);
    }

    #[Test]
    public function failed_partial_credit_charge_does_not_consume_credit(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'purchase_fail', 'status' => 'pending']),
            'api.test.chip-in.asia/api/v1/purchases/purchase_fail/charge/' => Http::response(['__all__' => [['code' => 'insufficient_funds']]]),
        ]);

        $sub = $this->dueSubWithToken(credit: 1000);

        $this->artisan('cashier:renew')->assertSuccessful();

        $sub->refresh();

        // Charge failed → past_due, credit retained for the retry.
        $this->assertSame('past_due', $sub->chip_status);
        $this->assertSame(1000, $sub->creditBalance());
    }

    #[Test]
    public function swap_and_invoice_prorate_banks_credit_on_downgrade(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'purchase_sw', 'status' => 'pending']),
            'api.test.chip-in.asia/api/v1/purchases/purchase_sw/charge/' => Http::response(['status' => 'paid']),
        ]);

        $user = $this->createUser(['chip_id' => 'client_sw']);

        PaymentMethod::create([
            'billable_id' => $user->getKey(),
            'billable_type' => $user->getMorphClass(),
            'chip_token_id' => 'tok_sw',
            'is_default' => true,
        ]);

        // Downgrade mid-cycle: pro → basic.
        Plan::create([
            'id' => 'price_basic', 'chip_price_id' => 'price_basic',
            'name' => 'Basic', 'price' => 10.00, 'currency' => 'MYR',
            'interval' => 'month', 'interval_count' => 1,
        ]);
        Plan::create([
            'id' => 'price_pro', 'chip_price_id' => 'price_pro',
            'name' => 'Pro', 'price' => 30.00, 'currency' => 'MYR',
            'interval' => 'month', 'interval_count' => 1,
        ]);

        $sub = $user->subscriptions()->create([
            'name' => 'default',
            'chip_id' => 'sub_sw',
            'chip_status' => 'active',
            'chip_price_id' => 'price_pro',
            'quantity' => 1,
            'ends_at' => null,
            'renews_at' => Carbon::now()->addDays(15),
            'current_period_start' => Carbon::now()->subDays(15),
            'current_period_end' => Carbon::now()->addDays(15),
        ]);

        $sub->swapAndInvoice('price_basic', ['prorate' => true]);

        $sub->refresh();

        // Downgrade → unused value banked as credit (prorationFor < 0).
        $this->assertGreaterThan(0, $sub->creditBalance());
        $this->assertSame('price_basic', $sub->chip_price_id);

        // Nothing charged (downgrade credit, not a gateway charge).
        Http::assertNothingSent();
    }
}
