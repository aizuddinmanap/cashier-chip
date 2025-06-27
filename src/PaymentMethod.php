<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

class PaymentMethod
{
    /**
     * The payment method attributes.
     */
    protected array $attributes = [];

    /**
     * Create a new payment method instance.
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Get an attribute from the payment method.
     */
    public function __get(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Get the payment method's ID.
     */
    public function id(): ?string
    {
        return $this->id ?? $this->chip_id;
    }

    /**
     * Get the payment method's type.
     */
    public function type(): string
    {
        return $this->attributes['type'] ?? 'card';
    }

    /**
     * Get the last four digits of the payment method.
     */
    public function lastFour(): ?string
    {
        return $this->attributes['last_four'] ?? null;
    }

    /**
     * Get the brand of the payment method.
     */
    public function brand(): ?string
    {
        return $this->attributes['brand'] ?? null;
    }

    /**
     * Determine if the payment method is the default.
     */
    public function isDefault(): bool
    {
        return $this->attributes['is_default'] ?? false;
    }

    /**
     * Delete the payment method.
     */
    public function delete(): bool
    {
        // TODO: Implement payment method deletion via Chip API
        return true;
    }

    /**
     * Convert the payment method to an array.
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
} 