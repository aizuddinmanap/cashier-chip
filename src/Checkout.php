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
    public static function forPayment(int $amount, string $currency = 'MYR'): self
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
     * Automatically sets force_recurring and payment_method_whitelist
     * to enable card tokenization for renewal charges.
     */
    public static function forSubscription(string $priceId, int $quantity = 1): self
    {
        $checkout = new static();

        $checkout->data = [
            'price_id' => $priceId,
            'quantity' => $quantity,
            'is_subscription' => true,
            'force_recurring' => true,
            'payment_method_whitelist' => config(
                'cashier.recurring.payment_methods',
                ['visa', 'mastercard', 'maestro']
            ),
        ];

        return $checkout;
    }

    /**
     * Force card tokenization for recurring payments.
     */
    public function forceRecurring(bool $force = true): self
    {
        $this->data['force_recurring'] = $force;

        if ($force && ! isset($this->data['payment_method_whitelist'])) {
            $this->data['payment_method_whitelist'] = config(
                'cashier.recurring.payment_methods',
                ['visa', 'mastercard', 'maestro']
            );
        }

        return $this;
    }

    /**
     * Skip capture for RM0 preauthorization (free trials, card verification).
     */
    public function skipCapture(bool $skip = true): self
    {
        $this->data['skip_capture'] = $skip;

        return $this;
    }

    /**
     * Set the payment method whitelist.
     */
    public function paymentMethodWhitelist(array $methods): self
    {
        $this->data['payment_method_whitelist'] = $methods;

        return $this;
    }

    /**
     * Set the platform identifier.
     */
    public function platform(string $platform): self
    {
        $this->data['platform'] = $platform;

        return $this;
    }

    /**
     * Disable sending receipt to customer (useful for auto-charges).
     */
    public function withoutReceipt(): self
    {
        $this->data['send_receipt'] = false;

        return $this;
    }

    /**
     * Set due_strict parameter.
     */
    public function dueStrict(bool $strict = true): self
    {
        $this->data['due_strict'] = $strict;

        return $this;
    }

    /**
     * Set the total override amount (in cents).
     */
    public function totalOverride(int $amount): self
    {
        $this->data['total_override'] = $amount;

        return $this;
    }

    /**
     * Set the customer for the checkout.
     */
    public function customer(string $customerId): self
    {
        $this->data['customer_id'] = $customerId;

        unset($this->data['client_email'], $this->data['client_name']);

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

        unset($this->data['customer_id']);

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
     * Set the reference for the checkout.
     */
    public function reference($reference): self
    {
        $this->data['reference'] = (string) $reference;

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
     * Set the cancel/failure URL for the checkout.
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
     */
    public function fpxBank(string $bankCode): self
    {
        $this->data['fpx_bank'] = $bankCode;

        return $this;
    }

    /**
     * Get list of supported FPX banks.
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
        $purchaseData = $this->buildPurchaseData();

        return $this->api->createPurchase($purchaseData);
    }

    /**
     * Build the purchase data for Chip API following official SDK structure.
     */
    protected function buildPurchaseData(): array
    {
        $data = [
            'brand_id' => config('cashier.chip.brand_id'),
            'creator_agent' => config(
                'cashier.recurring.creator_agent',
                'Laravel-Cashier-Chip/' . Cashier::$version
            ),
        ];

        // Purchase block
        $purchase = [
            'currency' => strtoupper($this->data['currency'] ?? config('cashier.currency', 'MYR')),
            'products' => $this->buildProducts(),
        ];

        if (isset($this->data['due_strict'])) {
            $purchase['due_strict'] = $this->data['due_strict'];
        }

        if (isset($this->data['total_override'])) {
            $purchase['total_override'] = $this->data['total_override'];
        }

        if (isset($this->data['metadata'])) {
            $purchase['metadata'] = $this->data['metadata'];
        }

        $data['purchase'] = $purchase;

        // Redirect URLs
        $data['success_redirect'] = $this->data['success_url']
            ?? config('app.url') . '/payment/success';
        $data['failure_redirect'] = $this->data['cancel_url']
            ?? config('app.url') . '/payment/cancel';

        // Client information
        if (isset($this->data['customer_id'])) {
            $data['client'] = ['id' => $this->data['customer_id']];
        } elseif (isset($this->data['client_email']) || isset($this->data['client_name'])) {
            $data['client'] = array_filter([
                'email' => $this->data['client_email'] ?? null,
                'full_name' => $this->data['client_name'] ?? null,
            ]);
        }

        // Webhook callbacks
        if (isset($this->data['webhook_url'])) {
            $data['success_callback'] = $this->data['webhook_url'];
            $data['failure_callback'] = $this->data['webhook_url'];
        }

        // Recurring parameters
        if (! empty($this->data['force_recurring'])) {
            $data['force_recurring'] = true;

            // Filter whitelist to recurring-capable methods only
            $allowed = config('cashier.recurring.payment_methods', ['visa', 'mastercard', 'maestro']);
            $whitelist = $this->data['payment_method_whitelist'] ?? $allowed;
            $data['payment_method_whitelist'] = array_values(array_intersect($whitelist, $allowed));

            if (empty($data['payment_method_whitelist'])) {
                $data['payment_method_whitelist'] = $allowed;
            }
        } elseif (isset($this->data['payment_method_whitelist'])) {
            $data['payment_method_whitelist'] = $this->data['payment_method_whitelist'];
        }

        if (isset($this->data['skip_capture'])) {
            $data['skip_capture'] = $this->data['skip_capture'];
        }

        // Zero-amount subscription = skip capture (free trial)
        if (($this->data['is_subscription'] ?? false)
            && ($this->data['force_recurring'] ?? false)
            && ($purchase['total_override'] ?? ($this->data['amount'] ?? null)) === 0) {
            $data['skip_capture'] = true;
        }

        if (isset($this->data['send_receipt'])) {
            $data['send_receipt'] = $this->data['send_receipt'];
        }

        if (isset($this->data['platform'])) {
            $data['platform'] = $this->data['platform'];
        }

        if (isset($this->data['reference'])) {
            $data['reference'] = $this->data['reference'];
        }

        // FPX-specific data
        if (($this->data['payment_method'] ?? null) === 'fpx') {
            $data['payment_method_whitelist'] = ['fpx'];

            if (isset($this->data['fpx_bank'])) {
                $data['fpx_bank_id'] = $this->data['fpx_bank'];
            }
        }

        // Custom data overrides
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
            return [
                [
                    'name' => $this->data['description'] ?? 'Subscription',
                    'price' => $this->data['price'] ?? 0,
                    'quantity' => $this->data['quantity'] ?? 1,
                ]
            ];
        } else {
            return [
                [
                    'name' => $this->data['description'] ?? 'Payment',
                    'price' => $this->data['amount'],
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
