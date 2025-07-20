<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Aizuddinmanap\CashierChip\Models\Plan;

class Subscription extends Model
{
    /**
     * The attributes that are not mass assignable.
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'trial_ends_at' => 'datetime',
        'paused_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * Get the user that owns the subscription.
     */
    public function user(): BelongsTo
    {
        return $this->owner();
    }

    /**
     * Get the model related to the subscription.
     */
    public function owner(): BelongsTo
    {
        $model = config('cashier.model');

        return $this->belongsTo($model, (new $model)->getForeignKey());
    }

    /**
     * Get the subscription items related to the subscription.
     */
    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    /**
     * Get the plan for the subscription.
     */
    public function plan(): ?Plan
    {
        if (!$this->chip_price_id) {
            return null;
        }

        // First try to find by plan ID
        $plan = Plan::find($this->chip_price_id);
        
        // If not found, try to find by chip_price_id
        if (!$plan) {
            $plan = Plan::where('chip_price_id', $this->chip_price_id)->first();
        }
        
        return $plan;
    }

    /**
     * Determine if the subscription is active.
     */
    public function active(): bool
    {
        return $this->ends_at === null || $this->onGracePeriod();
    }

    /**
     * Filter query by active.
     */
    public function scopeActive($query)
    {
        return $query->where(function ($query) {
            $query->whereNull('ends_at')
                  ->orWhere('ends_at', '>', Carbon::now());
        });
    }

    /**
     * Filter query by inactive subscriptions.
     */
    public function scopeInactive($query)
    {
        return $query->where('ends_at', '<=', Carbon::now());
    }

    /**
     * Filter query by cancelled subscriptions.
     */
    public function scopeCancelled($query)
    {
        return $query->whereNotNull('ends_at');
    }

    /**
     * Filter query by not cancelled subscriptions.
     */
    public function scopeNotCancelled($query)
    {
        return $query->whereNull('ends_at');
    }

    /**
     * Filter query by subscriptions on trial.
     */
    public function scopeOnTrial($query)
    {
        return $query->whereNotNull('trial_ends_at')
                     ->where('trial_ends_at', '>', Carbon::now());
    }

    /**
     * Filter query by subscriptions not on trial.
     */
    public function scopeNotOnTrial($query)
    {
        return $query->where(function ($query) {
            $query->whereNull('trial_ends_at')
                  ->orWhere('trial_ends_at', '<=', Carbon::now());
        });
    }

    /**
     * Filter query by subscriptions on grace period.
     */
    public function scopeOnGracePeriod($query)
    {
        return $query->whereNotNull('ends_at')
                     ->where('ends_at', '>', Carbon::now());
    }

    /**
     * Filter query by subscriptions not on grace period.
     */
    public function scopeNotOnGracePeriod($query)
    {
        return $query->where(function ($query) {
            $query->whereNull('ends_at')
                  ->orWhere('ends_at', '<=', Carbon::now());
        });
    }

    /**
     * Filter query by ended subscriptions.
     */
    public function scopeEnded($query)
    {
        return $query->whereNotNull('ends_at')
                     ->where('ends_at', '<=', Carbon::now());
    }

    /**
     * Filter query by recurring subscriptions.
     */
    public function scopeRecurring($query)
    {
        return $query->whereNull('ends_at')
                     ->where(function ($query) {
                         $query->whereNull('trial_ends_at')
                               ->orWhere('trial_ends_at', '<=', Carbon::now());
                     });
    }

    /**
     * Filter query by subscription name.
     */
    public function scopeByName($query, string $name)
    {
        return $query->where('name', $name);
    }

    /**
     * Filter query by price ID.
     */
    public function scopeByPrice($query, string $priceId)
    {
        return $query->where('chip_price_id', $priceId);
    }

    /**
     * Filter query by subscription status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('chip_status', $status);
    }

    /**
     * Filter query by valid subscriptions (active or on grace period).
     */
    public function scopeValid($query)
    {
        return $query->where(function ($query) {
            $query->whereNull('ends_at')
                  ->orWhere('ends_at', '>', Carbon::now());
        });
    }

    /**
     * Determine if the subscription is recurring and not on trial.
     */
    public function recurring(): bool
    {
        return ! $this->onTrial() && ! $this->cancelled();
    }

    /**
     * Determine if the subscription is no longer active.
     */
    public function cancelled(): bool
    {
        return ! is_null($this->ends_at);
    }

    /**
     * Determine if the subscription has ended and the grace period has expired.
     */
    public function ended(): bool
    {
        return $this->cancelled() && ! $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is within its trial period.
     */
    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Determine if the subscription is within its grace period after cancellation.
     */
    public function onGracePeriod(): bool
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    /**
     * Determine if the subscription has a specific plan.
     */
    public function hasPlan($plans): bool
    {
        $plans = is_array($plans) ? $plans : func_get_args();

        return in_array($this->chip_price_id, $plans);
    }

    /**
     * Determine if the subscription is valid.
     */
    public function valid(): bool
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
    }

    /**
     * Cancel the subscription at the end of the billing period.
     */
    public function cancel(): self
    {
        // Skip API call for trial-only subscriptions
        if ($this->onTrial() && ! $this->hasChipId()) {
            $this->fill(['ends_at' => Carbon::now()])->save();
            return $this;
        }

        // For paid subscriptions, make API call to Chip
        if ($this->hasChipId()) {
            try {
                $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
                $api->cancelSubscription($this->chip_id, [
                    'effective_from' => 'next_billing_period'
                ]);
            } catch (\Exception $e) {
                // Log error but continue with local cancellation
                \Log::warning('Failed to cancel subscription via Chip API: ' . $e->getMessage());
            }
        }

        // Mark as cancelled at end of billing period
        $this->fill(['ends_at' => $this->ends_at ?? Carbon::now()->addDays(30)])->save();

        // Dispatch cancellation event
        event(new \Aizuddinmanap\CashierChip\Events\SubscriptionCanceled($this));

        return $this;
    }

    /**
     * Cancel the subscription immediately.
     */
    public function cancelNow(): self
    {
        // Skip API call for trial-only subscriptions
        if ($this->onTrial() && ! $this->hasChipId()) {
            $this->fill(['ends_at' => Carbon::now()])->save();
            return $this;
        }

        // For paid subscriptions, make API call to Chip
        if ($this->hasChipId()) {
            try {
                $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
                $api->cancelSubscription($this->chip_id, [
                    'effective_from' => 'immediately'
                ]);
            } catch (\Exception $e) {
                // Log error but continue with local cancellation
                \Log::warning('Failed to cancel subscription via Chip API: ' . $e->getMessage());
            }
        }

        // Mark as cancelled immediately
        $this->fill(['ends_at' => Carbon::now()])->save();

        // Dispatch cancellation event
        event(new \Aizuddinmanap\CashierChip\Events\SubscriptionCanceled($this));

        return $this;
    }

    /**
     * Stop the subscription from cancelling (remove scheduled cancellation).
     */
    public function stopCancellation(): self
    {
        // Skip API call for trial-only subscriptions
        if ($this->onTrial() && ! $this->hasChipId()) {
            $this->fill(['ends_at' => null])->save();
            return $this;
        }

        // For paid subscriptions, make API call to Chip
        if ($this->hasChipId()) {
            try {
                $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
                $api->updateSubscription($this->chip_id, [
                    'scheduled_change' => null
                ]);
            } catch (\Exception $e) {
                // Log error but continue with local update
                \Log::warning('Failed to stop cancellation via Chip API: ' . $e->getMessage());
            }
        }

        // Remove scheduled cancellation
        $this->fill(['ends_at' => null])->save();

        return $this;
    }

    /**
     * Resume a cancelled subscription (alias for stopCancellation).
     */
    public function resume(): self
    {
        return $this->stopCancellation();
    }

    /**
     * Check if the subscription has a Chip ID (not trial-only).
     */
    protected function hasChipId(): bool
    {
        return ! empty($this->chip_id) && ! str_starts_with($this->chip_id, 'trial_');
    }

    /**
     * Get the price associated with the subscription.
     */
    public function price(): ?Price
    {
        return $this->chip_price_id ? Price::find($this->chip_price_id) : null;
    }

    /**
     * Get the total amount for the subscription.
     */
    public function amount(): int
    {
        // If subscription has items, sum their amounts
        if ($this->items()->exists()) {
            return $this->items->sum(function ($item) {
                $price = $item->price();
                return $price ? $price->amount() : 0;
            });
        }

        // Otherwise, use the subscription's own price
        $price = $this->price();
        return $price ? $price->amount() : 0;
    }

    /**
     * Get the currency for the subscription.
     */
    public function currency(): string
    {
        $price = $this->price();
        return $price ? $price->currency() : config('cashier.currency', 'MYR');
    }
} 