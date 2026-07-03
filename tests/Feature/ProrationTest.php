<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Events\SubscriptionRenewed;
use Aizuddinmanap\CashierChip\Models\Plan;
use Aizuddinmanap\CashierChip\PaymentMethod;
use Aizuddinmanap\CashierChip\Proration;
use Aizuddinmanap\CashierChip\Subscription;
use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class ProrationTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser(['chip_id' => 'client_123']);

        foreach ([['price_basic', 10.00], ['price_pro', 30.00]] as [$id, $price]) {
            Plan::create([
                'id' => $id,
                'chip_price_id' => $id,
                'name' => $id,
                'price' => $price,
                'currency' => 'MYR',
                'interval' => 'month',
                'interval_count' => 1,
            ]);
        }

        PaymentMethod::create([
            'billable_id' => $this->user->getKey(),
            'billable_type' => $this->user->getMorphClass(),
            'chip_token_id' => 'tok_recurring',
            'is_default' => true,
        ]);
    }

    protected function subscription(string $price = 'price_basic', array $overrides = []): Subscription
    {
        return $this->user->subscriptions()->create(array_merge([
            'name' => 'default',
            'chip_id' => 'sub_' . uniqid(),
            'chip_status' => 'active',
            'chip_price_id' => $price,
            'quantity' => 1,
            'ends_at' => null,
            'renews_at' => Carbon::now()->addDays(15),
        ], $overrides));
    }

    #[Test]
    public function the_proration_helper_is_pure_and_deterministic(): void
    {
        $start = Carbon::parse('2026-01-01');
        $end = Carbon::parse('2026-01-31');   // 30-day period

        // Full delta at the start, nothing at the end, ~half at the midpoint.
        $this->assertEqualsWithDelta(2000, Proration::calculate(1000, 3000, $start, $end, $start), 2);
        $this->assertEquals(0, Proration::calculate(1000, 3000, $start, $end, $end));
        $this->assertEqualsWithDelta(1000, Proration::calculate(1000, 3000, $start, $end, Carbon::parse('2026-01-16')), 80);

        // Downgrade yields a credit (negative); no side effects, no DB.
        $this->assertLessThan(0, Proration::calculate(3000, 1000, $start, $end, Carbon::parse('2026-01-16')));
    }

    #[Test]
    public function proration_for_uses_the_subscription_period_and_amounts(): void
    {
        $sub = $this->subscription('price_basic');   // upgrade to price_pro

        $proration = $sub->prorationFor('price_pro');

        $this->assertGreaterThan(0, $proration);
        $this->assertLessThan(2000, $proration);      // partial period → partial delta
        // Pure preview: it changed nothing.
        $this->assertEquals('price_basic', $sub->fresh()->chip_price_id);
        $this->assertNull($sub->fresh()->pending_plan_id);
        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function swap_and_invoice_switches_now_and_charges_the_given_amount(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'purchase_now', 'status' => 'pending']),
            'api.test.chip-in.asia/api/v1/purchases/purchase_now/charge/' => Http::response(['status' => 'paid']),
        ]);

        $sub = $this->subscription('price_basic');

        // Caller opts into proration and passes the amount to charge.
        $amount = $sub->prorationFor('price_pro');
        $sub->swapAndInvoice('price_pro', ['amount' => $amount]);

        $fresh = $sub->fresh();
        $this->assertEquals('price_pro', $fresh->chip_price_id);
        $this->assertNull($fresh->pending_plan_id);
        $this->assertDatabaseHas('transactions', ['chip_id' => 'purchase_now', 'total' => $amount, 'status' => 'success']);
    }

    #[Test]
    public function cashier_renew_applies_a_pending_plan_change_then_charges_the_new_amount(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'purchase_r', 'status' => 'pending']),
            'api.test.chip-in.asia/api/v1/purchases/purchase_r/charge/' => Http::response(['status' => 'paid']),
        ]);

        $sub = $this->subscription('price_basic', [
            'renews_at' => Carbon::now()->subDay(),
            'pending_plan_id' => 'price_pro',
        ]);

        $this->artisan('cashier:renew')->assertSuccessful();

        $fresh = $sub->fresh();
        $this->assertEquals('price_pro', $fresh->chip_price_id);   // pending applied
        $this->assertNull($fresh->pending_plan_id);
        // Charged the NEW plan's amount (3000), not the old one.
        $this->assertDatabaseHas('transactions', ['chip_id' => 'purchase_r', 'total' => 3000, 'status' => 'success']);
        Event::assertDispatched(SubscriptionRenewed::class);
    }
}
