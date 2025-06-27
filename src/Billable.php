<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Aizuddinmanap\CashierChip\Concerns\ManagesCustomer;
use Aizuddinmanap\CashierChip\Concerns\ManagesInvoices;
use Aizuddinmanap\CashierChip\Concerns\ManagesPaymentMethods;
use Aizuddinmanap\CashierChip\Concerns\ManagesSubscriptions;
use Aizuddinmanap\CashierChip\Concerns\ManagesTransactions;
use Aizuddinmanap\CashierChip\Concerns\PerformsCharges;

trait Billable
{
    use ManagesCustomer;
    use ManagesInvoices;
    use ManagesPaymentMethods;
    use ManagesSubscriptions;
    use ManagesTransactions;
    use PerformsCharges;

    /**
     * Get all transactions for the billable entity.
     */
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'billable')->orderByDesc('created_at');
    }

    /**
     * Get all subscriptions for the billable entity.
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class)->orderByDesc('created_at');
    }

    /**
     * Get a subscription instance by name.
     */
    public function subscription(string $name = 'default'): ?Subscription
    {
        return $this->subscriptions->where('name', $name)->first();
    }

    /**
     * Determine if the billable entity is on trial.
     */
    public function onTrial(string $name = 'default', ?string $plan = null): bool
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($name);

        if (! $subscription || ! $subscription->onTrial()) {
            return false;
        }

        return $plan ? $subscription->hasPlan($plan) : true;
    }

    /**
     * Determine if the billable entity is on a "generic" trial.
     */
    public function onGenericTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Determine if the billable entity has a given subscription.
     */
    public function subscribed(string $name = 'default', ?string $plan = null): bool
    {
        $subscription = $this->subscription($name);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        return $plan ? $subscription->hasPlan($plan) : true;
    }

    /**
     * Get the entity's trial end date.
     */
    public function trialEndsAt(): ?\DateTimeInterface
    {
        return $this->trial_ends_at;
    }
} 