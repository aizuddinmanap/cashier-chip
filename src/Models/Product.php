<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Models;

class Product
{
    /**
     * Product name.
     */
    public ?string $name = null;

    /**
     * Product price in cents.
     */
    public ?int $price = null;

    /**
     * Product quantity.
     */
    public int $quantity = 1;

    /**
     * Product description.
     */
    public ?string $description = null;

    /**
     * Whether this is a subscription product.
     */
    public bool $is_subscription = false;

    /**
     * Subscription interval (for subscription products).
     */
    public ?string $subscription_interval = null;

    /**
     * Create a new Product instance.
     */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * Convert the product to an array.
     */
    public function toArray(): array
    {
        $data = [];
        
        foreach (get_object_vars($this) as $key => $value) {
            if ($value !== null && ($key !== 'is_subscription' || $value === true)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }
} 