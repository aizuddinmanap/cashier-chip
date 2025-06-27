<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Concerns;

use Aizuddinmanap\CashierChip\Invoice;
use Illuminate\Support\Collection;

trait ManagesInvoices
{
    /**
     * Get all invoices for the billable entity.
     */
    public function invoices(): Collection
    {
        // TODO: Implement invoices retrieval from Chip API
        return collect();
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
        // TODO: Implement invoice retrieval from Chip API
        return null;
    }

    /**
     * Get the latest invoice for the billable entity.
     */
    public function latestInvoice(): ?Invoice
    {
        // TODO: Implement latest invoice retrieval from Chip API
        return null;
    }

    /**
     * Download an invoice as PDF.
     */
    public function downloadInvoice(string $invoiceId, array $data = []): \Symfony\Component\HttpFoundation\Response
    {
        // TODO: Implement invoice PDF generation
        throw new \Exception('Invoice download not implemented yet.');
    }
} 