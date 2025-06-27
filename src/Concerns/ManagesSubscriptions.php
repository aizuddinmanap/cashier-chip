<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Concerns;

use Aizuddinmanap\CashierChip\Subscription;
use Aizuddinmanap\CashierChip\SubscriptionBuilder;

trait ManagesSubscriptions
{
    /**
     * Begin creating a new subscription.
     */
    public function newSubscription(string $name, string $plan): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $name, $plan);
    }

    /**
     * Determine if the user is actively subscribed to one of the given plans.
     */
    public function subscribedToPlan($plans, string $subscription = 'default'): bool
    {
        $subscription = $this->subscription($subscription);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        return $subscription->hasPlan($plans);
    }

    /**
     * Determine if the entity is on the given plan.
     */
    public function onPlan(string $plan): bool
    {
        return $this->subscriptions->contains(function (Subscription $subscription) use ($plan) {
            return $subscription->valid() && $subscription->hasPlan($plan);
        });
    }

    /**
     * Determine if the entity has any active subscriptions.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->subscriptions->contains(function (Subscription $subscription) {
            return $subscription->active();
        });
    }

    /**
     * Get the entity's most recently created subscription.
     */
    public function latestSubscription(): ?Subscription
    {
        return $this->subscriptions->sortByDesc('created_at')->first();
    }
} 