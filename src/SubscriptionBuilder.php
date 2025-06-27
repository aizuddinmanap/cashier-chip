<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class SubscriptionBuilder
{
    /**
     * The model that is subscribing.
     */
    protected Model $billable;

    /**
     * The name of the subscription.
     */
    protected string $name;

    /**
     * The plan to subscribe to.
     */
    protected string $plan;

    /**
     * The quantity of the subscription.
     */
    protected int $quantity = 1;

    /**
     * The trial end date for the subscription.
     */
    protected ?\DateTimeInterface $trialEnd = null;

    /**
     * Indicates that the trial should end immediately.
     */
    protected bool $skipTrial = false;

    /**
     * The coupon code being applied to the customer.
     */
    protected ?string $coupon = null;

    /**
     * Metadata to apply to the subscription.
     */
    protected array $metadata = [];

    /**
     * Create a new subscription builder instance.
     */
    public function __construct(Model $billable, string $name, string $plan)
    {
        $this->billable = $billable;
        $this->name = $name;
        $this->plan = $plan;
    }

    /**
     * Set the quantity of the subscription.
     */
    public function quantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * Specify the number of days of the trial.
     */
    public function trialDays(int $trialDays): self
    {
        $this->trialEnd = Carbon::now()->addDays($trialDays);

        return $this;
    }

    /**
     * Specify the ending date of the trial.
     */
    public function trialUntil(\DateTimeInterface $trialEnd): self
    {
        $this->trialEnd = $trialEnd;

        return $this;
    }

    /**
     * Force the trial to end immediately.
     */
    public function skipTrial(): self
    {
        $this->skipTrial = true;

        return $this;
    }

    /**
     * The coupon to apply to a new subscription.
     */
    public function withCoupon(string $coupon): self
    {
        $this->coupon = $coupon;

        return $this;
    }

    /**
     * Add metadata to the subscription.
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Create the subscription.
     */
    public function create(array $options = []): Subscription
    {
        // Ensure the billable model has a Chip customer ID
        if (! $this->billable->hasChipId()) {
            $this->billable->createAsChipCustomer($options);
        }

        // Calculate trial end date
        $trialEnd = $this->skipTrial ? null : $this->trialEnd;

        // Create the subscription record
        $subscription = $this->billable->subscriptions()->create([
            'name' => $this->name,
            'chip_id' => 'sub_' . uniqid(), // Will be replaced with actual Chip ID from API
            'chip_status' => 'active',
            'chip_price_id' => $this->plan,
            'quantity' => $this->quantity,
            'trial_ends_at' => $trialEnd,
        ]);

        // Create subscription items
        $subscription->items()->create([
            'chip_id' => 'si_' . uniqid(), // Will be replaced with actual Chip ID from API
            'chip_product_id' => $this->plan, // Assuming plan is product for now
            'chip_price_id' => $this->plan,
            'quantity' => $this->quantity,
        ]);

        // TODO: Make actual API call to Chip to create the subscription
        // This would involve calling the Chip API with the subscription details

        return $subscription;
    }

    /**
     * Create the subscription and return the checkout URL.
     */
    public function checkout(array $options = []): array
    {
        $subscription = $this->create($options);

        // TODO: Create checkout session with Chip API
        // This would return the checkout URL from Chip

        return [
            'subscription' => $subscription,
            'checkout_url' => 'https://gate.chip-in.asia/payment/checkout_url_placeholder',
        ];
    }
} 