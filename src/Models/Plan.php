<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Models;

use Aizuddinmanap\CashierChip\Subscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Plan extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'plans';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The "type" of the auto-incrementing ID.
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'chip_price_id',
        'name',
        'description',
        'price',
        'currency',
        'interval',
        'interval_count',
        'features',
        'active',
        'sort_order',
        'stripe_price_id',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'price' => 'decimal:2',
        'features' => 'array',
        'active' => 'boolean',
        'interval_count' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Get the subscriptions for this plan.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'chip_price_id', 'chip_price_id');
    }

    /**
     * Scope a query to only include active plans.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }


    /**
     * Scope a query to order plans by sort_order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Scope a query to filter by interval.
     */
    public function scopeInterval(Builder $query, string $interval): Builder
    {
        return $query->where('interval', $interval);
    }

    /**
     * Get the display price attribute.
     */
    public function getDisplayPriceAttribute(): string
    {
        $symbol = $this->getCurrencySymbol();
        return $symbol . number_format($this->price, 2);
    }

    /**
     * Get the formatted interval attribute.
     */
    public function getFormattedIntervalAttribute(): string
    {
        if ($this->interval_count === 1) {
            return $this->interval;
        }

        return $this->interval_count . ' ' . str_plural($this->interval);
    }

    /**
     * Check if the plan is active.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Check if the plan is monthly.
     */
    public function isMonthly(): bool
    {
        return $this->interval === 'month' && $this->interval_count === 1;
    }

    /**
     * Check if the plan is yearly.
     */
    public function isYearly(): bool
    {
        return $this->interval === 'year' && $this->interval_count === 1;
    }

    /**
     * Get the price per month for comparison.
     */
    public function getPricePerMonthAttribute(): float
    {
        if ($this->interval === 'month') {
            return $this->price / $this->interval_count;
        }

        if ($this->interval === 'year') {
            return $this->price / ($this->interval_count * 12);
        }

        return $this->price;
    }

    /**
     * Get currency symbol.
     */
    protected function getCurrencySymbol(): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'MYR' => 'RM ',
            'SGD' => 'S$',
        ];

        return $symbols[$this->currency] ?? $this->currency . ' ';
    }

    /**
     * Get features as a formatted list.
     */
    public function getFeaturesList(): array
    {
        return $this->features ?? [];
    }

    /**
     * Check if plan has a specific feature.
     */
    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->getFeaturesList());
    }

    /**
     * Get plans by currency.
     */
    public static function byCurrency(string $currency): Builder
    {
        return static::where('currency', $currency);
    }

    /**
     * Get the cheapest plan.
     */
    public static function cheapest(): ?self
    {
        return static::active()->orderBy('price')->first();
    }

    /**
     * Get the most expensive plan.
     */
    public static function mostExpensive(): ?self
    {
        return static::active()->orderByDesc('price')->first();
    }
}