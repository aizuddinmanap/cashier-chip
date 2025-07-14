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
        $this->currency = $options['currency'] ?? config('cashier.currency', 'myr');
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
    public function create(): Transaction
    {
        $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();

        try {
            // Ensure we have client email (required by Chip API)
            $clientEmail = $this->options['client_email'] ?? $this->billable->email ?? null;
            if (!$clientEmail) {
                throw new \Exception('Client email is required for Chip API purchase creation');
            }

            // Prepare purchase data for Chip API following official SDK structure
            $purchaseData = [
                'purchase' => [
                    'currency' => strtoupper($this->currency),
                    'products' => [
                        [
                            'name' => $this->description ?: 'Payment',
                            'price' => $this->amount,
                            'quantity' => 1,
                        ]
                    ],
                    'metadata' => $this->metadata,
                ],
                'client' => [
                    'email' => $clientEmail,
                ],
                'brand_id' => config('cashier.chip.brand_id'),
            ];

            // Add client name if available
            if ($this->billable->name ?? $this->options['client_name'] ?? null) {
                $purchaseData['client']['full_name'] = $this->billable->name ?? $this->options['client_name'];
            }

            // Merge any additional options
            $purchaseData = array_merge($purchaseData, array_filter($this->options, function($key) {
                return !in_array($key, ['client_email', 'client_name']);
            }, ARRAY_FILTER_USE_KEY));

            // Create purchase via Chip API
            $response = $api->createPurchase($purchaseData);

            // Create local payment record
            return $this->billable->transactions()->create([
                'id' => 'txn_' . uniqid(),
                'chip_id' => $response['id'],
                'total' => $this->amount,
                'currency' => $this->currency,
                'description' => $this->description,
                'status' => $response['status'] ?? 'pending',
                'type' => 'charge',
                'metadata' => json_encode($this->metadata),
            ]);

        } catch (\Exception $e) {
            throw new \Exception("Failed to create payment: {$e->getMessage()}");
        }
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
        $payment = $this->billable->transactions()->create([
            'id' => $response['id'] ?? 'pay_' . uniqid(),
            'chip_id' => $response['id'] ?? 'chip_' . uniqid(),
            'total' => $this->amount,
            'currency' => $this->currency,
            'status' => 'pending',
        ]);

        return [
            'payment' => $payment,
            'checkout_url' => $response['checkout_url'] ?? null,
        ];
    }
} 