<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Concerns;

use Aizuddinmanap\CashierChip\Invoice;
use Aizuddinmanap\CashierChip\Transaction;
use Illuminate\Support\Collection;
use Carbon\Carbon;

trait ManagesInvoices
{
    /**
     * Get all invoices for the billable entity.
     * 
     * This method converts transactions to invoices for Laravel Cashier compatibility.
     */
    public function invoices(bool $includePending = false): Collection
    {
        $query = $this->transactions()
            ->where('type', 'charge')
            ->orderByDesc('created_at');

        if (!$includePending) {
            $query->where('status', 'success');
        }

        return $query->get()->map(function (Transaction $transaction) {
            return $this->convertTransactionToInvoice($transaction);
        });
    }

    /**
     * Get all receipts for the billable entity.
     */
    public function receipts()
    {
        return $this->morphMany(\Aizuddinmanap\CashierChip\Receipt::class, 'billable')->orderByDesc('created_at');
    }

    /**
     * Find an invoice by its ID.
     */
    public function findInvoice(string $invoiceId): ?Invoice
    {
        // Try to find by invoice ID first (for compatibility)
        $transaction = $this->transactions()
            ->where('id', $invoiceId)
            ->orWhere('chip_id', $invoiceId)
            ->first();

        if (!$transaction) {
            return null;
        }

        return $this->convertTransactionToInvoice($transaction);
    }

    /**
     * Find an invoice by its ID or throw an exception.
     */
    public function findInvoiceOrFail(string $invoiceId): Invoice
    {
        $invoice = $this->findInvoice($invoiceId);

        if (!$invoice) {
            throw new \Exception("Invoice {$invoiceId} not found");
        }

        return $invoice;
    }

    /**
     * Get the latest invoice for the billable entity.
     */
    public function latestInvoice(): ?Invoice
    {
        $transaction = $this->transactions()
            ->where('type', 'charge')
            ->where('status', 'success')
            ->orderByDesc('created_at')
            ->first();

        if (!$transaction) {
            return null;
        }

        return $this->convertTransactionToInvoice($transaction);
    }

    /**
     * Get the upcoming invoice for the billable entity.
     * 
     * For one-time payments, this returns pending transactions.
     * For subscriptions, this would calculate the next billing cycle.
     */
    public function upcomingInvoice(): ?Invoice
    {
        // Get pending transactions as upcoming invoices
        $pendingTransaction = $this->transactions()
            ->where('type', 'charge')
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->first();

        if ($pendingTransaction) {
            return $this->convertTransactionToInvoice($pendingTransaction);
        }

        // For subscriptions, calculate next billing cycle
        $activeSubscription = $this->subscriptions()
            ->where('chip_status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                      ->orWhere('ends_at', '>', Carbon::now());
            })
            ->first();

        if ($activeSubscription) {
            return $this->generateUpcomingSubscriptionInvoice($activeSubscription);
        }

        return null;
    }

    /**
     * Download an invoice as PDF.
     */
    public function downloadInvoice(string $invoiceId, array $data = []): \Symfony\Component\HttpFoundation\Response
    {
        $invoice = $this->findInvoiceOrFail($invoiceId);

        return $invoice->downloadPDF($data);
    }

    /**
     * Create an invoice for a specific amount.
     */
    public function invoiceFor(string $description, int $amount, array $options = []): Invoice
    {
        $transaction = $this->transactions()->create([
            'id' => 'txn_' . uniqid(),
            'chip_id' => $options['chip_id'] ?? 'invoice_' . uniqid(),
            'type' => 'charge',
            'status' => 'pending',
            'currency' => $options['currency'] ?? 'MYR',
            'total' => $amount,
            'description' => $description,
            'metadata' => json_encode($options['metadata'] ?? []),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->convertTransactionToInvoice($transaction);
    }

    /**
     * Get invoices for a specific date range.
     */
    public function invoicesForPeriod(Carbon $startDate, Carbon $endDate): Collection
    {
        return $this->transactions()
            ->where('type', 'charge')
            ->where('status', 'success')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Transaction $transaction) {
                return $this->convertTransactionToInvoice($transaction);
            });
    }

    /**
     * Get invoices for a specific year.
     */
    public function invoicesForYear(int $year): Collection
    {
        $startDate = Carbon::create($year, 1, 1);
        $endDate = Carbon::create($year, 12, 31, 23, 59, 59);

        return $this->invoicesForPeriod($startDate, $endDate);
    }

    /**
     * Get total amount of invoices for a period.
     */
    public function invoiceTotalForPeriod(Carbon $startDate, Carbon $endDate): int
    {
        return $this->transactions()
            ->where('type', 'charge')
            ->where('status', 'success')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('total');
    }

    /**
     * Convert a transaction to an invoice for Laravel Cashier compatibility.
     */
    protected function convertTransactionToInvoice(Transaction $transaction): Invoice
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
            'due_date' => $transaction->created_at->addDays(30), // Default 30 days
            'paid_at' => $transaction->processed_at,
            'description' => $transaction->description,
            'metadata' => $transaction->metadata ? json_decode($transaction->metadata, true) : [],
            'lines' => $this->generateInvoiceLines($transaction),
            'total' => $transaction->total,
            'subtotal' => $transaction->total,
            'tax' => 0, // CashierChip doesn't handle tax by default
            'billable' => $this,
            'transaction' => $transaction,
        ]);
    }

    /**
     * Map transaction status to invoice status for Laravel Cashier compatibility.
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
    protected function generateInvoiceLines(Transaction $transaction): array
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
     * Generate upcoming subscription invoice.
     */
    protected function generateUpcomingSubscriptionInvoice($subscription): Invoice
    {
        // This would integrate with subscription pricing logic
        // For now, create a basic upcoming invoice
        $amount = 2990; // Default amount, should be fetched from subscription pricing

        return new Invoice([
            'id' => 'upcoming_' . uniqid(),
            'chip_id' => 'upcoming_' . $subscription->chip_id,
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'amount_paid' => 0,
            'amount_due' => $amount,
            'currency' => 'MYR',
            'status' => 'draft',
            'date' => Carbon::now()->addMonth(),
            'due_date' => Carbon::now()->addMonth(),
            'paid_at' => null,
            'description' => 'Upcoming subscription payment',
            'metadata' => ['subscription_id' => $subscription->id],
            'lines' => [
                [
                    'id' => 'line_' . uniqid(),
                    'description' => 'Subscription: ' . $subscription->name,
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
} 