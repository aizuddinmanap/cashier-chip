<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Money\Currency;
use Money\Money;

class Transaction extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
        'total' => 'integer',
    ];

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function amount(): string
    {
        return Cashier::formatAmount($this->total, $this->currency);
    }

    public function rawTotal(): int
    {
        return $this->total;
    }

    public function successful(): bool
    {
        return $this->status === 'success';
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }

    public function pending(): bool
    {
        return $this->status === 'pending';
    }

    public function asMoney(): Money
    {
        return new Money($this->total, new Currency(strtoupper($this->currency)));
    }
} 