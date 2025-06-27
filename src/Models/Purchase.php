<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Models;

class Purchase
{
    /**
     * Client information.
     */
    public ?ClientDetails $client = null;

    /**
     * Purchase details.
     */
    public ?PurchaseDetails $purchase = null;

    /**
     * Brand ID.
     */
    public ?string $brand_id = null;

    /**
     * Success redirect URL.
     */
    public ?string $success_redirect = null;

    /**
     * Failure redirect URL.
     */
    public ?string $failure_redirect = null;

    /**
     * Success callback URL.
     */
    public ?string $success_callback = null;

    /**
     * Failure callback URL.
     */
    public ?string $failure_callback = null;

    /**
     * Due date for the purchase.
     */
    public ?string $due = null;

    /**
     * Create a new Purchase instance.
     */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                if ($key === 'client' && is_array($value)) {
                    $this->client = new ClientDetails($value);
                } elseif ($key === 'purchase' && is_array($value)) {
                    $this->purchase = new PurchaseDetails($value);
                } else {
                    $this->{$key} = $value;
                }
            }
        }
    }

    /**
     * Convert the purchase to an array.
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->client !== null) {
            $data['client'] = $this->client->toArray();
        }

        if ($this->purchase !== null) {
            $data['purchase'] = $this->purchase->toArray();
        }

        foreach (['brand_id', 'success_redirect', 'failure_redirect', 'success_callback', 'failure_callback', 'due'] as $field) {
            if ($this->{$field} !== null) {
                $data[$field] = $this->{$field};
            }
        }

        return $data;
    }
} 