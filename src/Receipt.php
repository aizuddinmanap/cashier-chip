<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Money\Currency;
use Money\Money;

class Receipt extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'receipts';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     */
    protected $keyType = 'string';

    /**
     * The attributes that are not mass assignable.
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'paid_at' => 'datetime',
        'total' => 'integer',
        'subtotal' => 'integer',
        'tax' => 'integer',
    ];

    /**
     * Get the billable entity that the receipt belongs to.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the total amount for the receipt.
     */
    public function amount(): string
    {
        return Cashier::formatAmount($this->total, $this->currency);
    }

    /**
     * Get the subtotal amount for the receipt.
     */
    public function subtotal(): string
    {
        return Cashier::formatAmount($this->subtotal, $this->currency);
    }

    /**
     * Get the tax amount for the receipt.
     */
    public function tax(): string
    {
        return Cashier::formatAmount($this->tax ?? 0, $this->currency);
    }

    /**
     * Get the raw total for the receipt.
     */
    public function rawTotal(): int
    {
        return $this->total;
    }

    /**
     * Get the raw subtotal for the receipt.
     */
    public function rawSubtotal(): int
    {
        return $this->subtotal;
    }

    /**
     * Get the raw tax for the receipt.
     */
    public function rawTax(): int
    {
        return $this->tax ?? 0;
    }

    /**
     * Get the used currency for the receipt.
     */
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Get the Money instance for the total.
     */
    public function asMoney(): Money
    {
        return new Money($this->total, new Currency(strtoupper($this->currency)));
    }

    /**
     * Determine if the receipt is for a subscription.
     */
    public function isSubscription(): bool
    {
        return ! is_null($this->subscription_id);
    }
} 