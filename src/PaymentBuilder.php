<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Illuminate\Database\Eloquent\Model;

class PaymentBuilder
{
    /**
     * The model that is making the payment.
     */
    protected Model $billable;

    /**
     * The amount to charge.
     */
    protected int $amount;

    /**
     * The currency for the payment.
     */
    protected string $currency;

    /**
     * The description for the payment.
     */
    protected ?string $description = null;

    /**
     * Metadata to apply to the payment.
     */
    protected array $metadata = [];

    /**
     * Options for the payment.
     */
    protected array $options = [];

    /**
     * Create a new payment builder instance.
     */
    public function __construct(Model $billable, int $amount, array $options = [])
    {
        $this->billable = $billable;
        $this->amount = $amount;
        $this->currency = $options['currency'] ?? config('cashier-chip.currency', 'myr');
        $this->options = $options;
    }

    /**
     * Set the currency for the payment.
     */
    public function currency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * Set the description for the payment.
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Add metadata to the payment.
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    /**
     * Create the payment.
     */
    public function create(): Payment
    {
        // Ensure the billable model has a Chip customer ID
        if (! $this->billable->hasChipId()) {
            $this->billable->createAsChipCustomer();
        }

        // Create checkout with Chip API
        $checkout = Checkout::forPayment($this->amount, $this->currency)
            ->customer($this->billable->chipId())
            ->description($this->description ?? 'Payment');

        if (isset($this->options['success_url'])) {
            $checkout->successUrl($this->options['success_url']);
        }
        
        if (isset($this->options['cancel_url'])) {
            $checkout->cancelUrl($this->options['cancel_url']);
        }

        if (! empty($this->metadata)) {
            $checkout->withMetadata($this->metadata);
        }

        $response = $checkout->create();

        // Create the payment record
        $payment = $this->billable->payments()->create([
            'id' => $response['id'] ?? 'pay_' . uniqid(),
            'chip_id' => $response['id'] ?? 'chip_' . uniqid(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => 'pending',
        ]);

        return $payment;
    }

    /**
     * Create the payment and return the checkout URL.
     */
    public function checkout(array $options = []): array
    {
        // Ensure the billable model has a Chip customer ID
        if (! $this->billable->hasChipId()) {
            $this->billable->createAsChipCustomer();
        }

        // Create checkout with Chip API
        $checkout = Checkout::forPayment($this->amount, $this->currency)
            ->customer($this->billable->chipId())
            ->description($this->description ?? 'Payment');

        if (isset($options['success_url'])) {
            $checkout->successUrl($options['success_url']);
        }
        
        if (isset($options['cancel_url'])) {
            $checkout->cancelUrl($options['cancel_url']);
        }

        if (! empty($this->metadata)) {
            $checkout->withMetadata($this->metadata);
        }

        $response = $checkout->create();

        // Create the payment record
        $payment = $this->billable->payments()->create([
            'id' => $response['id'] ?? 'pay_' . uniqid(),
            'chip_id' => $response['id'] ?? 'chip_' . uniqid(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => 'pending',
        ]);

        return [
            'payment' => $payment,
            'checkout_url' => $response['checkout_url'] ?? null,
        ];
    }
} 