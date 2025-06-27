<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

class Price
{
    protected array $attributes = [];

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public function __get(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    public static function find(string $id): ?self
    {
        // Implementation placeholder
        return new self(['id' => $id]);
    }

    public function amount(): int
    {
        return $this->attributes['amount'] ?? 0;
    }

    public function currency(): string
    {
        return $this->attributes['currency'] ?? 'myr';
    }
} 