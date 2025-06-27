<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Models;

class PurchaseDetails
{
    /**
     * Currency code (e.g., MYR).
     */
    public string $currency = 'MYR';

    /**
     * Array of products.
     *
     * @var Product[]
     */
    public array $products = [];

    /**
     * Purchase metadata.
     */
    public ?array $metadata = null;

    /**
     * Purchase notes.
     */
    public ?string $notes = null;

    /**
     * Create a new PurchaseDetails instance.
     */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                if ($key === 'products' && is_array($value)) {
                    $this->products = array_map(function ($product) {
                        return $product instanceof Product ? $product : new Product($product);
                    }, $value);
                } else {
                    $this->{$key} = $value;
                }
            }
        }
    }

    /**
     * Add a product to the purchase.
     */
    public function addProduct(Product $product): self
    {
        $this->products[] = $product;

        return $this;
    }

    /**
     * Convert the purchase details to an array.
     */
    public function toArray(): array
    {
        $data = [
            'currency' => $this->currency,
            'products' => array_map(fn($product) => $product->toArray(), $this->products),
        ];

        if ($this->metadata !== null) {
            $data['metadata'] = $this->metadata;
        }

        if ($this->notes !== null) {
            $data['notes'] = $this->notes;
        }

        return $data;
    }
} 