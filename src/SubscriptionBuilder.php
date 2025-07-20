<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Aizuddinmanap\CashierChip\Models\Plan;

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
     * The plan instance.
     */
    protected ?Plan $plan = null;

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
        
        // Try to load the plan if it exists locally
        $this->plan = $this->findPlan($priceId);
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
        // Merge builder options with method options
        $options = array_merge($this->options, $options);

        // Check if this is a trial-only subscription
        $isTrialOnly = $this->trialEnds && ! $this->skipTrial;

        try {
            if ($isTrialOnly) {
                // Trial subscriptions are local-only (no API call needed)
                return $this->createTrialSubscription($options);
            }

            // For paid subscriptions, ensure the billable model has a Chip customer
            if (! $this->billable->hasChipId()) {
                $this->billable->createAsChipCustomer();
            }

            // Create subscription via Chip API
            $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
            
            $subscriptionData = [
                'customer_id' => $this->billable->chipId(),
                'price_id' => $this->priceId,
                'quantity' => $this->quantity,
                'metadata' => $this->metadata,
            ];

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
     * Create a trial-only subscription (local database only, no API call).
     */
    protected function createTrialSubscription(array $options = []): Subscription
    {
        // Create local subscription record for trial
        return $this->billable->subscriptions()->create([
            'name' => $this->name,
            'chip_id' => 'trial_' . uniqid(), // Generate local trial ID
            'chip_status' => 'trialing',
            'chip_price_id' => $this->priceId,
            'quantity' => $this->quantity,
            'trial_ends_at' => $this->trialEnds,
            'ends_at' => null,
        ]);
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

    /**
     * Get the plan instance.
     */
    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    /**
     * Find a plan by price ID.
     */
    protected function findPlan(string $priceId): ?Plan
    {
        // First try to find by plan ID
        $plan = Plan::find($priceId);
        
        // If not found, try to find by chip_price_id
        if (!$plan) {
            $plan = Plan::where('chip_price_id', $priceId)->first();
        }
        
        return $plan;
    }

    /**
     * Create a subscription builder for a plan.
     */
    public static function forPlan(Model $billable, string $name, Plan $plan): self
    {
        $builder = new static($billable, $name, $plan->chip_price_id);
        $builder->plan = $plan;
        
        return $builder;
    }
} 