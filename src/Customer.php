<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

class Customer
{
    /**
     * The customer attributes.
     */
    protected array $attributes = [];

    /**
     * Create a new customer instance.
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Get an attribute from the customer.
     */
    public function __get(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Set an attribute on the customer.
     */
    public function __set(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get the customer's Chip ID.
     */
    public function id(): ?string
    {
        return $this->chip_id;
    }

    /**
     * Get the customer's email.
     */
    public function email(): ?string
    {
        return $this->email;
    }

    /**
     * Get the customer's name.
     */
    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * Convert the customer to an array.
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
} 