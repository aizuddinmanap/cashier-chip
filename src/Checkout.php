<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Aizuddinmanap\CashierChip\Http\ChipApi;

class Checkout
{
    /**
     * The Chip API client.
     */
    protected ChipApi $api;

    /**
     * The checkout data.
     */
    protected array $data = [];

    /**
     * Create a new checkout instance.
     */
    public function __construct(?ChipApi $api = null)
    {
        $this->api = $api ?? new ChipApi();
    }

    /**
     * Create a new checkout instance for a one-time payment.
     */
    public static function forPayment(int $amount, string $currency = 'myr'): self
    {
        $checkout = new static();
        
        $checkout->data = [
            'amount' => $amount,
            'currency' => $currency,
            'is_subscription' => false,
        ];

        return $checkout;
    }

    /**
     * Create a new checkout instance for a subscription.
     */
    public static function forSubscription(string $priceId, int $quantity = 1): self
    {
        $checkout = new static();
        
        $checkout->data = [
            'price_id' => $priceId,
            'quantity' => $quantity,
            'is_subscription' => true,
        ];

        return $checkout;
    }

    /**
     * Set the customer for the checkout.
     */
    public function customer(string $customerId): self
    {
        $this->data['customer_id'] = $customerId;

        return $this;
    }

    /**
     * Set the client information for the checkout.
     */
    public function client(string $email, ?string $fullName = null): self
    {
        $this->data['client_email'] = $email;
        if ($fullName) {
            $this->data['client_name'] = $fullName;
        }

        return $this;
    }

    /**
     * Set the description for the checkout.
     */
    public function description(string $description): self
    {
        $this->data['description'] = $description;

        return $this;
    }

    /**
     * Set the success URL for the checkout.
     */
    public function successUrl(string $url): self
    {
        $this->data['success_url'] = $url;

        return $this;
    }

    /**
     * Set the cancel URL for the checkout.
     */
    public function cancelUrl(string $url): self
    {
        $this->data['cancel_url'] = $url;

        return $this;
    }

    /**
     * Set the webhook URL for the checkout.
     */
    public function webhookUrl(string $url): self
    {
        $this->data['webhook_url'] = $url;

        return $this;
    }

    /**
     * Add metadata to the checkout.
     */
    public function withMetadata(array $metadata): self
    {
        $this->data['metadata'] = array_merge($this->data['metadata'] ?? [], $metadata);

        return $this;
    }

    /**
     * Set custom data for the checkout.
     */
    public function withCustomData(array $customData): self
    {
        $this->data['custom_data'] = $customData;

        return $this;
    }

    /**
     * Enable trial period for subscription checkout.
     */
    public function trialDays(int $days): self
    {
        if ($this->data['is_subscription'] ?? false) {
            $this->data['trial_days'] = $days;
        }

        return $this;
    }

    /**
     * Create a checkout specifically for FPX (Malaysian online banking).
     */
    public static function forFPX(int $amount, string $currency = 'MYR'): self
    {
        $checkout = new static();
        
        $checkout->data = [
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'payment_method' => 'fpx',
            'is_subscription' => false,
        ];

        return $checkout;
    }

    /**
     * Set the preferred FPX bank for the payment.
     * Common Malaysian banks supported by Chip FPX.
     */
    public function fpxBank(string $bankCode): self
    {
        $this->data['fpx_bank'] = $bankCode;
        
        return $this;
    }

    /**
     * Get list of supported FPX banks.
     * These are the common Malaysian banks supported by FPX.
     */
    public static function getSupportedFPXBanks(): array
    {
        return [
            'maybank2u' => 'Maybank2U',
            'cimb' => 'CIMB Clicks', 
            'public' => 'Public Bank',
            'rhb' => 'RHB Bank',
            'hongleong' => 'Hong Leong Bank',
            'ambank' => 'AmBank',
            'affin' => 'Affin Bank',
            'alliance' => 'Alliance Bank',
            'islam' => 'Bank Islam',
            'muamalat' => 'Bank Muamalat',
            'rakyat' => 'Bank Rakyat',
            'bsn' => 'BSN',
            'hsbc' => 'HSBC Bank',
            'kfh' => 'KFH',
            'ocbc' => 'OCBC Bank',
            'sc' => 'Standard Chartered',
            'uob' => 'UOB Bank',
            'agro' => 'AGRONet',
        ];
    }

    /**
     * Create the checkout session.
     */
    public function create(): array
    {
        // Prepare the data for Chip API
        $purchaseData = $this->buildPurchaseData();

        // Create the purchase via Chip API
        return $this->api->createPurchase($purchaseData);
    }

    /**
     * Build the purchase data for Chip API following official SDK structure.
     */
    protected function buildPurchaseData(): array
    {
        $data = [
            'purchase' => [
                'currency' => strtoupper($this->data['currency'] ?? 'MYR'),
                'products' => $this->buildProducts(),
            ],
            'brand_id' => config('cashier.chip.brand_id'),
            'success_redirect' => $this->data['success_url'] ?? config('app.url') . '/payment/success',
            'failure_redirect' => $this->data['cancel_url'] ?? config('app.url') . '/payment/cancel',
        ];

        // Add client information if customer_id is provided
        if (isset($this->data['customer_id'])) {
            $data['client'] = [
                'id' => $this->data['customer_id'],
            ];
        }

        // Add client email and name if provided
        if (isset($this->data['client_email']) || isset($this->data['client_name'])) {
            $data['client'] = array_merge($data['client'] ?? [], array_filter([
                'email' => $this->data['client_email'] ?? null,
                'full_name' => $this->data['client_name'] ?? null,
            ]));
        }

        // Add success/failure callbacks for webhooks
        if (isset($this->data['webhook_url'])) {
            $data['success_callback'] = $this->data['webhook_url'];
            $data['failure_callback'] = $this->data['webhook_url'];
        }

        // Add metadata if provided
        if (isset($this->data['metadata'])) {
            $data['purchase']['metadata'] = $this->data['metadata'];
        }

        // Add FPX-specific data if this is an FPX payment
        if (($this->data['payment_method'] ?? null) === 'fpx') {
            $data['payment_method_whitelist'] = ['fpx'];
            
            // Add preferred bank if specified
            if (isset($this->data['fpx_bank'])) {
                $data['fpx_bank_id'] = $this->data['fpx_bank'];
            }
        }

        // Add custom data if provided
        if (isset($this->data['custom_data'])) {
            $data = array_merge($data, $this->data['custom_data']);
        }

        return $data;
    }

    /**
     * Build the products array for the purchase following official SDK structure.
     */
    protected function buildProducts(): array
    {
        if ($this->data['is_subscription'] ?? false) {
            // Subscription product
            return [
                [
                    'name' => $this->data['description'] ?? 'Subscription',
                    'price' => $this->data['price'] ?? 0,
                    'quantity' => $this->data['quantity'] ?? 1,
                    'is_subscription' => true,
                    'subscription_interval' => $this->data['interval'] ?? 'month',
                ]
            ];
        } else {
            // One-time payment product (price in cents as per Chip API)
            return [
                [
                    'name' => $this->data['description'] ?? 'Payment',
                    'price' => $this->data['amount'], // Amount should be in cents
                    'quantity' => 1,
                ]
            ];
        }
    }

    /**
     * Get the checkout URL from the response.
     */
    public function url(): ?string
    {
        $response = $this->create();
        
        return $response['checkout_url'] ?? null;
    }

    /**
     * Get the checkout data.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get the amount for the checkout.
     */
    public function getAmount(): ?int
    {
        return $this->data['amount'] ?? null;
    }

    /**
     * Get the currency for the checkout.
     */
    public function getCurrency(): ?string
    {
        return $this->data['currency'] ?? null;
    }

    /**
     * Get the FPX bank code.
     */
    public function getFpxBank(): ?string
    {
        return $this->data['fpx_bank'] ?? null;
    }

    /**
     * Magic getter for backward compatibility.
     */
    public function __get(string $name)
    {
        return match ($name) {
            'amount' => $this->getAmount(),
            'currency' => $this->getCurrency(),
            'fpxBank' => $this->getFpxBank(),
            default => $this->data[$name] ?? null,
        };
    }
} 