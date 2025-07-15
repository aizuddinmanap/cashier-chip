<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Aizuddinmanap\CashierChip\Concerns\ManagesCustomer;
use Aizuddinmanap\CashierChip\Concerns\ManagesInvoices;
use Aizuddinmanap\CashierChip\Concerns\ManagesPaymentMethods;
use Aizuddinmanap\CashierChip\Concerns\ManagesSubscriptions;
use Aizuddinmanap\CashierChip\Concerns\ManagesTransactions;
use Aizuddinmanap\CashierChip\Concerns\PerformsCharges;
use Illuminate\Support\Collection;

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
        return Checkout::forPrices($prices, $options)->client($this->email, $this->name ?? null);
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
        // Create a pending invoice that can be processed later
        $amount = $options['amount'] ?? 0;
        $description = $options['description'] ?? 'Invoice';
        
        return $this->invoiceFor($description, $amount, $options);
    }

    /**
     * Invoice the billable entity for the given amount.
     */
    public function invoiceFor(string $description, int $amount, array $options = []): Invoice
    {
        // Create the transaction record
        $transaction = $this->transactions()->create([
            'id' => 'txn_' . uniqid(),
            'chip_id' => $options['chip_id'] ?? 'invoice_' . uniqid(),
            'type' => 'charge',
            'status' => 'pending',
            'currency' => strtoupper($options['currency'] ?? config('cashier.currency', 'MYR')),
            'total' => $amount,
            'description' => $description,
            'metadata' => $options['metadata'] ?? [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create and return invoice from transaction
        return $this->convertTransactionToInvoice($transaction);
    }

    /**
     * Get the upcoming invoice for the billable entity.
     */
    public function upcomingInvoice(): ?Invoice
    {
        // Get pending transactions as upcoming invoices
        $query = $this->transactions()->orderByDesc('created_at');
        
        // Only filter by type if the column exists (for backward compatibility)
        if (method_exists($this, 'hasTypeColumn') && $this->hasTypeColumn()) {
            $query->where('type', 'charge');
        }
        
        $pendingTransaction = $query->where('status', 'pending')->first();

        if ($pendingTransaction) {
            return $this->convertTransactionToInvoice($pendingTransaction);
        }

        // For subscriptions, calculate next billing cycle
        $activeSubscription = $this->subscriptions()
            ->where('chip_status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                      ->orWhere('ends_at', '>', now());
            })
            ->first();

        if ($activeSubscription) {
            // Get the actual subscription amount instead of hardcoding
            $amount = $activeSubscription->amount() ?: 0; // Fallback to 0 if no pricing configured
            $currency = $activeSubscription->currency();
            
            // Create subscription transaction
            $transaction = $this->transactions()->create([
                'id' => 'txn_' . uniqid(),
                'chip_id' => 'upcoming_' . $activeSubscription->chip_id,
                'type' => 'charge',
                'status' => 'pending',
                'currency' => strtoupper($currency),
                'total' => $amount,
                'description' => $options['description'] ?? 'Subscription Payment',
                'metadata' => ['subscription_id' => $activeSubscription->id],
            ]);

            return new Invoice([
                'id' => 'upcoming_' . uniqid(),
                'chip_id' => 'upcoming_' . $activeSubscription->chip_id,
                'customer_id' => $activeSubscription->customer_id,
                'subscription_id' => $activeSubscription->id,
                'amount_paid' => 0,
                'amount_due' => $amount,
                'currency' => 'MYR',
                'status' => 'draft',
                'date' => now()->addMonth(),
                'due_date' => now()->addMonth(),
                'paid_at' => null,
                'description' => 'Upcoming subscription payment',
                'metadata' => ['subscription_id' => $activeSubscription->id],
                'lines' => [
                    [
                        'id' => 'line_' . uniqid(),
                        'description' => 'Subscription: ' . $activeSubscription->name,
                        'amount' => $amount,
                        'currency' => 'MYR',
                        'quantity' => 1,
                    ]
                ],
                'total' => $amount,
                'subtotal' => $amount,
                'tax' => 0,
                'billable' => $this,
                'transaction' => null,
            ]);
        }

        return null;
    }

    /**
     * Find an invoice by its ID.
     */
    public function findInvoice(string $id): ?Invoice
    {
        $transaction = $this->transactions()
            ->where('id', $id)
            ->orWhere('chip_id', $id)
            ->first();

        if (!$transaction) {
            return null;
        }

        return $this->convertTransactionToInvoice($transaction);
    }

    /**
     * Find an invoice or throw a 404 error.
     */
    public function findInvoiceOrFail(string $id): Invoice
    {
        $invoice = $this->findInvoice($id);

        if (!$invoice) {
            throw new \Exception("Invoice {$id} not found");
        }

        return $invoice;
    }

    /**
     * Download an invoice as a PDF.
     */
    public function downloadInvoice(string $invoiceId, array $data = []): \Symfony\Component\HttpFoundation\Response
    {
        $invoice = $this->findInvoiceOrFail($invoiceId);
        
        return $invoice->downloadPDF($data);
    }

    /**
     * Get all invoices for the billable entity.
     */
    public function invoices(bool $includePending = false): Collection
    {
        $query = $this->transactions()->orderByDesc('created_at');

        // Only filter by type if the column exists (for backward compatibility)
        if (method_exists($this, 'hasTypeColumn') && $this->hasTypeColumn()) {
            $query->where('type', 'charge');
        }

        if (!$includePending) {
            $query->where('status', 'success');
        }

        return $query->get()->map(function (Transaction $transaction) {
            return $this->convertTransactionToInvoice($transaction);
        });
    }

    /**
     * Get invoices for a specific period.
     */
    public function invoicesForPeriod(\Carbon\Carbon $startDate, \Carbon\Carbon $endDate): Collection
    {
        $query = $this->transactions()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderByDesc('created_at');
            
        // Only filter by type if the column exists (for backward compatibility)
        if (method_exists($this, 'hasTypeColumn') && $this->hasTypeColumn()) {
            $query->where('type', 'charge');
        }

        return $query->get()->map(function (Transaction $transaction) {
            return $this->convertTransactionToInvoice($transaction);
        });
    }

    /**
     * Convert a transaction to an invoice for Laravel Cashier compatibility.
     */
    protected function convertTransactionToInvoice($transaction): Invoice
    {
        return new Invoice([
            'id' => $transaction->id,
            'chip_id' => $transaction->chip_id,
            'customer_id' => $transaction->customer_id,
            'subscription_id' => $transaction->subscription_id ?? null,
            'amount_paid' => $transaction->status === 'success' ? $transaction->total : 0,
            'amount_due' => $transaction->status === 'pending' ? $transaction->total : 0,
            'currency' => $transaction->currency,
            'status' => $this->mapTransactionStatusToInvoiceStatus($transaction->status),
            'date' => $transaction->created_at,
            'due_date' => $transaction->created_at->addDays(30),
            'paid_at' => $transaction->processed_at,
            'description' => $transaction->description,
            'metadata' => $transaction->metadata ?? [],
            'lines' => $this->generateInvoiceLines($transaction),
            'total' => $transaction->total,
            'subtotal' => $transaction->total,
            'tax' => 0,
            'billable' => $this,
            'transaction' => $transaction,
            // Laravel compatibility fields
            'created_at' => $transaction->created_at,
            'updated_at' => $transaction->updated_at,
        ]);
    }

    /**
     * Map transaction status to invoice status.
     */
    protected function mapTransactionStatusToInvoiceStatus(string $transactionStatus): string
    {
        return match ($transactionStatus) {
            'success' => 'paid',
            'pending' => 'open',
            'failed' => 'void',
            'refunded' => 'void',
            default => 'draft',
        };
    }

    /**
     * Generate invoice lines from transaction data.
     */
    protected function generateInvoiceLines($transaction): array
    {
        return [
            [
                'id' => 'line_' . uniqid(),
                'description' => $transaction->description ?: 'Payment',
                'amount' => $transaction->total,
                'currency' => $transaction->currency,
                'quantity' => 1,
            ]
        ];
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