<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Money\Currency;
use Money\Money;

class Payment extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'payments';

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
        'amount' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the billable entity that the payment belongs to.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the formatted amount for the payment.
     */
    public function amount(): string
    {
        return Cashier::formatAmount($this->amount, $this->currency);
    }

    /**
     * Get the raw amount for the payment.
     */
    public function rawAmount(): int
    {
        return $this->amount;
    }

    /**
     * Get the used currency for the payment.
     */
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Get the Money instance for the amount.
     */
    public function asMoney(): Money
    {
        return new Money($this->amount, new Currency(strtoupper($this->currency)));
    }

    /**
     * Determine if the payment was successful.
     */
    public function successful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Determine if the payment has failed.
     */
    public function failed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Determine if the payment is pending.
     */
    public function pending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Determine if the payment was refunded.
     */
    public function refunded(): bool
    {
        return $this->status === 'refunded';
    }
} 