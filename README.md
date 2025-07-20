# Laravel Cashier Chip

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)
[![Total Downloads](https://img.shields.io/packagist/dt/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)
[![License](https://img.shields.io/packagist/l/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)

Laravel Cashier Chip provides an expressive, fluent interface to [Chip's](https://www.chip-in.asia/) payment and subscription billing services. **Now with 100% Laravel Cashier API compatibility**, it seamlessly bridges CashierChip's transaction-based architecture with Laravel Cashier's familiar invoice patterns.

## 🎉 **Stable Release: v1.0.15**

**Production-ready with comprehensive bug fixes and enhanced test coverage:**

- ✅ **All 72 Tests Passing** - Comprehensive test coverage with 273+ assertions  
- ✅ **PHPUnit 11 Fully Compatible** - Zero deprecations remaining (down from 71!)
- ✅ **PDF Date Formatting Fixed** - No more "format() on null" errors when paid_at is null
- ✅ **PDF Generation Fixed** - No more null pointer errors in PDF generation when billable entity is null  
- ✅ **Timestamp Fields Fixed** - Invoice objects now have proper `created_at` and `updated_at` fields
- ✅ **Laravel View Compatibility** - No more null pointer errors in Blade templates
- ✅ **Robust Error Handling** - Graceful fallbacks for missing customer/billable data
- ✅ **PHPUnit 11 Compatible** - Modern test attributes, zero deprecations (down from 71!)
- ✅ **Database Compatibility** - Works with both old and new transaction table schemas
- ✅ **Metadata System Fixed** - Resolved circular reference and array conversion issues  
- ✅ **Invoice Generation Stable** - Transaction-to-invoice conversion working perfectly
- ✅ **Currency Display Fixed** - Malaysian Ringgit properly displays as "RM 29.90"
- ✅ **PDF Generation Working** - Optional dompdf integration with error handling
- ✅ **Dynamic Pricing** - No more hardcoded amounts, uses actual subscription pricing
- ✅ **Laravel Cashier Compatible** - 100% API compatibility verified

## ✨ Laravel Cashier Invoice Alignment

**CashierChip v1.0.12+ includes full Laravel Cashier compatibility:**

- ✅ **Perfect Laravel Cashier API** - Same methods as Stripe/Paddle Cashier
- ✅ **Transaction-to-Invoice Bridge** - Your transactions work as invoices automatically  
- ✅ **PDF Invoice Generation** - Professional PDFs with company branding (optional)
- ✅ **Query Scopes & Filtering** - Powerful invoice management capabilities
- ✅ **Status Management** - Proper invoice statuses (paid, open, void, draft)
- ✅ **Zero Breaking Changes** - Existing transaction code still works

### 🔄 The CashierChip Difference

**Unlike other Laravel Cashier packages:**
- **Stripe/Paddle Cashier** - Uses external API for invoice data
- **CashierChip** - Stores billing data as transactions locally, converts to invoices on-demand

**This means:**
- ✅ **Faster Performance** - No external API calls for invoice listing
- ✅ **Offline Compatibility** - Works without internet for invoice views  
- ✅ **Full Data Control** - All billing data in your database
- ✅ **Laravel Cashier Compatible** - Same API, better performance

## 🚀 Features

- **Laravel Cashier Compatibility**: 100% compatible API with Stripe/Paddle Cashier
- **Transaction-Based Billing**: Fast, local storage of all payment data
- **Invoice Generation**: Convert transactions to invoices with optional PDF export
- **Subscription Management**: Create, modify, cancel, and resume subscriptions
- **One-time Payments**: Process single charges with full transaction tracking
- **Refund Processing**: Full and partial refunds with automatic transaction linking
- **Customer Management**: Automatic customer creation and synchronization
- **Webhook Handling**: Secure webhook processing with automatic verification
- **FPX Support**: Malaysian bank transfers with real-time status checking
- **Optional PDF Generation**: Customizable invoice templates with company branding (requires dompdf)

## 📦 Installation

Install via Composer:

```bash
composer require aizuddinmanap/cashier-chip
```

### Publish and Run Migrations

```bash
php artisan vendor:publish --tag="cashier-migrations"
php artisan migrate
```

> **Note:** The plans table migration is included but optional. You can skip it if you don't need local plan management and prefer to use Chip API directly for plan data.

### Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag="cashier-config"
```

### Optional Dependencies

For PDF invoice generation, install dompdf:

```bash
composer require dompdf/dompdf
```

CashierChip works with both dompdf 2.x and 3.x, so you can choose your preferred version.

## ⚙️ Configuration

Add your Chip credentials to your `.env` file:

```env
CHIP_API_KEY=your_chip_api_key
CHIP_BRAND_ID=your_chip_brand_id
CHIP_WEBHOOK_SECRET=your_webhook_secret
```

### Add Billable Trait

Add the `Billable` trait to your User model:

```php
use Aizuddinmanap\CashierChip\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

### Add Database Columns

Add a migration to add the required column to your users table:

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('chip_id')->nullable()->index();
});
```

## 🧾 Working with Invoices (Laravel Cashier Compatible)

### Basic Invoice Operations

CashierChip automatically converts your transactions to invoices with full Laravel Cashier API compatibility:

```php
// Get all paid invoices (successful transactions)
$invoices = $user->invoices();

// Get all invoices including pending ones  
$allInvoices = $user->invoices(true);

// Find specific invoice
$invoice = $user->findInvoice('txn_123');

// Get latest invoice
$latestInvoice = $user->latestInvoice();

// Get upcoming invoice (pending transactions)
$upcomingInvoice = $user->upcomingInvoice();

// Create new invoice
$invoice = $user->invoiceFor('Premium Service', 2990); // RM 29.90
```

### Invoice Properties - Exactly Like Laravel Cashier

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

// Line items and metadata
$invoice->lines();           // Collection of line items
$invoice->description();     // Invoice description
$invoice->metadata();        // Array of metadata
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

## 📄 PDF Invoice Generation

**Note**: PDF generation requires an optional dependency. Install with:

```bash
composer require dompdf/dompdf
```

CashierChip supports both dompdf 2.x and 3.x versions, giving you flexibility in choosing your preferred version.

### Download & View Invoices

```php
// Download invoice as PDF
$invoice = $user->findInvoice('txn_123');
return $invoice->downloadPDF();

// Download with custom filename
return $invoice->downloadPDF([], 'my-invoice-123.pdf');

// View in browser
return $invoice->viewPDF();

// Download with company branding
return $invoice->downloadPDF([
    'company_name' => 'Your Company Ltd',
    'company_address' => '123 Business Street\nKuala Lumpur, Malaysia',
    'company_phone' => '+60 3-1234 5678',
    'company_email' => 'billing@yourcompany.com'
]);
```

### Controller Example

```php
class InvoiceController extends Controller
{
    public function download(Request $request, $invoiceId)
    {
        $user = $request->user();
        
        // Works exactly like Laravel Cashier Stripe/Paddle!
        return $user->downloadInvoice($invoiceId, [
            'company_name' => config('app.name'),
            'company_address' => config('company.address'),
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

## 💰 Transaction Management (Core CashierChip)

While invoices provide Laravel Cashier compatibility, transactions remain the core of CashierChip's fast, local billing system:

### Transaction Queries

```php
// Get all transactions
$transactions = $user->transactions()->get();

// Get successful transactions only
$successfulTransactions = $user->transactions()->successful()->get();

// Get failed transactions
$failedTransactions = $user->transactions()->failed()->get();

// Get refunded transactions
$refundedTransactions = $user->transactions()->refunded()->get();

// Get refund transactions
$refunds = $user->transactions()->refunds()->get();

// Get charges only
$charges = $user->transactions()->charges()->get();

// Get transactions by type
$subscriptionCharges = $user->transactions()->ofType('subscription')->get();
```

### Transaction Status Checking

```php
$transaction = $user->findTransaction('transaction_id');

// Check transaction status
if ($transaction->successful()) {
    // Transaction completed successfully
}

if ($transaction->failed()) {
    // Transaction failed
}

if ($transaction->pending()) {
    // Transaction still processing
}

if ($transaction->refunded()) {
    // Transaction has been refunded
}
```

### Transaction Details

```php
$transaction = $user->findTransaction('transaction_id');

// Get formatted amounts
$amount = $transaction->amount();        // "RM 100.00"
$rawAmount = $transaction->rawAmount();  // 10000 (cents)
$currency = $transaction->currency();    // "MYR"

// Get transaction metadata
$chipId = $transaction->chipId();        // Chip transaction ID
$type = $transaction->type();            // "charge" or "refund"
$paymentMethod = $transaction->paymentMethod(); // "fpx", "card", etc.
$metadata = $transaction->metadata();    // Custom metadata array

// Get Money object for calculations
$money = $transaction->asMoney();
$formatted = $money->format();           // Formatted with Money library
```

## 💳 One-Time Payments

### Simple Charge

```php
// Charge a customer
$transaction = $user->charge(2990); // RM 29.90

// Charge with options
$transaction = $user->charge(2990, [
    'description' => 'Premium Service',
    'metadata' => ['service_type' => 'premium'],
]);
```

### Using Payment Builder

```php
$payment = $user->newCharge(2990)
    ->withDescription('Monthly Subscription')
    ->withMetadata(['plan' => 'premium'])
    ->create();

// Get payment URL for customer
$paymentUrl = $payment->url();
```

### Create Checkout Session

```php
// Simple checkout
$checkout = Checkout::forAmount(2990, 'MYR')
    ->client('customer@example.com', 'John Doe')
    ->successUrl('https://yoursite.com/success')
    ->cancelUrl('https://yoursite.com/cancel')
    ->create();

// Redirect customer to payment
return redirect($checkout['checkout_url']);
```

## 📋 Subscriptions

### Creating Subscriptions

```php
// Create subscription
$subscription = $user->newSubscription('default', 'price_monthly_premium')
    ->trialDays(14)
    ->create();

// Create subscription with immediate charge
$subscription = $user->newSubscription('default', 'price_monthly_premium')
    ->skipTrial()
    ->create();
```

### Checking Subscription Status

```php
// Check if user has active subscription
if ($user->subscribed('default')) {
    // User has active subscription
}

// Check specific price
if ($user->subscribedToPrice('price_monthly_premium', 'default')) {
    // User is subscribed to this specific price
}

// Check if on trial
if ($user->onTrial('default')) {
    // User is on trial
}

// Check if subscription is active
if ($user->subscription('default')->active()) {
    // Subscription is active
}
```

### Managing Subscriptions

```php
// Cancel subscription (at period end)
$user->subscription('default')->cancel();

// Cancel immediately
$user->subscription('default')->cancelNow();

// Resume cancelled subscription
$user->subscription('default')->resume();

// Change subscription price
$user->subscription('default')->swap('new_price_id');

// Update quantity
$user->subscription('default')->updateQuantity(5);
```

## 🔄 Refunds

### Processing Refunds

```php
// Full refund
$refund = $user->refund('transaction_id');

// Partial refund
$refund = $user->refund('transaction_id', 1000); // RM 10.00

// Refund using transaction object
$transaction = $user->findTransaction('transaction_id');
$refund = $transaction->refund(500); // RM 5.00
```

### Refund Information

```php
$transaction = $user->findTransaction('transaction_id');

// Check if can be refunded
if ($transaction->canBeRefunded()) {
    // Transaction can be refunded
}

// Get refundable amount
$refundableAmount = $transaction->refundableAmount();

// Get total refunded amount
$totalRefunded = $transaction->totalRefunded();

// Get all refunds for this transaction
$refunds = $transaction->refunds();
```

## 📊 Customer Management

### Customer Creation and Updates

```php
// Create Chip customer
$customer = $user->createAsChipCustomer([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

// Update customer
$customer = $user->updateChipCustomer([
    'name' => 'John Smith',
]);

// Get customer
$customer = $user->asChipCustomer();
```

### Customer Information

```php
// Check if user has Chip customer ID
if ($user->hasChipId()) {
    $chipId = $user->chipId();
}

// Sync customer data with Chip
$user->syncChipCustomerData();
```

## 🔗 FPX (Malaysian Bank Transfer)

### Create FPX Payment

```php
// Create FPX payment
$fpx = FPX::forAmount(2990) // RM 29.90
    ->bank('maybank2u') // Maybank
    ->client('customer@example.com', 'John Doe')
    ->successUrl('https://yoursite.com/success')
    ->cancelUrl('https://yoursite.com/cancel')
    ->create();

// Redirect to bank
return redirect($fpx['checkout_url']);
```

### FPX Bank List

```php
// Get available banks
$banks = FPX::banks();

foreach ($banks as $bankCode => $bankName) {
    echo "{$bankCode}: {$bankName}";
}
```

### Check FPX Status

```php
// Check payment status
$status = FPX::status('purchase_id');

if ($status['status'] === 'success') {
    // Payment completed
}
```

## 🎣 Webhooks

### Webhook Setup

CashierChip automatically registers webhook routes. The webhooks are handled at:

```
POST /chip/webhook
```

Make sure to set your webhook URL in your Chip dashboard to:
```
https://yoursite.com/chip/webhook
```

### Webhook Events

The package automatically handles these webhook events:

- `purchase.completed` - Payment completed successfully
- `purchase.failed` - Payment failed or was declined
- `purchase.refunded` - Payment was refunded (full or partial)
- `subscription.created` - New subscription activated
- `subscription.updated` - Subscription plan or status changes
- `subscription.cancelled` - Subscription cancelled or expired

### Webhook Event Handling

```php
// Listen for webhook events
Event::listen(\Aizuddinmanap\CashierChip\Events\TransactionCompleted::class, function ($event) {
    $transaction = $event->transaction;
    
    // Send confirmation email
    Mail::to($transaction->billable->email)->send(new PaymentConfirmationMail($transaction));
});

Event::listen(\Aizuddinmanap\CashierChip\Events\WebhookReceived::class, function ($event) {
    $payload = $event->payload;
    
    // Log webhook for debugging
    Log::info('Webhook received: ' . $payload['event_type']);
});
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

## 🔧 Advanced Usage

### Custom Payment Methods

```php
// Get available payment methods
$methods = $user->getAvailablePaymentMethods();

// Check specific payment method
if ($user->isPaymentMethodAvailable('fpx')) {
    // FPX is available
}
```

### Recurring Tokens

```php
// Charge with saved token
$payment = $user->chargeWithToken('purchase_id', [
    'amount' => 10000,
]);

// Delete recurring token
$user->deleteRecurringToken('purchase_id');
```

### Currency Formatting

```php
use Aizuddinmanap\CashierChip\Cashier;

// Format amount
$formatted = Cashier::formatAmount(2990); // "RM 29.90"
$formatted = Cashier::formatAmount(2990, 'USD'); // "$29.90"

// Set default currency
Cashier::useCurrency('usd', 'en_US');
```

## 💰 Plans Management

CashierChip v1.0.12+ includes an optional local plans table for better performance and developer experience. This allows you to store plan details locally instead of making API calls to fetch plan information.

### Benefits of Local Plans

- **🚀 Performance**: No external API calls to display pricing pages
- **💻 Better DX**: Rich local plan queries and relationships  
- **🎨 Flexibility**: Custom features, descriptions, sorting, promotional pricing
- **🔄 Reliability**: Works offline, no external dependencies for plan display
- **📱 Modern Pattern**: Follows Paddle/Stripe Cashier conventions

### Setting Up Plans

First, make sure you've published and run the migrations including the plans table:

```bash
php artisan vendor:publish --tag="cashier-migrations"
php artisan migrate
```

### Creating Plans

```php
use Aizuddinmanap\CashierChip\Models\Plan;

// Create a plan
Plan::create([
    'id' => 'basic_monthly',
    'chip_price_id' => 'price_abc123', // From Chip API
    'name' => 'Basic Plan',
    'description' => 'Perfect for individuals getting started',
    'price' => 29.99,
    'currency' => 'MYR',
    'interval' => 'month',
    'interval_count' => 1,
    'features' => [
        '10 Projects',
        '100 MB Storage',
        'Email Support'
    ],
    'active' => true,
    'sort_order' => 1,
]);

// Create a yearly plan
Plan::create([
    'id' => 'pro_yearly',
    'chip_price_id' => 'price_def456',
    'name' => 'Pro Plan',
    'description' => 'Best value for growing businesses',
    'price' => 299.99,
    'currency' => 'MYR',
    'interval' => 'year',
    'features' => [
        'Unlimited Projects',
        '10 GB Storage',
        'Priority Support',
        'Advanced Analytics'
    ],
    'sort_order' => 2,
]);
```

### Using Plans in Your Application

```php
// Display pricing page
$plans = Plan::active()->ordered()->get();

foreach ($plans as $plan) {
    echo $plan->name; // "Basic Plan"
    echo $plan->display_price; // "RM 29.99"
    echo $plan->formatted_interval; // "month"
    
    foreach ($plan->features_list as $feature) {
        echo "✓ {$feature}";
    }
}
```

### Creating Subscriptions with Plans

```php
// Method 1: Using plan ID (recommended)
$subscription = $user->newSubscription('default', 'basic_monthly')->create();

// Method 2: Using Plan model directly
$plan = Plan::find('pro_yearly');
$subscription = SubscriptionBuilder::forPlan($user, 'default', $plan)->create();

// Access plan from subscription
$subscription = $user->subscription('default');
$plan = $subscription->plan();
echo $plan->name; // "Pro Plan"
echo $plan->display_price; // "RM 299.99"
```

### Plan Query Methods

```php
// Get all active plans ordered by sort_order
$plans = Plan::active()->ordered()->get();

// Get plans by interval
$monthlyPlans = Plan::active()->interval('month')->get();
$yearlyPlans = Plan::active()->interval('year')->get();


// Get plans by currency
$myrPlans = Plan::byCurrency('MYR')->get();

// Get cheapest/most expensive
$cheapest = Plan::cheapest();
$premium = Plan::mostExpensive();

// Check plan features
$plan = Plan::find('basic_monthly');
if ($plan->hasFeature('Email Support')) {
    // Plan includes email support
}
```

### Plan Helper Methods

```php
$plan = Plan::find('pro_yearly');

// Price formatting
echo $plan->display_price; // "RM 299.99"
echo $plan->price_per_month; // 24.99 (for comparison)

// Interval formatting
echo $plan->formatted_interval; // "year"

// Boolean checks
$plan->isActive(); // true
$plan->isMonthly(); // false
$plan->isYearly(); // true

// Features
$plan->features_list; // Array of features
$plan->hasFeature('Advanced Analytics'); // true
```

### Building Pricing Pages

```php
// Controller
public function pricing()
{
    $monthlyPlans = Plan::active()->interval('month')->ordered()->get();
    $yearlyPlans = Plan::active()->interval('year')->ordered()->get();
    
    return view('pricing', compact('monthlyPlans', 'yearlyPlans'));
}
```

```blade
{{-- Blade template --}}
<div class="pricing-grid">
    @foreach($monthlyPlans as $plan)
        <div class="pricing-card">
            <h3>{{ $plan->name }}</h3>
            <p class="description">{{ $plan->description }}</p>
            <div class="price">{{ $plan->display_price }}</div>
            <div class="interval">per {{ $plan->formatted_interval }}</div>
            
            <ul class="features">
                @foreach($plan->features_list as $feature)
                    <li>✓ {{ $feature }}</li>
                @endforeach
            </ul>
            
            <a href="{{ route('subscribe', $plan->id) }}" class="btn">
                Choose {{ $plan->name }}
            </a>
        </div>
    @endforeach
</div>
```

### Relationship with Subscriptions

```php
// Get all subscriptions for a plan
$plan = Plan::find('basic_monthly');
$subscriptions = $plan->subscriptions;

// Get plan from subscription
$subscription = $user->subscription('default');
$plan = $subscription->plan();

if ($plan) {
    echo "Subscribed to: {$plan->name}";
    echo "Price: {$plan->display_price}/{$plan->formatted_interval}";
}
```

### Migration Without Plans Table

If you prefer not to use the local plans table, you can skip the plans migration and continue using price IDs directly:

```php
// Still works without plans table
$subscription = $user->newSubscription('default', 'price_abc123')->create();
```

## 🗄️ Database Schema

CashierChip uses a well-structured database schema to track all payment and subscription data.

### Transactions Table (Core Billing Data)

```sql
CREATE TABLE transactions (
    id VARCHAR(255) PRIMARY KEY,
    chip_id VARCHAR(255) UNIQUE,
    customer_id VARCHAR(255),
    billable_type VARCHAR(255),
    billable_id BIGINT,
    type VARCHAR(255) DEFAULT 'charge',     -- 'charge', 'refund'
    status VARCHAR(255),                    -- 'pending', 'success', 'failed', 'refunded'
    currency VARCHAR(3) DEFAULT 'MYR',
    total INTEGER,                          -- Amount in cents
    payment_method VARCHAR(255),            -- 'fpx', 'card', 'ewallet'
    description TEXT,
    metadata JSON,
    refunded_from VARCHAR(255),             -- Links refunds to original transactions
    processed_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Plans Table (Optional - v1.0.12+)

```sql
CREATE TABLE plans (
    id VARCHAR(255) PRIMARY KEY,                -- e.g., 'basic_monthly', 'pro_yearly'
    chip_price_id VARCHAR(255) UNIQUE,          -- Chip's price ID from API
    name VARCHAR(255),                          -- "Basic Plan", "Pro Plan"
    description TEXT,                           -- Plan description
    price DECIMAL(10,2),                        -- 29.99
    currency VARCHAR(3) DEFAULT 'MYR',          -- MYR, USD, SGD
    interval VARCHAR(255),                      -- month, year, week, day
    interval_count INTEGER DEFAULT 1,           -- every X intervals
    features JSON,                              -- ["Feature 1", "Feature 2"]
    active BOOLEAN DEFAULT 1,                   -- is plan available
    sort_order INTEGER DEFAULT 0,               -- display order
    stripe_price_id VARCHAR(255),               -- future multi-gateway support
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX idx_active_sort (active, sort_order),
    INDEX idx_currency_active (currency, active),
    INDEX idx_interval (interval)
);
```

### Migration Files Included

```bash
2024_01_01_000001_add_chip_customer_columns.php    # Adds chip_id to users table
2024_01_01_000002_create_subscriptions_table.php   # Subscription management
2024_01_01_000003_create_customers_table.php       # Customer data
2024_01_01_000003_create_subscription_items_table.php # Subscription items
2024_01_01_000004_create_transactions_table.php    # Transaction tracking (core)
2024_01_01_000005_create_plans_table.php           # Plans management (optional)
```

## 🔍 Testing

### Running Tests

```bash
composer test
```

### Test Coverage

The package includes comprehensive tests:

- ✅ 60+ passing tests
- ✅ Laravel Cashier API compatibility tests
- ✅ Transaction-to-invoice conversion tests
- ✅ PDF generation tests
- ✅ API integration tests
- ✅ Database schema tests
- ✅ Webhook processing tests
- ✅ FPX functionality tests
- ✅ Refund processing tests
- ✅ Customer management tests

### Test Configuration

```php
// In your tests
Http::fake([
    'api.test.chip-in.asia/api/v1/purchases/' => Http::response([
        'id' => 'purchase_123',
        'checkout_url' => 'https://checkout.chip-in.asia/123',
    ]),
]);
```

## 🔄 Migration from Direct Transaction Usage

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

Both approaches work perfectly! The invoice approach provides Laravel Cashier compatibility with additional features like PDF generation and proper status management.

## 📚 Additional Documentation

- **[CASHIER_INVOICE_EXAMPLES.md](CASHIER_INVOICE_EXAMPLES.md)** - Comprehensive invoice usage guide
- **[LARAVEL_CASHIER_ALIGNMENT.md](LARAVEL_CASHIER_ALIGNMENT.md)** - Technical alignment details
- **[LIBRARY_ASSESSMENT.md](LIBRARY_ASSESSMENT.md)** - Library analysis and improvements

## 🤝 Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## 🔒 Security

If you discover any security related issues, please email aizuddinmanap@gmail.com instead of using the issue tracker.

## 📄 License

Laravel Cashier Chip is open-sourced software licensed under the [MIT license](LICENSE.md).

## 💡 Key Benefits Recap

1. **🎯 Laravel Cashier Compatible** - Same API as Stripe/Paddle Cashier
2. **⚡ High Performance** - Local transaction storage, no external API calls for listings
3. **🧾 Professional Invoices** - PDF generation with company branding (optional dompdf)
4. **🔄 Transaction Foundation** - Fast, reliable transaction-based architecture
5. **🇲🇾 Malaysia Ready** - FPX support and MYR currency optimized
6. **🛡️ Zero Breaking Changes** - Existing code continues to work
7. **📊 Powerful Queries** - Rich filtering and reporting capabilities
8. **🎨 UI Ready** - Complete Blade templates and examples included
9. **✅ Production Stable** - v1.0.12 with all 71 tests passing (266+ assertions)
10. **🔧 Battle-Tested** - Metadata, invoice conversion, and PDF generation all verified
11. **🧪 Modern PHPUnit** - Compatible with PHPUnit 11, reduced deprecations by 98.6%
12. **🗄️ Database Flexible** - Works with both old and new transaction table schemas
13. **⏰ Timestamp Perfect** - Full Laravel timestamp field compatibility for views
14. **🛡️ Regression Protected** - Comprehensive test coverage prevents timestamp bugs

## 🐛 Troubleshooting

### PDF Generation Errors

**Issue 1**: "Call to a member function on null" when generating PDFs

**Cause**: This was a null pointer error in v1.0.12 and earlier when the billable entity was null.

**Solution**: Upgrade to v1.0.13+ which includes proper null checks:

```php
// Fixed in v1.0.13 - now safe with null billable
$invoice = $user->findInvoice('txn_123');
$response = $invoice->downloadPDF($brandingData); // No longer crashes
```

**Issue 2**: "Call to a member function format() on null" when generating PDFs

**Cause**: This was a date formatting error in v1.0.13 and earlier when `paid_at` was null.

**Solution**: Upgrade to v1.0.14+ which includes proper date null checks:

```php
// Fixed in v1.0.14 - now safe with null paid_at dates
$invoice = $user->findInvoice('txn_123');
$response = $invoice->downloadPDF($brandingData); // Shows "N/A" for null dates
```

**Workaround for older versions**: Ensure all date fields are properly set when creating invoices.

### Invoice Timestamp Errors

**Issue**: Null pointer errors accessing `$invoice->created_at` in Blade templates

**Cause**: Fixed in v1.0.12 - invoice conversion wasn't setting Laravel timestamp fields.

**Solution**: Upgrade to v1.0.12+ for proper timestamp field handling.

### Missing PDF Dependencies

**Issue**: "PDF generation requires dompdf" error

**Solution**: Install the optional PDF dependency:

```bash
composer require dompdf/dompdf
```

PDF generation is optional - only install if you need invoice PDFs.

**CashierChip v1.0.12 bridges the gap between transaction-based performance and Laravel Cashier's familiar invoice patterns - giving you the best of both worlds with production-grade stability, modern testing, and bulletproof timestamp handling!** 🚀