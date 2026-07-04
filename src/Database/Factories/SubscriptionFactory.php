<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Database\Factories;

use Aizuddinmanap\CashierChip\Models\Plan;
use Aizuddinmanap\CashierChip\PaymentMethod;
use Aizuddinmanap\CashierChip\Subscription;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * Configure: ensure the subscription has an owner and a matching Plan so a
     * bare Subscription::factory()->create() is immediately chargeable.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Subscription $subscription) {
            $fk = $subscription->owner()->getForeignKeyName();

            if (! $subscription->getAttribute($fk)) {
                $model = config('cashier.model');
                $owner = new $model();
                $owner->fill([
                    'name' => 'Factory User',
                    'email' => 'factory_' . uniqid() . '@example.com',
                    'password' => bcrypt('password'),
                ])->save();

                $subscription->owner()->associate($owner);
            }

            if (! $subscription->chip_price_id) {
                $priceId = 'price_' . uniqid();

                Plan::factory()->create([
                    'id' => $priceId,
                    'chip_price_id' => $priceId,
                    'name' => 'Factory Plan',
                    'price' => 29.00,
                ]);

                $subscription->chip_price_id = $priceId;
            }
        });
    }

    public function definition(): array
    {
        return [
            'name' => 'default',
            'chip_id' => 'sub_' . uniqid(),
            'chip_status' => 'active',
            'chip_price_id' => null, // seeded by configure() if unset
            'quantity' => 1,
            'trial_ends_at' => null,
            'paused_at' => null,
            'ends_at' => null,
            'renews_at' => Carbon::now()->subDay(),
            'current_period_start' => Carbon::now()->subMonth(),
            'current_period_end' => Carbon::now()->subDay(),
        ];
    }

    /**
     * Bind the subscription to a specific billable model.
     */
    public function forBillable($billable): self
    {
        return $this->state([])->afterMaking(function (Subscription $subscription) use ($billable) {
            $subscription->owner()->associate($billable);
        });
    }

    /**
     * Seed a Plan for the given price id and bind the subscription to it.
     */
    public function forPrice(string $priceId, float $price = 29.00, array $planAttributes = []): self
    {
        return $this->afterMaking(function (Subscription $subscription) use ($priceId, $price, $planAttributes) {
            Plan::factory()->create(array_merge([
                'id' => $priceId,
                'chip_price_id' => $priceId,
                'name' => ucfirst($priceId),
                'price' => $price,
            ], $planAttributes));

            $subscription->chip_price_id = $priceId;
        });
    }

    /**
     * Bind the subscription to an existing Plan (reuses its chip_price_id).
     */
    public function forPlan(Plan $plan): self
    {
        return $this->afterMaking(function (Subscription $subscription) use ($plan) {
            $subscription->chip_price_id = $plan->chip_price_id;
        });
    }

    /**
     * Active and recurring (the default status, made explicit).
     */
    public function active(): self
    {
        return $this->state([
            'chip_status' => 'active',
            'ends_at' => null,
            'trial_ends_at' => null,
        ]);
    }

    /**
     * Past due — a renewal charge failed; retried after the grace period.
     */
    public function pastDue(): self
    {
        return $this->state([
            'chip_status' => 'past_due',
            'ends_at' => null,
        ]);
    }

    /**
     * Due for renewal now (renews_at in the past).
     */
    public function dueForRenewal(): self
    {
        return $this->state([
            'renews_at' => Carbon::now()->subDay(),
            'ends_at' => null,
        ]);
    }

    /**
     * On a free trial — trialing status, trial_ends_at in the future.
     */
    public function onTrial(): self
    {
        return $this->state([
            'chip_status' => 'trialing',
            'trial_ends_at' => Carbon::now()->addDays(14),
            'renews_at' => Carbon::now()->addDays(14),
            'ends_at' => null,
        ]);
    }

    /**
     * Cancelled — ends_at set; access runs to period end, no more charges.
     */
    public function cancelled(): self
    {
        return $this->state([
            'chip_status' => 'canceled',
            'ends_at' => Carbon::now()->addWeek(),
        ]);
    }

    /**
     * Flagged requires_payment_method — due but no saved token to charge.
     */
    public function requiresPaymentMethod(): self
    {
        return $this->state([
            'chip_status' => 'requires_payment_method',
            'ends_at' => null,
        ]);
    }

    /**
     * Give the owner a saved (default) payment method the renewal can charge.
     * Call after forBillable() so the PM attaches to the right model.
     */
    public function withToken(): self
    {
        return $this->afterCreating(function (Subscription $subscription) {
            $owner = $subscription->owner()->first();

            if (! $owner) {
                return;
            }

            PaymentMethod::factory()->create([
                'billable_type' => $owner->getMorphClass(),
                'billable_id' => $owner->getKey(),
                'is_default' => true,
            ]);
        });
    }
}
