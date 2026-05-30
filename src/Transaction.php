<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Aizuddinmanap\CashierChip\Http\ChipApi;
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
     * Determine if the transaction is authorized (skip_capture) awaiting capture.
     */
    public function preauthorized(): bool
    {
        return $this->status === 'preauthorized';
    }

    /**
     * Determine if the transaction is on hold awaiting capture.
     */
    public function onHold(): bool
    {
        return $this->status === 'on_hold';
    }

    /**
     * Determine if the transaction was voided (authorization released).
     */
    public function voided(): bool
    {
        return $this->status === 'voided';
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
        // Only filter by type if the column exists (for backward compatibility)
        if ($this->hasTypeColumnStatic()) {
            return $query->where('type', 'charge');
        }
        
        // If no type column, return all transactions (assuming they're charges for compatibility)
        return $query;
    }

    /**
     * Static method to check if the transactions table has a 'type' column.
     */
    protected static function hasTypeColumnStatic(): bool
    {
        static $hasTypeColumn = null;
        
        if ($hasTypeColumn === null) {
            try {
                $schema = \Illuminate\Support\Facades\Schema::getConnection()->getSchemaBuilder();
                $hasTypeColumn = $schema->hasColumn('transactions', 'type');
            } catch (\Exception $e) {
                // If we can't check the schema, assume the column doesn't exist for safety
                $hasTypeColumn = false;
            }
        }
        
        return $hasTypeColumn;
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

    /**
     * Map a Chip purchase "status" to an internal transaction status.
     *
     * Returns null for transient states we don't act on (created / sent /
     * viewed / pending_execute), so a reconcile pass leaves them untouched.
     */
    public static function mapChipStatus(string $chipStatus): ?string
    {
        return match ($chipStatus) {
            'paid' => 'success',
            'preauthorized' => 'preauthorized',
            'hold' => 'on_hold',
            'pending_charge' => 'pending_charge',
            'refunded' => 'refunded',
            'error', 'blocked', 'cancelled', 'expired', 'overdue' => 'failed',
            default => null,
        };
    }

    /**
     * Determine if this transaction can be captured.
     *
     * Only an authorized (skip_capture) or held payment may be captured.
     */
    public function capturable(): bool
    {
        return in_array($this->status, ['preauthorized', 'on_hold'], true);
    }

    /**
     * Capture a previously authorized (skip_capture) or held payment.
     *
     * @param  int|null  $amount  Amount in cents to capture; null captures the full amount.
     */
    public function capture(?int $amount = null): self
    {
        if (! $this->capturable()) {
            throw new \Exception("Transaction {$this->id} is not in a capturable state ({$this->status}).");
        }

        if (! $this->chip_id) {
            throw new \Exception("Transaction {$this->id} has no Chip purchase ID to capture.");
        }

        $data = [];
        if ($amount !== null) {
            $data['amount'] = $amount;
        }

        $response = (new ChipApi())->capturePurchase($this->chip_id, $data);

        if (! is_array($response) || ($response['status'] ?? null) !== 'paid') {
            throw new \Exception("Chip did not confirm capture for transaction {$this->id}.");
        }

        $this->update([
            'status' => 'success',
            'payment_method' => $response['transaction_data']['payment_method'] ?? $this->payment_method,
            'processed_at' => now(),
        ]);

        return $this;
    }

    /**
     * Determine if this transaction can be voided (its authorization released).
     */
    public function voidable(): bool
    {
        return in_array($this->status, ['preauthorized', 'on_hold'], true);
    }

    /**
     * Void (release) a previously authorized or held payment without capturing it.
     */
    public function void(): self
    {
        if (! $this->voidable()) {
            throw new \Exception("Transaction {$this->id} is not in a voidable state ({$this->status}).");
        }

        if (! $this->chip_id) {
            throw new \Exception("Transaction {$this->id} has no Chip purchase ID to void.");
        }

        $response = (new ChipApi())->releasePurchase($this->chip_id);

        if (! is_array($response) || ($response['status'] ?? null) !== 'released') {
            throw new \Exception("Chip did not confirm release for transaction {$this->id}.");
        }

        $this->update(['status' => 'voided']);

        return $this;
    }
}