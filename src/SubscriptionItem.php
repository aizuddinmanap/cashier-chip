<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionItem extends Model
{
    /**
     * The attributes that are not mass assignable.
     */
    protected $guarded = [];

    /**
     * Get the subscription that the item belongs to.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Update the quantity of the subscription item.
     */
    public function updateQuantity(int $quantity): self
    {
        // Implementation would make API call to Chip to update quantity
        $this->fill(['quantity' => $quantity])->save();

        return $this;
    }

    /**
     * Increment the quantity of the subscription item.
     */
    public function incrementQuantity(int $count = 1): self
    {
        return $this->updateQuantity($this->quantity + $count);
    }

    /**
     * Decrement the quantity of the subscription item.
     */
    public function decrementQuantity(int $count = 1): self
    {
        return $this->updateQuantity(max(1, $this->quantity - $count));
    }

    /**
     * Get the price associated with the subscription item.
     */
    public function price(): ?Price
    {
        return Price::find($this->chip_price_id);
    }
} 