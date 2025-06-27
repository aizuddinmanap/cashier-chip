<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

class Invoice
{
    /**
     * The invoice attributes.
     */
    protected array $attributes = [];

    /**
     * Create a new invoice instance.
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Get an attribute from the invoice.
     */
    public function __get(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Get the invoice's ID.
     */
    public function id(): ?string
    {
        return $this->id ?? $this->chip_id;
    }

    /**
     * Get the invoice's total amount.
     */
    public function total(): string
    {
        return Cashier::formatAmount($this->attributes['total'] ?? 0, $this->attributes['currency'] ?? 'myr');
    }

    /**
     * Get the raw total amount.
     */
    public function rawTotal(): int
    {
        return $this->attributes['total'] ?? 0;
    }

    /**
     * Get the invoice's status.
     */
    public function status(): string
    {
        return $this->attributes['status'] ?? 'pending';
    }

    /**
     * Determine if the invoice is paid.
     */
    public function paid(): bool
    {
        return $this->status() === 'paid';
    }

    /**
     * Get the invoice's due date.
     */
    public function dueDate(): ?\DateTimeInterface
    {
        return $this->attributes['due_date'] ?? null;
    }

    /**
     * Convert the invoice to an array.
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
} 