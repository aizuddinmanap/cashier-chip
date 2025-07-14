<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Money\Currency;
use Money\Money;

class Transaction extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'transactions';

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
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the billable entity that the transaction belongs to.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the customer that the transaction belongs to.
     */
    public function customer()
    {
        return $this->belongsTo(Cashier::customerModel(), 'customer_id');
    }

    /**
     * Get the formatted amount for the transaction.
     */
    public function amount(): string
    {
        return Cashier::formatAmount($this->amount, $this->currency);
    }

    /**
     * Get the raw amount for the transaction.
     */
    public function rawAmount(): int
    {
        return $this->amount;
    }

    /**
     * Get the used currency for the transaction.
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
     * Determine if the transaction was successful.
     */
    public function successful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Determine if the transaction has failed.
     */
    public function failed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Determine if the transaction is pending.
     */
    public function pending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Determine if the transaction was refunded.
     */
    public function refunded(): bool
    {
        return $this->status === 'refunded';
    }

    /**
     * Get the Chip transaction ID.
     */
    public function chipId(): ?string
    {
        return $this->chip_id;
    }

    /**
     * Get the transaction type (charge, refund, etc.).
     */
    public function type(): string
    {
        return $this->type ?? 'charge';
    }

    /**
     * Determine if this is a charge transaction.
     */
    public function isCharge(): bool
    {
        return $this->type() === 'charge';
    }

    /**
     * Determine if this is a refund transaction.
     */
    public function isRefund(): bool
    {
        return $this->type() === 'refund';
    }

    /**
     * Get the payment method used for the transaction.
     */
    public function paymentMethod(): ?string
    {
        return $this->payment_method;
    }

    /**
     * Get transaction metadata.
     */
    public function metadata(): array
    {
        return $this->metadata ? json_decode($this->metadata, true) : [];
    }

    /**
     * Set transaction metadata.
     */
    public function setMetadataAttribute($value): void
    {
        $this->attributes['metadata'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Scope to filter successful transactions.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope to filter failed transactions.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to filter pending transactions.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to filter refunded transactions.
     */
    public function scopeRefunded($query)
    {
        return $query->where('status', 'refunded');
    }

    /**
     * Scope to filter by transaction type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter charges.
     */
    public function scopeCharges($query)
    {
        return $query->where('type', 'charge');
    }

    /**
     * Scope to filter refunds.
     */
    public function scopeRefunds($query)
    {
        return $query->where('type', 'refund');
    }
} 