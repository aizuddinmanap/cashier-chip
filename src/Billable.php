<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Aizuddinmanap\CashierChip\Concerns\ManagesCustomer;
use Aizuddinmanap\CashierChip\Concerns\ManagesInvoices;
use Aizuddinmanap\CashierChip\Concerns\ManagesPaymentMethods;
use Aizuddinmanap\CashierChip\Concerns\ManagesSubscriptions;
use Aizuddinmanap\CashierChip\Concerns\ManagesTransactions;
use Aizuddinmanap\CashierChip\Concerns\PerformsCharges;

trait Billable
{
    use ManagesCustomer;
    use ManagesInvoices;
    use ManagesPaymentMethods;
    use ManagesSubscriptions;
    use ManagesTransactions;
    use PerformsCharges;

    /**
     * Get all transactions for the billable entity.
     */
    public function transactions()
    {
        return $this->morphMany(Cashier::transactionModel(), 'billable')->orderByDesc('created_at');
    }

    /**
     * Get all subscriptions for the billable entity.
     */
    public function subscriptions()
    {
        return $this->hasMany(Cashier::subscriptionModel())->orderByDesc('created_at');
    }

    /**
     * Get the customer associated with the billable entity.
     */
    public function customer()
    {
        return $this->morphOne(Cashier::customerModel(), 'billable');
    }

    /**
     * Create a new subscription builder for the given price.
     */
    public function newSubscription(string $name, string $priceId): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $name, $priceId);
    }

    /**
     * Get a subscription instance by name.
     */
    public function subscription(string $name = 'default'): ?Subscription
    {
        return $this->subscriptions()->where('name', $name)->first();
    }

    /**
     * Determine if the billable entity has any active subscriptions.
     */
    public function subscribed(?string $name = null, ?string $priceId = null): bool
    {
        $subscription = $this->subscription($name);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        return $priceId ? $subscription->hasPlan($priceId) : true;
    }

    /**
     * Determine if the billable entity is on trial.
     */
    public function onTrial(string $name = 'default', ?string $priceId = null): bool
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($name);

        if (! $subscription || ! $subscription->onTrial()) {
            return false;
        }

        return $priceId ? $subscription->hasPlan($priceId) : true;
    }

    /**
     * Determine if the billable entity is on a "generic" trial.
     */
    public function onGenericTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Get the ending date of the trial.
     */
    public function trialEndsAt(): ?\DateTimeInterface
    {
        return $this->trial_ends_at;
    }

    /**
     * Determine if the billable entity has a Chip customer ID.
     */
    public function hasChipId(): bool
    {
        return ! is_null($this->chip_id);
    }

    /**
     * Get the Chip customer ID for the billable entity.
     */
    public function chipId(): ?string
    {
        return $this->chip_id;
    }

    /**
     * Create a Chip customer for the billable entity.
     */
    public function createAsChipCustomer(array $options = []): Customer
    {
        if ($this->hasChipId()) {
            throw new \Exception('Billable model already has a Chip customer ID.');
        }

        $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();

        // Prepare client data for Chip API
        $clientData = [
            'email' => $this->email ?? $options['email'] ?? null,
            'full_name' => $this->name ?? $options['name'] ?? null,
            'legal_name' => $options['legal_name'] ?? null,
        ];

        // Remove null values
        $clientData = array_filter($clientData, function ($value) {
            return $value !== null;
        });

        try {
            // Create client via Chip API
            $response = $api->createClient($clientData);

            $customer = new Customer([
                'chip_id' => $response['id'],
                'email' => $response['email'] ?? $clientData['email'],
                'name' => $response['full_name'] ?? $clientData['full_name'],
            ]);

            // Store the Chip customer ID locally
            $this->chip_id = $customer->chip_id;
            $this->save();

            return $customer;

        } catch (\Exception $e) {
            // Fallback to local customer creation if API fails
            $customer = new Customer([
                'chip_id' => 'cust_' . uniqid(),
                'email' => $this->email ?? $options['email'] ?? null,
                'name' => $this->name ?? $options['name'] ?? null,
            ]);

            $this->chip_id = $customer->chip_id;
            $this->save();

            return $customer;
        }
    }

    /**
     * Create a customer for the billable entity.
     */
    public function createAsCustomer(array $options = []): Customer
    {
        return $this->createAsChipCustomer($options);
    }

    /**
     * Get or create a Chip customer for the billable entity.
     */
    public function asChipCustomer(): Customer
    {
        if ($this->hasChipId()) {
            return $this->customer ?: $this->createAsChipCustomer();
        }

        return $this->createAsChipCustomer();
    }

    /**
     * Create a checkout session for the given prices.
     */
    public function checkout(array $prices, array $options = []): Checkout
    {
        return Checkout::forPrices($prices, $options)->customer($this->chipId());
    }

    /**
     * Create a checkout session for a single price.
     */
    public function checkoutPrice(string $priceId, array $options = []): Checkout
    {
        return $this->checkout([$priceId], $options);
    }

    /**
     * Begin creating a new charge for the given amount.
     */
    public function tab(string $name = 'default'): PaymentBuilder
    {
        return new PaymentBuilder($this, 0);
    }

    /**
     * Invoice the billable entity outside of the regular billing cycle.
     */
    public function invoice(array $options = []): Invoice
    {
        // Implementation for creating invoices
        // This would integrate with Chip's invoice API
        throw new \Exception('Invoice creation not yet implemented');
    }

    /**
     * Invoice the billable entity for the given amount.
     */
    public function invoiceFor(string $description, int $amount, array $options = []): Invoice
    {
        // Implementation for creating invoices for specific amounts
        // This would integrate with Chip's invoice API
        throw new \Exception('Invoice creation not yet implemented');
    }

    /**
     * Get the upcoming invoice for the billable entity.
     */
    public function upcomingInvoice(): ?Invoice
    {
        // Implementation for retrieving upcoming invoices
        // This would integrate with Chip's invoice API
        return null;
    }

    /**
     * Find an invoice by its ID.
     */
    public function findInvoice(string $id): ?Invoice
    {
        // Implementation for finding invoices by ID
        // This would integrate with Chip's invoice API
        return null;
    }

    /**
     * Find an invoice or throw a 404 error.
     */
    public function findInvoiceOrFail(string $id): Invoice
    {
        $invoice = $this->findInvoice($id);

        if (! $invoice) {
            throw new \Exception("Invoice {$id} not found");
        }

        return $invoice;
    }

    /**
     * Create a new checkout session for the billable entity.
     */
    public function redirectToCheckout(array $options = []): string
    {
        $checkout = $this->checkout($options['prices'] ?? [], $options);
        
        return $checkout->create()['checkout_url'] ?? '';
    }

    /**
     * Get the tax rates that apply to the billable entity.
     */
    public function taxRates(): array
    {
        return config('cashier.tax_rates', []);
    }

    /**
     * Sync the customer's information with Chip.
     */
    public function syncChipCustomerData(): Customer
    {
        return $this->asChipCustomer()->syncWithChip();
    }
} 