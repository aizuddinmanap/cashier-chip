<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class SubscriptionBuilder
{
    /**
     * The billable entity.
     */
    protected Model $billable;

    /**
     * The subscription name.
     */
    protected string $name;

    /**
     * The price ID.
     */
    protected string $priceId;

    /**
     * The quantity of the subscription.
     */
    protected int $quantity = 1;

    /**
     * The trial end date.
     */
    protected ?\DateTimeInterface $trialEnds = null;

    /**
     * Indicates if the trial should end immediately.
     */
    protected bool $skipTrial = false;

    /**
     * The metadata to apply to the subscription.
     */
    protected array $metadata = [];

    /**
     * The subscription options.
     */
    protected array $options = [];

    /**
     * Create a new subscription builder instance.
     */
    public function __construct(Model $billable, string $name, string $priceId)
    {
        $this->billable = $billable;
        $this->name = $name;
        $this->priceId = $priceId;
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
     * Specify the trial period in days.
     */
    public function trialDays(int $days): self
    {
        $this->trialEnds = Carbon::now()->addDays($days);

        return $this;
    }

    /**
     * Specify the trial period until a specific date.
     */
    public function trialUntil(\DateTimeInterface $trialEnds): self
    {
        $this->trialEnds = $trialEnds;

        return $this;
    }

    /**
     * Skip the trial period.
     */
    public function skipTrial(): self
    {
        $this->skipTrial = true;
        $this->trialEnds = null;

        return $this;
    }

    /**
     * Add metadata to the subscription.
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    /**
     * Set subscription options.
     */
    public function withOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    /**
     * Create the subscription.
     */
    public function create(array $options = []): Subscription
    {
        // Ensure the billable model has a Chip customer
        if (! $this->billable->hasChipId()) {
            $this->billable->createAsChipCustomer();
        }

        // Merge builder options with method options
        $options = array_merge($this->options, $options);

        try {
            // Create subscription via Chip API
            $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
            
            $subscriptionData = [
                'customer_id' => $this->billable->chipId(),
                'price_id' => $this->priceId,
                'quantity' => $this->quantity,
                'metadata' => $this->metadata,
            ];

            if ($this->trialEnds && ! $this->skipTrial) {
                $subscriptionData['trial_end'] = $this->trialEnds->getTimestamp();
            }

            $subscriptionData = array_merge($subscriptionData, $options);

            $response = $api->createSubscription($subscriptionData);

            // Create local subscription record
            $subscription = $this->billable->subscriptions()->create([
                'name' => $this->name,
                'chip_id' => $response['id'],
                'chip_status' => $response['status'] ?? 'active',
                'chip_price_id' => $this->priceId,
                'quantity' => $this->quantity,
                'trial_ends_at' => $this->trialEnds,
                'ends_at' => null,
            ]);

            return $subscription;

        } catch (\Exception $e) {
            throw new \Exception("Failed to create subscription: {$e->getMessage()}");
        }
    }

    /**
     * Add the subscription to the billable entity without creating it in Chip.
     */
    public function add(array $options = []): Subscription
    {
        // This method is for adding existing Chip subscriptions to the local database
        return $this->billable->subscriptions()->create([
            'name' => $this->name,
            'chip_id' => $options['chip_id'] ?? 'sub_' . uniqid(),
            'chip_status' => $options['status'] ?? 'active',
            'chip_price_id' => $this->priceId,
            'quantity' => $this->quantity,
            'trial_ends_at' => $this->trialEnds,
            'ends_at' => null,
        ]);
    }

    /**
     * Get the billable entity.
     */
    public function getBillable(): Model
    {
        return $this->billable;
    }

    /**
     * Get the subscription name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the price ID.
     */
    public function getPriceId(): string
    {
        return $this->priceId;
    }

    /**
     * Get the quantity.
     */
    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /**
     * Get the trial end date.
     */
    public function getTrialEnds(): ?\DateTimeInterface
    {
        return $this->trialEnds;
    }

    /**
     * Get the metadata.
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get the options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }
} 