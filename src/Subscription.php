<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Aizuddinmanap\CashierChip\Database\Factories\SubscriptionFactory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Aizuddinmanap\CashierChip\Models\Plan;

class Subscription extends Model
{
    use HasFactory;

    /**
     * Resolve the factory directly so Model::factory() works in consumer apps
     * regardless of Factory::$namespace or any app-level factory resolver.
     */
    protected static function newFactory(): Factory
    {
        return SubscriptionFactory::new();
    }

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
        'renews_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'credit_balance' => 'integer',
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
     * Compute the next renewal date from the given time using the plan interval.
     *
     * Falls back to a monthly interval when no local Plan matches chip_price_id.
     */
    public function nextRenewalFrom(?Carbon $from = null): Carbon
    {
        $from = $from ? $from->copy() : Carbon::now();

        $plan = $this->plan();
        $interval = $plan?->interval ?? 'month';
        $count = max(1, (int) ($plan?->interval_count ?? 1));

        return match ($interval) {
            'day' => $from->addDays($count),
            'week' => $from->addWeeks($count),
            'year' => $from->addYears($count),
            default => $from->addMonths($count),
        };
    }

    /**
     * The next renewal date strictly after $after, anchored to the prior
     * renews_at (not to "now").
     *
     * Used by cashier:renew so a late scheduler run doesn't drift the billing
     * anniversary — nextRenewalFrom($now) would move "5th + 1 month" to
     * "6th + 1 month" whenever the run fires a day late, and the slip compounds
     * every cycle. Anchoring to the old renews_at keeps the day-of-month stable.
     *
     * Skip-ahead: if an outage left renews_at several intervals in the past,
     * advances until the result is strictly in the future so the customer is
     * charged once, not N times for downtime. Bounded (nextRenewalFrom always
     * advances by at least one unit) so the loop terminates.
     */
    public function nextRenewalAfter(Carbon $after, Carbon $now): Carbon
    {
        $next = $this->nextRenewalFrom($after);

        // Guard against a degenerate non-advancing interval before looping.
        while ($next->lessThanOrEqualTo($now) && $next->greaterThan($after)) {
            $next = $this->nextRenewalFrom($next);
        }

        return $next;
    }

    /**
     * The start of the current billing period.
     *
     * Prefers the persisted current_period_start (an authoritative record of
     * the actual charged period, written whenever renews_at advances). Falls
     * back to renews_at minus one interval for legacy rows where the column
     * is null.
     */
    public function currentPeriodStart(): ?Carbon
    {
        if ($this->current_period_start) {
            return $this->current_period_start->copy();
        }

        return $this->computedPeriodStart();
    }

    /**
     * Derive the period start from renews_at minus one plan interval.
     */
    protected function computedPeriodStart(): ?Carbon
    {
        if (! $this->renews_at) {
            return null;
        }

        $plan = $this->plan();
        $interval = $plan?->interval ?? 'month';
        $count = max(1, (int) ($plan?->interval_count ?? 1));
        $end = $this->renews_at->copy();

        return match ($interval) {
            'day' => $end->subDays($count),
            'week' => $end->subWeeks($count),
            'year' => $end->subYears($count),
            default => $end->subMonths($count),
        };
    }

    /**
     * The renewal amount (cents) for a given price id, at the current quantity.
     */
    public function amountForPrice(string $priceId): int
    {
        $plan = Plan::where('chip_price_id', $priceId)->first() ?? Plan::find($priceId);

        if ($plan) {
            return (int) round(((float) $plan->price) * 100) * max(1, (int) ($this->quantity ?? 1));
        }

        $price = Price::find($priceId);

        return $price ? $price->amount() : 0;
    }

    /**
     * The current billing period boundaries.
     *
     * Prefer the persisted columns (authoritative); fall back to the derived
     * renews_at values for legacy rows.
     */
    public function periodStart(): ?Carbon
    {
        return $this->currentPeriodStart();
    }

    public function periodEnd(): ?Carbon
    {
        return $this->current_period_end ?? $this->renews_at;
    }

    /**
     * Opt-in convenience over Proration::calculate() using this subscription's
     * current period and amounts. Returns cents to settle for switching to
     * $newPriceId now (> 0 owed, < 0 credit). It computes only — it does NOT
     * charge, credit, or change the plan. You decide what to do with the number.
     */
    public function prorationFor(string $newPriceId, ?\DateTimeInterface $now = null): int
    {
        $start = $this->currentPeriodStart();

        if (! $start || ! $this->renews_at) {
            return 0;
        }

        return Proration::calculate(
            $this->amount(),
            $this->amountForPrice($newPriceId),
            $start,
            $this->renews_at,
            $now
        );
    }

    /**
     * Scope to token-based subscriptions whose renewal is due and still billable.
     *
     * Cancelled subscriptions (ends_at set) are excluded — cancelling stops
     * future charges while access runs to the period end.
     */
    public function scopeDueForRenewal($query, ?Carbon $now = null)
    {
        $now = $now ?? Carbon::now();

        return $query->whereNull('ends_at')
            ->whereNotNull('renews_at')
            ->where('renews_at', '<=', $now)
            ->whereIn('chip_status', ['active', 'past_due', 'trialing', 'requires_payment_method']);
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
     * Determine if the subscription is for the given price.
     */
    public function hasPrice(string $priceId): bool
    {
        return $this->chip_price_id === $priceId;
    }

    /**
     * Determine if the subscription is for the given product.
     *
     * Chip has no separate product layer — a plan/price is the unit of billing —
     * so this matches on the plan/price id.
     */
    public function hasProduct(string $productId): bool
    {
        return $this->hasPrice($productId);
    }

    /**
     * Determine if the subscription is past due.
     */
    public function pastDue(): bool
    {
        return $this->chip_status === 'past_due';
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
     * Cancel the subscription at a specific moment in time.
     */
    public function cancelAt(\DateTimeInterface $date): self
    {
        if ($this->hasChipId()) {
            try {
                $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
                $api->cancelSubscription($this->chip_id, [
                    'effective_from' => Carbon::instance($date)->toIso8601String(),
                ]);
            } catch (\Exception $e) {
                \Log::warning('Failed to schedule cancellation via Chip API: ' . $e->getMessage());
            }
        }

        $this->fill(['ends_at' => Carbon::instance($date)])->save();

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
     * Swap the subscription to a new price/plan.
     */
    public function swap($priceId, array $options = []): self
    {
        // Primitive: schedule the change for the next renewal. The current plan
        // stays in effect until renews_at, then cashier:renew switches
        // chip_price_id to the pending plan. No proration, no charge — that's a
        // separate opt-in decision (see prorationFor() / swapAndInvoice()).
        $attributes = ['pending_plan_id' => $priceId];

        if (isset($options['quantity'])) {
            $attributes['quantity'] = $options['quantity'];
        }

        $this->fill($attributes)->save();

        event(new \Aizuddinmanap\CashierChip\Events\SubscriptionUpdated($this));

        return $this;
    }

    /**
     * Immediately switch the plan and charge for it now.
     *
     * Changes chip_price_id at once (clearing any pending change) and charges the
     * saved token. By default it charges the new plan's full amount; pass
     * ['amount' => n] — e.g. the result of prorationFor() — to charge a specific
     * figure instead. Pass ['prorate' => true] to auto-bank a downgrade's unused
     * value as credit_balance (cashier:renew spends it at the next renewal)
     * instead of charging nothing; an explicit 'amount' always wins. The
     * billing anchor (renews_at) is left unchanged.
     */
    public function swapAndInvoice($priceId, array $options = []): self
    {
        // Auto-bank downgrade credit before switching — prorationFor() reads the
        // CURRENT plan's period/amount, so it must run before fill+save.
        $bankedCredit = false;

        if (($options['prorate'] ?? false) && ! isset($options['amount'])) {
            $proration = $this->prorationFor($priceId);

            if ($proration < 0) {
                $this->addCredit(abs($proration));
                $bankedCredit = true;
            }
        }

        $attributes = ['chip_price_id' => $priceId, 'pending_plan_id' => null];

        if (isset($options['quantity'])) {
            $attributes['quantity'] = $options['quantity'];
        }

        $this->fill($attributes)->save();

        event(new \Aizuddinmanap\CashierChip\Events\SubscriptionUpdated($this));

        // A downgrade that banked credit charges nothing now — the unused value
        // covers the new (cheaper) plan's cycle at the next renewal.
        if ($bankedCredit) {
            return $this;
        }

        $owner = $this->owner()->first();
        $amount = (int) ($options['amount'] ?? $this->amount());

        if ($owner && $amount > 0) {
            $owner->chargeWithToken(
                $amount,
                $options['description'] ?? ('Plan change: ' . $this->name),
                [
                    'reference' => $this->id,
                    'currency' => $this->currency(),
                ]
            );
        }

        return $this;
    }

    /**
     * Charge a renewal payment for this subscription using the saved recurring token.
     *
     * Creates a new purchase and charges it with the owner's stored payment method.
     *
     * @param  array  $options  Additional options for the charge
     * @return \Aizuddinmanap\CashierChip\Transaction
     */
    public function renew(array $options = []): \Aizuddinmanap\CashierChip\Transaction
    {
        $owner = $this->owner()->first();

        if (! $owner) {
            throw new \Exception('Subscription has no owner.');
        }

        // Allow the caller (cashier:renew) to override the amount, e.g. after
        // applying a proration credit/debit from balance.
        $amount = (int) ($options['amount'] ?? $this->amount());

        if ($amount <= 0) {
            throw new \Exception('Subscription has no billable amount.');
        }

        $description = $options['description'] ?? 'Subscription Renewal: ' . $this->name;

        return $owner->chargeWithToken($amount, $description, array_merge([
            'reference' => $this->id,
            'currency' => $this->currency(),
        ], $options));
    }

    /**
     * Bank proration credit from a downgrade (cents). Atomic increment — safe
     * against a concurrent cashier:renew run reading credit_balance.
     */
    public function addCredit(int $cents): void
    {
        if ($cents <= 0) {
            return;
        }

        $this->increment('credit_balance', $cents);
        $this->refresh();
    }

    /**
     * Current credit balance (cents).
     */
    public function creditBalance(): int
    {
        return (int) $this->credit_balance;
    }

    /**
     * Record a credit-only renewal (no gateway call) when credit_balance covers
     * the full cycle. The ledger stays complete; cashier:renew still advances
     * the schedule and dispatches SubscriptionRenewed.
     */
    public function recordCreditOnlyRenewal(int $amount): \Aizuddinmanap\CashierChip\Transaction
    {
        $owner = $this->owner()->first();

        if (! $owner) {
            throw new \Exception('Subscription has no owner.');
        }

        return $owner->transactions()->create([
            'id' => 'txn_' . uniqid(),
            'chip_id' => null,
            'total' => $amount,
            'currency' => $this->currency(),
            'status' => 'success',
            'type' => 'credit',
            'description' => 'Subscription Renewal (credit): ' . $this->name,
            'payment_method' => 'credit_balance',
            'processed_at' => now(),
            'metadata' => [
                'credit_applied' => $amount,
                'renewal' => true,
            ],
        ]);
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
     * Get the total amount for the subscription (in cents) multiplied by quantity.
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

        // Use Plan model if available (preferred — has real pricing data)
        $plan = $this->plan();
        if ($plan) {
            return (int) round(((float) $plan->price) * 100) * max(1, (int) ($this->quantity ?? 1));
        }

        // Fallback to Price abstraction
        $price = $this->price();
        return $price ? $price->amount() : 0;
    }

    /**
     * Get the currency for the subscription.
     */
    public function currency(): string
    {
        $plan = $this->plan();
        if ($plan && $plan->currency) {
            return $plan->currency;
        }

        $price = $this->price();
        return $price ? $price->currency() : config('cashier.currency', 'MYR');
    }
} 