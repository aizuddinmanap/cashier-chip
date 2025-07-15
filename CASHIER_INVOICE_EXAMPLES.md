# Laravel Cashier Chip - Invoice Examples

## Overview

CashierChip now fully supports Laravel Cashier's invoice patterns while using CashierChip's transaction-based system internally. This guide shows how to use the invoice methods that are now 100% compatible with Laravel Cashier.

## ✅ Your Discovery: Transactions as Invoices

As you correctly identified:
- **CashierChip stores billing data as "transactions"** - not invoices
- **Your 18 transactions worth MYR 29.90 each** - these are your invoices!
- **The `invoices()` method now works** - it converts transactions to invoices
- **Perfect Laravel Cashier alignment** - same API, different data source

## 🧾 Working with Invoices

### Basic Invoice Operations

```php
// Get all paid invoices (successful transactions)
$invoices = $user->invoices();

// Get all invoices including pending ones
$allInvoices = $user->invoices(true);

// Get specific invoice
$invoice = $user->findInvoice('txn_123');

// Get latest invoice
$latestInvoice = $user->latestInvoice();

// Get upcoming invoice
$upcomingInvoice = $user->upcomingInvoice();
```

### Invoice Properties - Laravel Cashier Compatible

```php
$invoice = $user->findInvoice('txn_123');

// Basic properties
$invoice->id();              // "txn_123"
$invoice->total();           // "RM 29.90"
$invoice->rawTotal();        // 2990 (cents)
$invoice->currency();        // "MYR"
$invoice->status();          // "paid", "open", "void", "draft"

// Dates
$invoice->date();            // Carbon date
$invoice->dueDate();         // Carbon due date  
$invoice->paidAt();          // Carbon paid date (if paid)

// Status checks
$invoice->paid();            // true/false
$invoice->open();            // true/false (unpaid)
$invoice->void();            // true/false (failed/refunded)
$invoice->draft();           // true/false (pending)

// Line items
$invoice->lines();           // Collection of line items
$invoice->description();     // Invoice description
$invoice->metadata();        // Array of metadata
```

### Your 18 Transactions as Invoices

```php
// Get your 18 invoices (successful transactions)
$yourInvoices = $user->invoices();

echo "You have " . $yourInvoices->count() . " invoices\n";

foreach ($yourInvoices as $invoice) {
    echo "Invoice {$invoice->id()}: {$invoice->total()} - {$invoice->status()}\n";
    // Output: Invoice txn_123: RM 29.90 - paid
}

// Calculate total billing
$totalBilled = $yourInvoices->sum(fn($invoice) => $invoice->rawTotal());
echo "Total billed: " . Cashier::formatAmount($totalBilled) . "\n";
// Output: Total billed: RM 538.20 (18 × RM 29.90)
```

### Invoice Queries and Filtering

```php
// Get invoices for specific period
$startDate = Carbon::now()->startOfMonth();
$endDate = Carbon::now()->endOfMonth();
$monthlyInvoices = $user->invoicesForPeriod($startDate, $endDate);

// Get invoices for specific year
$yearlyInvoices = $user->invoicesForYear(2024);

// Get total amount for period
$monthlyTotal = $user->invoiceTotalForPeriod($startDate, $endDate);
```

## 📊 Transaction Query Scopes (Laravel Cashier Style)

Since invoices are backed by transactions, you can use transaction scopes:

```php
// Get successful transactions (paid invoices)
$paidInvoices = $user->transactions()->successful()->get()
    ->map(fn($txn) => $user->convertTransactionToInvoice($txn));

// Get failed transactions (void invoices)
$voidInvoices = $user->transactions()->failed()->get()
    ->map(fn($txn) => $user->convertTransactionToInvoice($txn));

// Get refunded transactions
$refundedInvoices = $user->transactions()->refunded()->get()
    ->map(fn($txn) => $user->convertTransactionToInvoice($txn));

// Filter by amount
$highValueInvoices = $user->transactions()
    ->successful()
    ->minAmount(5000) // RM 50+
    ->get()
    ->map(fn($txn) => $user->convertTransactionToInvoice($txn));

// Filter by date range
$recentInvoices = $user->transactions()
    ->successful()
    ->forPeriod(Carbon::now()->subMonth(), Carbon::now())
    ->get()
    ->map(fn($txn) => $user->convertTransactionToInvoice($txn));
```

## 📄 PDF Invoice Generation

### Download Invoice PDF

```php
// Download invoice as PDF
$invoice = $user->findInvoice('txn_123');
return $invoice->downloadPDF();

// Download with custom filename
return $invoice->downloadPDF([], 'my-invoice-123.pdf');

// Download with company data
return $invoice->downloadPDF([
    'company_name' => 'Your Company Ltd',
    'company_address' => '123 Business Street\nKuala Lumpur, Malaysia',
    'company_phone' => '+60 3-1234 5678',
    'company_email' => 'billing@yourcompany.com'
]);
```

### View Invoice PDF in Browser

```php
// View in browser
$invoice = $user->findInvoice('txn_123');
return $invoice->viewPDF();
```

### Controller Example

```php
class InvoiceController extends Controller
{
    public function download(Request $request, $invoiceId)
    {
        $user = $request->user();
        
        // This works exactly like Laravel Cashier!
        return $user->downloadInvoice($invoiceId, [
            'company_name' => config('app.name'),
            'company_address' => config('company.address'),
            'company_phone' => config('company.phone'),
            'company_email' => config('company.email'),
        ]);
    }
    
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Get all invoices (Laravel Cashier compatible)
        $invoices = $user->invoices();
        
        return view('invoices.index', compact('invoices'));
    }
}
```

## 🎯 Creating New Invoices

### Create Invoice for Specific Amount

```php
// Create invoice (Laravel Cashier compatible)
$invoice = $user->invoiceFor('Premium Service', 2990); // RM 29.90

// Create with options
$invoice = $user->invoiceFor('Custom Service', 5000, [
    'currency' => 'MYR',
    'metadata' => ['service_type' => 'premium'],
    'chip_id' => 'custom_invoice_123'
]);
```

### Create General Invoice

```php
// Create general invoice
$invoice = $user->invoice([
    'amount' => 2990,
    'description' => 'Service Invoice',
    'metadata' => ['period' => 'monthly']
]);
```

## 🔍 Laravel Cashier Alignment Examples

### Exact Laravel Cashier API Usage

```php
// These work exactly like Laravel Cashier Stripe/Paddle:

// Get all invoices
$invoices = $user->invoices();

// Get upcoming invoice
$upcoming = $user->upcomingInvoice();

// Find specific invoice
$invoice = $user->findInvoice('txn_123');

// Download PDF
return $user->downloadInvoice('txn_123');

// Create invoice
$invoice = $user->invoiceFor('Service', 2990);

// Check invoice status
if ($invoice->paid()) {
    echo "Invoice is paid!";
}

// Get invoice total
echo "Total: " . $invoice->total();
```

## 🎨 Blade Template Examples

### Invoice List Template

```blade
@extends('layouts.app')

@section('content')
<div class="container">
    <h1>My Invoices</h1>
    
    @if($invoices->count() > 0)
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices as $invoice)
                        <tr>
                            <td>{{ $invoice->id() }}</td>
                            <td>{{ $invoice->date()->format('M j, Y') }}</td>
                            <td>{{ $invoice->total() }}</td>
                            <td>
                                <span class="badge badge-{{ $invoice->paid() ? 'success' : 'warning' }}">
                                    {{ ucfirst($invoice->status()) }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('invoices.download', $invoice->id()) }}" 
                                   class="btn btn-sm btn-primary">
                                    Download PDF
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="alert alert-info">
            No invoices found.
        </div>
    @endif
</div>
@endsection
```

### Invoice Detail Template

```blade
@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h3>Invoice {{ $invoice->id() }}</h3>
                    <span class="badge badge-{{ $invoice->paid() ? 'success' : 'warning' }}">
                        {{ ucfirst($invoice->status()) }}
                    </span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Date:</strong> {{ $invoice->date()->format('M j, Y') }}</p>
                            <p><strong>Due Date:</strong> {{ $invoice->dueDate()->format('M j, Y') }}</p>
                            @if($invoice->paid())
                                <p><strong>Paid:</strong> {{ $invoice->paidAt()->format('M j, Y') }}</p>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <p><strong>Total:</strong> {{ $invoice->total() }}</p>
                            <p><strong>Currency:</strong> {{ $invoice->currency() }}</p>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h4>Line Items</h4>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Quantity</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoice->lines() as $line)
                                <tr>
                                    <td>{{ $line['description'] }}</td>
                                    <td>{{ $line['quantity'] }}</td>
                                    <td>{{ Cashier::formatAmount($line['amount'], $line['currency']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    
                    <div class="text-right">
                        <p><strong>Total: {{ $invoice->total() }}</strong></p>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="{{ route('invoices.download', $invoice->id()) }}" 
                       class="btn btn-primary">
                        Download PDF
                    </a>
                    <a href="{{ route('invoices.index') }}" 
                       class="btn btn-secondary">
                        Back to Invoices
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
```

## 🚀 Routes Example

```php
// routes/web.php
Route::middleware(['auth'])->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{id}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{id}/download', [InvoiceController::class, 'download'])->name('invoices.download');
});
```

## 💡 Key Benefits

1. **100% Laravel Cashier Compatible** - Same API as Stripe/Paddle
2. **Uses Your Transaction Data** - Converts existing transactions to invoices
3. **PDF Generation** - Professional invoice PDFs with company branding
4. **Query Scopes** - Powerful filtering and searching capabilities
5. **Status Management** - Proper invoice statuses (paid, open, void, draft)
6. **Laravel-style Methods** - Familiar Laravel patterns and conventions

## 🎯 Migration from Direct Transaction Usage

### Before (Direct Transaction Usage)
```php
// Old way - direct transactions
$transactions = $user->transactions()->successful()->get();
foreach ($transactions as $transaction) {
    echo $transaction->amount();
}
```

### After (Laravel Cashier Compatible)
```php
// New way - Laravel Cashier compatible
$invoices = $user->invoices();
foreach ($invoices as $invoice) {
    echo $invoice->total();
}
```

Both approaches work, but the invoice approach is now Laravel Cashier compatible and provides additional features like PDF generation and proper status management.

## 🔧 Installation Requirements

**PDF generation requires an optional dependency**. Install with:

```bash
composer require dompdf/dompdf
```

**CashierChip supports both dompdf 2.x and 3.x**, allowing you to choose your preferred version:

```bash
# For dompdf 2.x (stable)
composer require "dompdf/dompdf:^2.0"

# For dompdf 3.x (latest)
composer require "dompdf/dompdf:^3.0"
```

Following Laravel Cashier Stripe's approach, PDF generation is **optional** - install only if you need invoice PDFs.

## 📋 Summary

Your CashierChip implementation now provides:
- ✅ **Full Laravel Cashier API compatibility**
- ✅ **Transaction-to-invoice conversion**
- ✅ **PDF invoice generation**
- ✅ **Query scopes and filtering**
- ✅ **Status management**
- ✅ **Your 18 transactions work as invoices**

The system maintains CashierChip's transaction-based architecture while providing Laravel Cashier's invoice-based API - the best of both worlds! 