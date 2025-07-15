<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class Invoice
{
    /**
     * The invoice attributes.
     */
    protected array $attributes = [];

    /**
     * Create a new invoice instance.
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Get an attribute from the invoice.
     */
    public function __get(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Set an attribute on the invoice.
     */
    public function __set(string $key, $value)
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get the invoice's ID.
     */
    public function id(): string
    {
        return $this->attributes['id'] ?? $this->attributes['chip_id'];
    }

    /**
     * Get the invoice's Chip ID.
     */
    public function chipId(): ?string
    {
        return $this->attributes['chip_id'];
    }

    /**
     * Get the invoice's total amount.
     */
    public function total(): string
    {
        return Cashier::formatAmount($this->attributes['total'] ?? 0, $this->attributes['currency'] ?? 'myr');
    }

    /**
     * Get the raw total amount.
     */
    public function rawTotal(): int
    {
        return $this->attributes['total'] ?? 0;
    }

    /**
     * Get the invoice's subtotal amount.
     */
    public function subtotal(): string
    {
        return Cashier::formatAmount($this->attributes['subtotal'] ?? 0, $this->attributes['currency'] ?? 'myr');
    }

    /**
     * Get the raw subtotal amount.
     */
    public function rawSubtotal(): int
    {
        return $this->attributes['subtotal'] ?? 0;
    }

    /**
     * Get the invoice's tax amount.
     */
    public function tax(): string
    {
        return Cashier::formatAmount($this->attributes['tax'] ?? 0, $this->attributes['currency'] ?? 'myr');
    }

    /**
     * Get the raw tax amount.
     */
    public function rawTax(): int
    {
        return $this->attributes['tax'] ?? 0;
    }

    /**
     * Get the amount paid.
     */
    public function amountPaid(): string
    {
        return Cashier::formatAmount($this->attributes['amount_paid'] ?? 0, $this->attributes['currency'] ?? 'myr');
    }

    /**
     * Get the raw amount paid.
     */
    public function rawAmountPaid(): int
    {
        return $this->attributes['amount_paid'] ?? 0;
    }

    /**
     * Get the amount due.
     */
    public function amountDue(): string
    {
        return Cashier::formatAmount($this->attributes['amount_due'] ?? 0, $this->attributes['currency'] ?? 'myr');
    }

    /**
     * Get the raw amount due.
     */
    public function rawAmountDue(): int
    {
        return $this->attributes['amount_due'] ?? 0;
    }

    /**
     * Get the invoice's status.
     */
    public function status(): string
    {
        return $this->attributes['status'] ?? 'draft';
    }

    /**
     * Get the invoice's currency.
     */
    public function currency(): string
    {
        return strtoupper($this->attributes['currency'] ?? 'MYR');
    }

    /**
     * Determine if the invoice is paid.
     */
    public function paid(): bool
    {
        return $this->status() === 'paid';
    }

    /**
     * Determine if the invoice is open (unpaid).
     */
    public function open(): bool
    {
        return $this->status() === 'open';
    }

    /**
     * Determine if the invoice is void.
     */
    public function void(): bool
    {
        return $this->status() === 'void';
    }

    /**
     * Determine if the invoice is draft.
     */
    public function draft(): bool
    {
        return $this->status() === 'draft';
    }

    /**
     * Get the invoice's date.
     */
    public function date(): Carbon
    {
        return Carbon::parse($this->attributes['date'] ?? now());
    }

    /**
     * Get the invoice's due date.
     */
    public function dueDate(): Carbon
    {
        return Carbon::parse($this->attributes['due_date'] ?? now()->addDays(30));
    }

    /**
     * Get the invoice's paid date.
     */
    public function paidAt(): ?Carbon
    {
        return $this->attributes['paid_at'] ? Carbon::parse($this->attributes['paid_at']) : null;
    }

    /**
     * Get the invoice's description.
     */
    public function description(): ?string
    {
        return $this->attributes['description'];
    }

    /**
     * Get the invoice's metadata.
     */
    public function metadata(): array
    {
        return $this->attributes['metadata'] ?? [];
    }

    /**
     * Get the invoice's line items.
     */
    public function lines(): Collection
    {
        return collect($this->attributes['lines'] ?? []);
    }

    /**
     * Get the billable entity.
     */
    public function billable()
    {
        return $this->attributes['billable'] ?? null;
    }

    /**
     * Get the underlying transaction.
     */
    public function transaction(): ?Transaction
    {
        return $this->attributes['transaction'] ?? null;
    }

    /**
     * Get the customer associated with the invoice.
     */
    public function customer(): ?Customer
    {
        if ($this->attributes['customer_id']) {
            return Customer::find($this->attributes['customer_id']);
        }

        return null;
    }

    /**
     * Get the subscription associated with the invoice.
     */
    public function subscription(): ?Subscription
    {
        if ($this->attributes['subscription_id']) {
            return Subscription::find($this->attributes['subscription_id']);
        }

        return null;
    }

    /**
     * Download the invoice as a PDF.
     */
    public function downloadPDF(array $data = [], ?string $filename = null): Response
    {
        $filename = $filename ?? "invoice-{$this->id()}.pdf";
        
        $pdf = $this->generatePDF($data);
        
        return new Response(
            $pdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]
        );
    }

    /**
     * View the invoice as a PDF in the browser.
     */
    public function viewPDF(array $data = []): Response
    {
        $pdf = $this->generatePDF($data);
        
        return new Response(
            $pdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline',
            ]
        );
    }

    /**
     * Generate PDF content.
     */
    protected function generatePDF(array $data = []): object
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new \RuntimeException(
                'PDF generation requires dompdf. Install it with: composer require dompdf/dompdf'
            );
        }

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        
        $dompdf = new \Dompdf\Dompdf($options);
        
        $html = $this->generateInvoiceHTML($data);
        $dompdf->loadHtml($html);
        
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        return $dompdf;
    }

    /**
     * Generate HTML content for the invoice.
     */
    protected function generateInvoiceHTML(array $data = []): string
    {
        $billable = $this->billable();
        $customer = $this->customer();
        
        $companyName = $data['company_name'] ?? config('app.name', 'Your Company');
        $companyAddress = $data['company_address'] ?? '';
        $companyPhone = $data['company_phone'] ?? '';
        $companyEmail = $data['company_email'] ?? '';

        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Invoice ' . $this->id() . '</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; color: #333; }
                .header { border-bottom: 2px solid #007cba; padding-bottom: 20px; margin-bottom: 30px; }
                .company-info { float: left; }
                .invoice-info { float: right; text-align: right; }
                .clearfix { clear: both; }
                .customer-info { margin: 20px 0; }
                .invoice-details { margin: 20px 0; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background-color: #f8f9fa; font-weight: bold; }
                .total-row { font-weight: bold; background-color: #f8f9fa; }
                .status { padding: 5px 10px; border-radius: 3px; font-size: 12px; }
                .status-paid { background-color: #d4edda; color: #155724; }
                .status-open { background-color: #fff3cd; color: #856404; }
                .status-void { background-color: #f8d7da; color: #721c24; }
                .status-draft { background-color: #d1ecf1; color: #0c5460; }
                .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="company-info">
                    <h2>' . htmlspecialchars($companyName) . '</h2>
                    <p>' . nl2br(htmlspecialchars($companyAddress)) . '</p>
                    <p>Phone: ' . htmlspecialchars($companyPhone) . '</p>
                    <p>Email: ' . htmlspecialchars($companyEmail) . '</p>
                </div>
                <div class="invoice-info">
                    <h1>INVOICE</h1>
                    <p><strong>Invoice #:</strong> ' . htmlspecialchars($this->id()) . '</p>
                    <p><strong>Date:</strong> ' . $this->date()->format('M j, Y') . '</p>
                    <p><strong>Due Date:</strong> ' . $this->dueDate()->format('M j, Y') . '</p>
                    <p><strong>Status:</strong> <span class="status status-' . $this->status() . '">' . ucfirst($this->status()) . '</span></p>
                </div>
                <div class="clearfix"></div>
            </div>

            <div class="customer-info">
                <h3>Bill To:</h3>
                <p><strong>' . htmlspecialchars($customer ? $customer->name() : ($billable ? ($billable->name ?? 'Customer') : 'Customer')) . '</strong></p>
                <p>' . htmlspecialchars($customer ? $customer->email() : ($billable ? ($billable->email ?? '') : '')) . '</p>
            </div>

            <div class="invoice-details">
                <table>
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>';

        foreach ($this->lines() as $line) {
            $html .= '
                        <tr>
                            <td>' . htmlspecialchars($line['description']) . '</td>
                            <td>' . $line['quantity'] . '</td>
                            <td>' . Cashier::formatAmount($line['amount'], $line['currency']) . '</td>
                            <td>' . Cashier::formatAmount($line['amount'] * $line['quantity'], $line['currency']) . '</td>
                        </tr>';
        }

        $html .= '
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="total-row">Subtotal:</td>
                            <td class="total-row">' . $this->subtotal() . '</td>
                        </tr>';

        if ($this->rawTax() > 0) {
            $html .= '
                        <tr>
                            <td colspan="3" class="total-row">Tax:</td>
                            <td class="total-row">' . $this->tax() . '</td>
                        </tr>';
        }

        $html .= '
                        <tr>
                            <td colspan="3" class="total-row">Total:</td>
                            <td class="total-row">' . $this->total() . '</td>
                        </tr>
                    </tfoot>
                </table>
            </div>';

        if ($this->paid()) {
            $html .= '
            <div class="payment-info">
                <p><strong>Paid on:</strong> ' . $this->paidAt()->format('M j, Y') . '</p>
                <p><strong>Amount Paid:</strong> ' . $this->amountPaid() . '</p>
            </div>';
        }

        $html .= '
            <div class="footer">
                <p>Thank you for your business!</p>
                <p>This invoice was generated automatically by ' . $companyName . '</p>
            </div>
        </body>
        </html>';

        return $html;
    }

    /**
     * Convert the invoice to an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'chip_id' => $this->chipId(),
            'status' => $this->status(),
            'currency' => $this->currency(),
            'total' => $this->rawTotal(),
            'subtotal' => $this->rawSubtotal(),
            'tax' => $this->rawTax(),
            'amount_paid' => $this->rawAmountPaid(),
            'amount_due' => $this->rawAmountDue(),
            'date' => $this->date()->toISOString(),
            'due_date' => $this->dueDate()->toISOString(),
            'paid_at' => $this->paidAt()?->toISOString(),
            'description' => $this->description(),
            'metadata' => $this->metadata(),
            'lines' => $this->lines()->toArray(),
        ];
    }

    /**
     * Convert the invoice to JSON.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
} 