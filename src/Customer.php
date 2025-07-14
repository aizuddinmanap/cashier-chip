<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Customer extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'customers';

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
        'trial_ends_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the billable entity that owns the customer.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get all subscriptions for the customer.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Cashier::subscriptionModel(), 'customer_id')->orderByDesc('created_at');
    }

    /**
     * Get all transactions for the customer.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Cashier::transactionModel(), 'customer_id')->orderByDesc('created_at');
    }

    /**
     * Get the customer's Chip ID.
     */
    public function chipId(): ?string
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
     * Get the customer's phone number.
     */
    public function phone(): ?string
    {
        return $this->phone;
    }

    /**
     * Get the customer's address.
     */
    public function address(): ?array
    {
        return $this->address ? json_decode($this->address, true) : null;
    }

    /**
     * Set the customer's address.
     */
    public function setAddressAttribute($value): void
    {
        $this->attributes['address'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Determine if the customer has a specific subscription.
     */
    public function hasSubscription(string $name = 'default'): bool
    {
        return $this->subscriptions()->where('name', $name)->where('ends_at', null)->exists();
    }

    /**
     * Get a subscription by name.
     */
    public function subscription(string $name = 'default'): ?Subscription
    {
        return $this->subscriptions()->where('name', $name)->first();
    }

    /**
     * Determine if the customer is subscribed to any plan.
     */
    public function subscribed(): bool
    {
        return $this->subscriptions()->where('ends_at', null)->exists();
    }

    /**
     * Determine if the customer is on trial.
     */
    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Determine if the customer's trial has ended.
     */
    public function hasExpiredTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    /**
     * Get the customer's trial end date.
     */
    public function trialEndsAt(): ?\DateTimeInterface
    {
        return $this->trial_ends_at;
    }

    /**
     * Create a new subscription for the customer.
     */
    public function newSubscription(string $name, string $priceId): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this->billable, $name, $priceId);
    }

    /**
     * Sync the customer with Chip.
     */
    public function syncWithChip(): self
    {
        // Implementation would sync customer data with Chip API
        // This is a placeholder for the actual implementation
        return $this;
    }

    /**
     * Update the customer in Chip.
     */
    public function updateChipCustomer(array $options = []): self
    {
        // Implementation would update customer in Chip API
        // This is a placeholder for the actual implementation
        return $this;
    }

    /**
     * Delete the customer from Chip.
     */
    public function deleteChipCustomer(): bool
    {
        // Implementation would delete customer from Chip API
        // This is a placeholder for the actual implementation
        return true;
    }

    /**
     * Convert the customer to an array.
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Include computed attributes
        $array['on_trial'] = $this->onTrial();
        $array['subscribed'] = $this->subscribed();
        $array['has_expired_trial'] = $this->hasExpiredTrial();
        
        return $array;
    }
} 