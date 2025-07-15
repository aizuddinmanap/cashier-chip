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
        'total' => 'integer',
        'metadata' => 'array',
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
        return Cashier::formatAmount($this->total, $this->currency);
    }

    /**
     * Get the raw amount for the transaction.
     */
    public function rawAmount(): int
    {
        return $this->total;
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
        return new Money($this->total, new Currency(strtoupper($this->currency)));
    }

    /**
     * Determine if the transaction was successful.
     */
    public function successful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Determine if the transaction failed.
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
     * Determine if this is a charge transaction.
     */
    public function isCharge(): bool
    {
        return $this->type === 'charge';
    }

    /**
     * Determine if this is a refund transaction.
     */
    public function isRefund(): bool
    {
        return $this->type === 'refund';
    }

    /**
     * Get the Chip ID for the transaction.
     */
    public function chipId(): ?string
    {
        return $this->chip_id;
    }

    /**
     * Get the payment method used for the transaction.
     */
    public function paymentMethod(): ?string
    {
        return $this->payment_method;
    }

    /**
     * Get the transaction type.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Get the transaction metadata.
     */
    public function metadata(): array
    {
        $metadata = $this->attributes['metadata'] ?? [];
        
        // Handle case where metadata might still be a JSON string
        if (is_string($metadata)) {
            return json_decode($metadata, true) ?: [];
        }
        
        return is_array($metadata) ? $metadata : [];
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
     * Scope to filter charge transactions.
     */
    public function scopeCharges($query)
    {
        return $query->where('type', 'charge');
    }

    /**
     * Scope to filter refund transactions.
     */
    public function scopeRefunds($query)
    {
        return $query->where('type', 'refund');
    }

    /**
     * Scope to filter transactions by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter transactions by payment method.
     */
    public function scopeByPaymentMethod($query, string $paymentMethod)
    {
        return $query->where('payment_method', $paymentMethod);
    }

    /**
     * Scope to filter transactions by date range.
     */
    public function scopeForPeriod($query, \Carbon\Carbon $startDate, \Carbon\Carbon $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope to filter transactions by minimum amount.
     */
    public function scopeMinAmount($query, int $amount)
    {
        return $query->where('total', '>=', $amount);
    }

    /**
     * Scope to filter transactions by maximum amount.
     */
    public function scopeMaxAmount($query, int $amount)
    {
        return $query->where('total', '<=', $amount);
    }

    /**
     * Scope to filter transactions by currency.
     */
    public function scopeByCurrency($query, string $currency)
    {
        return $query->where('currency', strtoupper($currency));
    }

    /**
     * Get the original transaction for refunds.
     */
    public function originalTransaction(): ?Transaction
    {
        if (!$this->refunded_from) {
            return null;
        }

        return static::find($this->refunded_from);
    }

    /**
     * Get all refunds for this transaction.
     */
    public function refunds()
    {
        return $this->hasMany(static::class, 'refunded_from', 'id');
    }

    /**
     * Get the total amount refunded for this transaction.
     */
    public function totalRefunded(): int
    {
        return $this->refunds()->sum('total');
    }

    /**
     * Get the remaining refundable amount.
     */
    public function refundableAmount(): int
    {
        return $this->total - $this->totalRefunded();
    }

    /**
     * Determine if the transaction can be refunded.
     */
    public function canBeRefunded(): bool
    {
        return $this->successful() && $this->refundableAmount() > 0;
    }

    /**
     * Create a refund for this transaction.
     */
    public function refund(?int $amount = null): Transaction
    {
        if (!$this->canBeRefunded()) {
            throw new \Exception('Transaction cannot be refunded');
        }

        $refundAmount = $amount ?? $this->refundableAmount();
        
        if ($refundAmount > $this->refundableAmount()) {
            throw new \Exception('Refund amount exceeds refundable amount');
        }

        return static::create([
            'id' => 'txn_' . uniqid(),
            'chip_id' => 'refund_' . uniqid(),
            'customer_id' => $this->customer_id,
            'billable_type' => $this->billable_type,
            'billable_id' => $this->billable_id,
            'type' => 'refund',
            'status' => 'success',
            'currency' => $this->currency,
            'total' => $refundAmount,
            'payment_method' => $this->payment_method,
            'description' => 'Refund for transaction ' . $this->id,
            'refunded_from' => $this->id,
            'processed_at' => now(),
        ]);
    }
} 