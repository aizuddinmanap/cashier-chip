<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Events\SubscriptionChargeFailed;
use Aizuddinmanap\CashierChip\Models\Plan;
use Aizuddinmanap\CashierChip\PaymentMethod;
use Aizuddinmanap\CashierChip\Subscription;
use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class RenewCommandTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser(['chip_id' => 'client_123']);

        // Plan supplies the renewal amount (matched on chip_price_id).
        Plan::create([
            'id' => 'price_monthly',
            'chip_price_id' => 'price_monthly',
            'name' => 'Monthly',
            'price' => 29.00,
            'currency' => 'MYR',
            'interval' => 'month',
            'interval_count' => 1,
        ]);

        // Default saved token the renewal charges against.
        PaymentMethod::create([
            'billable_id' => $this->user->getKey(),
            'billable_type' => $this->user->getMorphClass(),
            'chip_token_id' => 'tok_recurring',
            'is_default' => true,
        ]);
    }

    protected function makeDueSubscription(array $overrides = []): Subscription
    {
        return $this->user->subscriptions()->create(array_merge([
            'name' => 'default',
            'chip_id' => 'sub_' . uniqid(),
            'chip_status' => 'active',
            'chip_price_id' => 'price_monthly',
            'quantity' => 1,
            'ends_at' => null,
            'renews_at' => Carbon::now()->subDay(),
        ], $overrides));
    }

    #[Test]
    public function it_charges_a_due_subscription_and_advances_the_schedule(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'purchase_r1', 'status' => 'pending']),
            'api.test.chip-in.asia/api/v1/purchases/purchase_r1/charge/' => Http::response(['status' => 'paid']),
        ]);

        $sub = $this->makeDueSubscription();
        $oldRenewsAt = $sub->renews_at->copy();

        $this->artisan('cashier:renew')->assertSuccessful();

        $sub->refresh();
        $this->assertEquals('active', $sub->chip_status);
        $this->assertTrue($sub->renews_at->isFuture());
        $this->assertDatabaseHas('transactions', ['chip_id' => 'purchase_r1', 'status' => 'success']);

        // The period just paid for is recorded explicitly: [old renews_at, new renews_at].
        $this->assertEquals($oldRenewsAt->timestamp, $sub->current_period_start->timestamp);
        $this->assertEquals($sub->renews_at->timestamp, $sub->current_period_end->timestamp);
    }

    #[Test]
    public function a_failed_charge_marks_past_due_and_dispatches_the_event(): void
    {
        Event::fake([SubscriptionChargeFailed::class]);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'purchase_r2', 'status' => 'pending']),
            'api.test.chip-in.asia/api/v1/purchases/purchase_r2/charge/' => Http::response(['__all__' => [['code' => 'insufficient_funds']]]),
        ]);

        $sub = $this->makeDueSubscription();

        $this->artisan('cashier:renew')->assertSuccessful();

        $this->assertEquals('past_due', $sub->refresh()->chip_status);
        Event::assertDispatched(SubscriptionChargeFailed::class);
    }

    #[Test]
    public function it_skips_subscriptions_not_yet_due(): void
    {
        Http::fake();

        $sub = $this->makeDueSubscription(['renews_at' => Carbon::now()->addWeek()]);

        $this->artisan('cashier:renew')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertEquals('active', $sub->refresh()->chip_status);
    }

    #[Test]
    public function it_skips_cancelled_subscriptions(): void
    {
        Http::fake();

        // Cancelled = ends_at set; access runs to period end, no more charges.
        $this->makeDueSubscription(['ends_at' => Carbon::now()->addWeek()]);

        $this->artisan('cashier:renew')->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_skips_subscriptions_without_a_billable_amount(): void
    {
        Http::fake();

        // No Plan for this price → no amount → skipped (not past_due).
        $sub = $this->makeDueSubscription(['chip_price_id' => 'price_unknown']);

        $this->artisan('cashier:renew')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertEquals('active', $sub->refresh()->chip_status);
    }

    #[Test]
    public function a_subscription_with_no_saved_token_is_flagged_not_past_due(): void
    {
        Event::fake([SubscriptionChargeFailed::class]);

        Http::fake();

        // Remove the default payment method → no token to charge against.
        PaymentMethod::where('chip_token_id', 'tok_recurring')->delete();

        $sub = $this->makeDueSubscription();

        $this->artisan('cashier:renew')->assertSuccessful();

        // Distinct status, NOT past_due — the customer needs to add a card,
        // this is not a declined-card dunning case.
        $this->assertEquals('requires_payment_method', $sub->refresh()->chip_status);

        // Still due-for-renewal so the next run retries once a card is added.
        $this->assertContains('requires_payment_method', Subscription::dueForRenewal(Carbon::now())->pluck('chip_status')->all());

        // No charge attempted.
        Http::assertNothingSent();

        // App is still notified so it can prompt for a card.
        Event::assertDispatched(
            SubscriptionChargeFailed::class,
            fn ($e) => ($e->payload['reason'] ?? null) === 'no_payment_method'
        );
    }

    #[Test]
    public function chargeIfStillDue_skips_a_subscription_already_advanced_under_the_lock(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'purchase_r3', 'status' => 'pending']),
            'api.test.chip-in.asia/api/v1/purchases/purchase_r3/charge/' => Http::response(['status' => 'paid']),
        ]);

        $sub = $this->makeDueSubscription();

        $command = new \Aizuddinmanap\CashierChip\Console\Commands\RenewCommand();
        $command->setLaravel(app());

        $reflection = new \ReflectionMethod($command, 'chargeIfStillDue');

        // Simulate the race the lock guards: another run already advanced
        // renews_at between the select and this critical section.
        $sub->update(['renews_at' => Carbon::now()->addMonth()]);

        $outcome = $reflection->invoke($command, $sub->refresh(), Carbon::now(), 3);

        $this->assertSame('skipped', $outcome['status']);
        Http::assertNothingSent();
    }

    #[Test]
    public function chargeIfStillDue_charges_and_advances_a_genuinely_due_subscription(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'purchase_r4', 'status' => 'pending']),
            'api.test.chip-in.asia/api/v1/purchases/purchase_r4/charge/' => Http::response(['status' => 'paid']),
        ]);

        $sub = $this->makeDueSubscription();
        $oldRenewsAt = $sub->renews_at->copy();

        $command = new \Aizuddinmanap\CashierChip\Console\Commands\RenewCommand();
        $command->setLaravel(app());

        $reflection = new \ReflectionMethod($command, 'chargeIfStillDue');
        $outcome = $reflection->invoke($command, $sub, Carbon::now(), 3);

        $this->assertSame('renewed', $outcome['status']);
        $this->assertTrue($sub->refresh()->renews_at->isFuture());
        $this->assertDatabaseHas('transactions', ['chip_id' => 'purchase_r4', 'status' => 'success']);

        // Period columns record the actual charged period.
        $this->assertEquals($oldRenewsAt->timestamp, $sub->current_period_start->timestamp);
        $this->assertEquals($sub->renews_at->timestamp, $sub->current_period_end->timestamp);
    }

    #[Test]
    public function a_late_run_does_not_drift_the_billing_anniversary(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'purchase_late', 'status' => 'pending']),
            'api.test.chip-in.asia/api/v1/purchases/purchase_late/charge/' => Http::response(['status' => 'paid']),
        ]);

        // Due on the 5th; the run fires 2 days late, on the 7th.
        $due = Carbon::parse(Carbon::now()->startOfMonth())->setDay(5);
        $runAt = (clone $due)->addDays(2);

        $sub = $this->makeDueSubscription(['renews_at' => $due]);
        $expectedNext = (clone $due)->addMonthNoOverflow();

        $command = new \Aizuddinmanap\CashierChip\Console\Commands\RenewCommand();
        $command->setLaravel(app());

        $reflection = new \ReflectionMethod($command, 'chargeIfStillDue');
        $outcome = $reflection->invoke($command, $sub, $runAt, 3);

        $this->assertSame('renewed', $outcome['status']);

        $sub->refresh();

        // Next renewal anchored to the DUE date (5th), not the run date (7th).
        $this->assertSame($expectedNext->day, $sub->renews_at->day, 'anniversary preserved');

        // Period spans exactly one interval: [due, due + 1 month].
        $this->assertEquals($due->timestamp, $sub->current_period_start->timestamp);
        $this->assertEquals($sub->renews_at->timestamp, $sub->current_period_end->timestamp);
    }

    #[Test]
    public function an_outage_charges_once_and_advances_strictly_past_now(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'purchase_out', 'status' => 'pending']),
            'api.test.chip-in.asia/api/v1/purchases/purchase_out/charge/' => Http::response(['status' => 'paid']),
        ]);

        // Cron was down for ~10 weeks on a monthly plan — renews_at is 10 weeks past.
        $due = Carbon::now()->subWeeks(10);
        $runAt = Carbon::now();

        $sub = $this->makeDueSubscription(['renews_at' => $due]);

        $command = new \Aizuddinmanap\CashierChip\Console\Commands\RenewCommand();
        $command->setLaravel(app());

        $reflection = new \ReflectionMethod($command, 'chargeIfStillDue');
        $outcome = $reflection->invoke($command, $sub, $runAt, 3);

        $this->assertSame('renewed', $outcome['status']);

        $sub->refresh();

        // Charged exactly once (not N times for the 10 missed weeks).
        $this->assertDatabaseCount('transactions', 1);

        // Next renewal is strictly in the future (skip-ahead past $runAt).
        $this->assertTrue($sub->renews_at->isFuture());

        // And it's anchored to the due date's day-of-month, not the run's.
        $this->assertSame($due->day, $sub->renews_at->day);
    }
}
