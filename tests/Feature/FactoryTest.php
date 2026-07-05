<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Models\Plan;
use Aizuddinmanap\CashierChip\PaymentMethod;
use Aizuddinmanap\CashierChip\Subscription;
use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Aizuddinmanap\CashierChip\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Verifies the model factories cover the patterns integrators hand-roll:
 * a bare create() yields a chargeable subscription, and the lifecycle states
 * compose into the shapes cashier:renew / proration / status checks expect.
 */
class FactoryTest extends TestCase
{
    #[Test]
    public function a_bare_subscription_factory_creates_a_chargeable_subscription(): void
    {
        $sub = Subscription::factory()->create();

        $this->assertSame('active', $sub->chip_status);
        $this->assertTrue($sub->renews_at->isPast(), 'due for renewal by default');
        $this->assertNotEmpty($sub->chip_price_id);
        $this->assertGreaterThan(0, $sub->amount(), 'a matching Plan was seeded');
        $this->assertNotNull($sub->owner, 'owner created from configured model');
        $this->assertNotNull($sub->current_period_start);
    }

    #[Test]
    public function past_due_and_due_for_renewal_states_compose(): void
    {
        $sub = Subscription::factory()->pastDue()->dueForRenewal()->create();

        $this->assertSame('past_due', $sub->chip_status);
        $this->assertTrue($sub->renews_at->isPast());

        // A past_due + due subscription is still picked up by the renewal scope.
        $this->assertTrue(
            Subscription::dueForRenewal(Carbon::now())->whereKey($sub->id)->exists()
        );
    }

    #[Test]
    public function on_trial_state_sets_trial_window(): void
    {
        $sub = Subscription::factory()->onTrial()->create();

        $this->assertSame('trialing', $sub->chip_status);
        $this->assertTrue($sub->onTrial());
        $this->assertTrue($sub->trial_ends_at->isFuture());
        $this->assertFalse($sub->renews_at->isPast(), 'renewal not due during trial');
    }

    #[Test]
    public function with_token_state_seeds_a_default_payment_method(): void
    {
        $sub = Subscription::factory()->withToken()->create();

        $owner = $sub->owner;
        $this->assertNotNull($owner->defaultPaymentMethod(), 'default PM seeded');
        $this->assertSame(
            $owner->getMorphClass(),
            $owner->defaultPaymentMethod()->billable_type
        );
    }

    #[Test]
    public function cancelled_and_requires_payment_method_states(): void
    {
        $cancelled = Subscription::factory()->cancelled()->create();
        $this->assertTrue($cancelled->cancelled());
        $this->assertNull(
            Subscription::dueForRenewal(Carbon::now())->whereKey($cancelled->id)->first(),
            'cancelled subs are not due for renewal'
        );

        $rpm = Subscription::factory()->requiresPaymentMethod()->dueForRenewal()->create();
        $this->assertSame('requires_payment_method', $rpm->chip_status);
        $this->assertNotNull(
            Subscription::dueForRenewal(Carbon::now())->whereKey($rpm->id)->first(),
            'requires_payment_method subs stay due so the next run retries'
        );
    }

    #[Test]
    public function for_billable_and_for_plan_bind_correctly(): void
    {
        $user = $this->createUser(['chip_id' => 'client_a']);
        $plan = Plan::factory()->yearly()->create(['price' => 290.00]);

        $sub = Subscription::factory()->forBillable($user)->forPlan($plan)->create();

        $this->assertSame($user->id, $sub->owner->id);
        $this->assertSame($plan->chip_price_id, $sub->chip_price_id);
    }

    #[Test]
    public function for_price_seeds_a_plan_and_binds(): void
    {
        $sub = Subscription::factory()
            ->forPrice('price_custom', 50.00)
            ->create();

        $this->assertSame('price_custom', $sub->chip_price_id);
        $this->assertSame(5000, $sub->amount());
    }

    #[Test]
    public function a_factory_subscription_renews_via_cashier_renew(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/' => Http::response(['id' => 'purchase_f1', 'status' => 'pending']),
            'api.test.chip-in.asia/api/v1/purchases/purchase_f1/charge/' => Http::response(['status' => 'paid']),
        ]);

        $sub = Subscription::factory()->withToken()->dueForRenewal()->create();

        $this->artisan('cashier:renew')->assertSuccessful();

        $sub->refresh();
        $this->assertTrue($sub->renews_at->isFuture(), 'renewal advanced the schedule');
        $this->assertDatabaseHas('transactions', ['chip_id' => 'purchase_f1', 'status' => 'success']);
    }

    #[Test]
    public function plan_factory_creates_a_monthly_plan_by_default(): void
    {
        $plan = Plan::factory()->create();

        $this->assertSame('month', $plan->interval);
        $this->assertSame(1, $plan->interval_count);
        $this->assertSame('MYR', $plan->currency);
    }

    #[Test]
    public function transaction_factory_morphs_to_a_billable(): void
    {
        $user = $this->createUser();

        $txn = Transaction::factory()->forBillable($user)->create();

        $this->assertSame($user->getMorphClass(), $txn->billable_type);
        $this->assertSame($user->id, $txn->billable_id);
        $this->assertSame('success', $txn->status);
    }

    #[Test]
    public function payment_method_factory_attaches_and_defaults(): void
    {
        $user = $this->createUser();

        $pm = PaymentMethod::factory()->forBillable($user)->default()->create();

        $this->assertSame($user->id, $pm->billable_id);
        $this->assertTrue($pm->is_default);
        $this->assertSame($user->defaultPaymentMethod()->id, $pm->id);
    }

    /**
     * Resolve factories under Laravel's default resolver (no custom masking),
     * matching what a real consumer app uses.
     */
    #[Test]
    public function factories_resolve_without_a_custom_resolver(): void
    {
        // Snapshot the test's custom resolver so we can restore it for the
        // rest of the suite; then flush to Laravel's default state.
        Factory::flushState();

        try {
            $sub = Subscription::factory()->create();
            $plan = Plan::factory()->create();
            $txn = Transaction::factory()->create();
            $pm = PaymentMethod::factory()->create();

            $this->assertNotNull($sub->id);
            $this->assertNotNull($plan->id);
            $this->assertNotNull($txn->id);
            $this->assertNotNull($pm->id);

            // Internal chaining also works: SubscriptionFactory seeds a Plan
            // via Plan::factory(), which would blow up if Plan didn't resolve.
            $this->assertNotEmpty($sub->chip_price_id);
            $this->assertGreaterThan(0, $sub->amount());
        } finally {
            // Restore the custom resolver so subsequent tests behave as before.
            Factory::guessFactoryNamesUsing(
                fn (string $modelName) => 'Aizuddinmanap\\CashierChip\\Database\\Factories\\'.class_basename($modelName).'Factory'
            );
        }
    }
}
