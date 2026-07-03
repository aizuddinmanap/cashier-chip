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
     *
     * Aligned with Laravel Cashier Stripe: create($paymentMethod).
     * When $paymentMethod is provided, it is used as the recurring token.
     * When creating a new subscription without a saved payment method,
     * use checkout() instead to redirect the customer to Chip's checkout page.
     *
     * @param  string|PaymentMethod|null  $paymentMethod  Payment method ID, chip_token_id, or PaymentMethod model
     */
    public function create($paymentMethod = null, array $options = []): Subscription
    {
        $options = array_merge($this->options, $options);

        $isTrialOnly = $this->trialEnds && ! $this->skipTrial;

        // Resolve payment method token
        $token = $this->resolvePaymentMethodToken($paymentMethod);

        // Trial-only with no card: local trial subscription, no API call.
        if ($isTrialOnly && ! $token) {
            return $this->createTrialSubscription($options);
        }

        // Chip has no server-side subscription object. A brand-new paid
        // subscription needs a card first — collect it via checkout(). create()
        // only works once you have a saved payment method to charge renewals.
        if (! $token) {
            throw new \LogicException(
                'Cannot create a paid subscription without a saved payment method. Use '
                . '$user->newSubscription(...)->checkout() to collect a card on Chip\'s '
                . 'hosted page, or pass a PaymentMethod/token to create(). For a '
                . 'Chip-managed subscription, use $user->subscribeToTemplate().'
            );
        }

        // Ensure the billable model has a Chip customer
        if (! $this->billable->hasChipId()) {
            $this->billable->createAsChipCustomer();
        }

        // Token-based subscription: the record is local; renewals charge the
        // saved token (see renew() / chargeWithToken()). Chip has no subscription
        // resource to create remotely.
        $subscription = $this->billable->subscriptions()->create([
            'name' => $this->name,
            'chip_id' => 'sub_' . uniqid(),
            'chip_status' => 'active',
            'chip_price_id' => $this->priceId,
            'quantity' => $this->quantity,
            'trial_ends_at' => $this->trialEnds,
            'ends_at' => null,
        ]);

        // Schedule the first renewal: at trial end if trialing, else one interval
        // out. cashier:renew charges token-based subscriptions when this passes.
        $subscription->renews_at = $subscription->trial_ends_at ?? $subscription->nextRenewalFrom();
        $subscription->save();

        // If a PaymentMethod model was provided, make it the default.
        if ($paymentMethod instanceof PaymentMethod) {
            $this->billable->updateDefaultPaymentMethod($paymentMethod->id);
        }

        return $subscription;
    }

    /**
     * Create a Checkout session for the subscription.
     * Redirects customer to Chip's checkout page with force_recurring enabled.
     *
     * Sets force_recurring, payment_method_whitelist, and skip_capture automatically.
     */
    public function checkout(array $sessionOptions = []): Checkout
    {
        $amount = 0;

        if ($this->plan) {
            $amount = (int) ($this->plan->price * 100);
        }

        $isTrialOnly = $this->trialEnds && ! $this->skipTrial;

        $checkout = Checkout::forSubscription($this->priceId, $this->quantity);

        // Set client info from billable
        $checkout->client(
            $this->billable->email ?? '',
            $this->billable->name ?? null
        );

        // Send the real per-unit price so the Chip receipt shows the correct
        // "Unit Price" line (not MYR 0.00). total_override governs the amount
        // actually charged (and is what trials / overrides adjust).
        if ($amount > 0) {
            $perUnit = (int) round($amount / max(1, $this->quantity));
            $checkout->unitPrice($perUnit);
            $checkout->totalOverride($amount);
        }

        // Free trial = RM0 preauthorization
        if ($isTrialOnly || $amount === 0) {
            $checkout->skipCapture();
            $checkout->unitPrice(0);
            $checkout->totalOverride(0);
        }

        // Set platform
        $checkout->platform(config('cashier.recurring.platform', 'api'));

        // Set metadata
        if (! empty($this->metadata)) {
            $checkout->withMetadata($this->metadata);
        }

        // Merge session options
        if (isset($sessionOptions['success_url'])) {
            $checkout->successUrl($sessionOptions['success_url']);
        }

        if (isset($sessionOptions['cancel_url'])) {
            $checkout->cancelUrl($sessionOptions['cancel_url']);
        }

        if (isset($sessionOptions['webhook_url'])) {
            $checkout->webhookUrl($sessionOptions['webhook_url']);
        }

        // Add subscription reference metadata
        $checkout->withMetadata([
            'subscription_name' => $this->name,
            'price_id' => $this->priceId,
            'billable_id' => $this->billable->getKey(),
            'billable_type' => $this->billable->getMorphClass(),
        ]);

        return $checkout;
    }

    /**
     * Create a trial-only subscription (local database only, no API call).
     */
    protected function createTrialSubscription(array $options = []): Subscription
    {
        return $this->billable->subscriptions()->create([
            'name' => $this->name,
            'chip_id' => 'trial_' . uniqid(),
            'chip_status' => 'trialing',
            'chip_price_id' => $this->priceId,
            'quantity' => $this->quantity,
            'trial_ends_at' => $this->trialEnds,
            'ends_at' => null,
            // First charge is attempted when the trial ends.
            'renews_at' => $this->trialEnds,
        ]);
    }

    /**
     * Add the subscription to the billable entity without creating it in Chip.
     */
    public function add(array $options = []): Subscription
    {
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
     * Resolve the payment method token from various input types.
     */
    protected function resolvePaymentMethodToken($paymentMethod): ?string
    {
        if (is_null($paymentMethod)) {
            return null;
        }

        if ($paymentMethod instanceof PaymentMethod) {
            return $paymentMethod->token();
        }

        if (is_string($paymentMethod)) {
            // Try to find a PaymentMethod model first
            $pm = PaymentMethod::where('chip_token_id', $paymentMethod)
                ->orWhere('id', $paymentMethod)
                ->first();

            return $pm ? $pm->token() : $paymentMethod;
        }

        return null;
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
        $plan = Plan::find($priceId);

        if (! $plan) {
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
