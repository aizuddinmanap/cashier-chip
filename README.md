# Laravel Cashier Chip

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)
[![Total Downloads](https://img.shields.io/packagist/dt/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)
[![License](https://img.shields.io/packagist/l/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)

Laravel Cashier Chip provides an expressive, fluent interface to [Chip's](https://www.chip-in.asia/) payment and subscription billing services. **Fully aligned with Laravel Cashier patterns**, it handles subscription management, one-time payments, webhooks, and customer management with a familiar Laravel API.

## ✨ Recent Improvements

**Version 2.0+ includes major fixes and Laravel Cashier alignment:**

- ✅ **Fixed Critical API Issues**: Proper endpoint URLs, configuration keys, and required fields
- ✅ **Laravel Cashier Compatibility**: 100% aligned with Laravel Cashier Stripe/Paddle patterns
- ✅ **Automatic Middleware Registration**: No manual setup required
- ✅ **Modern Database Schema**: Proper column names and relationships
- ✅ **Comprehensive Testing**: Full test suite with 52 passing tests
- ✅ **No Legacy Support**: Clean, modern codebase without backward compatibility overhead

## 🚀 Features

- **Subscription Management**: Create, modify, cancel, and resume subscriptions
- **One-time Payments**: Process single charges with full transaction tracking
- **Refund Processing**: Full and partial refunds with automatic transaction linking
- **Customer Management**: Automatic customer creation and synchronization
- **Webhook Handling**: Secure webhook processing with automatic verification
- **FPX Support**: Malaysian bank transfers with real-time status checking
- **Invoice Generation**: PDF invoices with customizable templates
- **Trial Periods**: Flexible trial management for subscriptions
- **Payment Methods**: Support for cards, e-wallets, and bank transfers
- **Transaction Tracking**: Comprehensive transaction history and status management
- **Laravel Integration**: Seamless integration with Laravel's authentication and models

## 📋 Requirements

- PHP 8.2+
- Laravel 10.0+
- Chip merchant account
- MySQL/PostgreSQL database

## 🔧 Installation

Install via Composer:

```bash
composer require aizuddinmanap/cashier-chip
```

### Publish Configuration and Migrations

```bash
php artisan vendor:publish --tag="cashier-config"
php artisan vendor:publish --tag="cashier-migrations"
```

### Run Migrations

```bash
php artisan migrate
```

### Environment Configuration

Add your Chip credentials to `.env`:

```env
CHIP_API_KEY=your_api_key
CHIP_BRAND_ID=your_brand_id
CHIP_WEBHOOK_SECRET=your_webhook_secret
CASHIER_CURRENCY=MYR
```

## 🔐 Configuration

The configuration file `config/cashier.php` provides comprehensive settings:

```php
return [
    'chip' => [
        'api_key' => env('CHIP_API_KEY'),
        'brand_id' => env('CHIP_BRAND_ID'),
        'api_url' => env('CHIP_API_URL', 'https://gate.chip-in.asia/api/v1'),
    ],
    
    'webhook' => [
        'secret' => env('CHIP_WEBHOOK_SECRET'),
        'tolerance' => env('CHIP_WEBHOOK_TOLERANCE', 300),
    ],
    
    'currency' => env('CASHIER_CURRENCY', 'MYR'),
    'model' => env('CASHIER_MODEL', App\Models\User::class),
    
    // Additional configuration options...
];
```

## 👤 Preparing Your Model

Add the `Billable` trait to your User model:

```php
<?php

namespace App\Models;

use Aizuddinmanap\CashierChip\Billable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Billable;
    
    // Your existing model code...
}
```

## 💳 One-time Payments

### Creating Charges

```php
// Simple charge
$payment = $user->charge(10000); // RM 100.00 in cents

// Charge with options
$payment = $user->charge(10000, [
    'description' => 'Premium upgrade',
    'client_email' => $user->email,
    'metadata' => ['order_id' => '12345'],
]);

// Get checkout URL
$checkout = $user->newCharge(10000)->checkout([
    'success_url' => route('payment.success'),
    'cancel_url' => route('payment.cancel'),
]);

return redirect($checkout['checkout_url']);
```

### Refunding Payments

Laravel Cashier Chip provides comprehensive refund functionality with full and partial refund support, automatic transaction tracking, and webhook integration.

```php
// Full refund
$refund = $user->refund('transaction_id');

// Partial refund (RM 50.00)
$refund = $user->refund('transaction_id', 5000);

// Check refund status
if ($refund->refunded()) {
    echo "Refund processed successfully";
}

// Get refund details
$refundAmount = $refund->amount();     // "RM 50.00"
$rawAmount = $refund->rawAmount();     // 5000 (cents)
$currency = $refund->currency();       // "MYR"
$refundId = $refund->chipId();         // Chip refund ID
```

#### Refund Error Handling

```php
try {
    $refund = $user->refund('transaction_id', 5000);
    
    // Process successful refund
    if ($refund->refunded()) {
        // Notify customer
        Mail::to($user->email)->send(new RefundProcessedMail($refund));
    }
    
} catch (\Exception $e) {
    // Handle refund failure
    Log::error('Refund failed: ' . $e->getMessage());
    
    // Notify admin or handle gracefully
    return back()->with('error', 'Refund processing failed. Please try again.');
}
```

#### Refund Transaction Tracking

```php
// Get original transaction
$originalTransaction = $user->findTransaction('transaction_id');

// Process refund
$refund = $user->refund('transaction_id', 5000);

// Track refund relationship
$originalId = $refund->refunded_from;  // Links to original transaction
$refundType = $refund->type();         // "refund"
$isRefund = $refund->isRefund();       // true

// Query refund transactions
$refunds = $user->transactions()->refunds()->get();
$refundedTransactions = $user->transactions()->refunded()->get();
```

#### Refund Validation

```php
// Validate refund eligibility
$transaction = $user->findTransaction('transaction_id');

if (!$transaction) {
    throw new \Exception('Transaction not found');
}

if (!$transaction->successful()) {
    throw new \Exception('Can only refund successful transactions');
}

if ($transaction->refunded()) {
    throw new \Exception('Transaction already refunded');
}

// Check refund amount
$maxRefund = $transaction->rawAmount();
if ($refundAmount > $maxRefund) {
    throw new \Exception('Refund amount cannot exceed original transaction amount');
}
```

## 🔄 Subscriptions

### Creating Subscriptions

```php
// Basic subscription
$subscription = $user->newSubscription('default', 'price_monthly')->create();

// Subscription with trial
$subscription = $user->newSubscription('premium', 'price_yearly')
    ->trialDays(14)
    ->create();

// Subscription with metadata
$subscription = $user->newSubscription('basic', 'price_monthly')
    ->withMetadata(['source' => 'website'])
    ->create();
```

### Checking Subscription Status

```php
// Check if user has any active subscription
if ($user->subscribed()) {
    // User has active subscription
}

// Check specific subscription
if ($user->subscribed('premium')) {
    // User has active premium subscription
}

// Check if user is on trial
if ($user->onTrial()) {
    // User is on trial
}
```

### Managing Subscriptions

#### Subscription Cancellation

Laravel Cashier Chip provides comprehensive subscription cancellation functionality, following Laravel Cashier Paddle patterns.

```php
// Cancel at end of billing period (graceful cancellation)
$subscription = $user->subscription('default');
$subscription->cancel();

// Cancel immediately
$subscription = $user->subscription('default');
$subscription->cancelNow();

// Resume cancelled subscription (remove scheduled cancellation)
$subscription = $user->subscription('default');
$subscription->resume();
// or
$subscription->stopCancellation(); // Same as resume()
```

#### Cancellation Status Checking

```php
$subscription = $user->subscription('default');

// Check if subscription is cancelled
if ($subscription->cancelled()) {
    // Subscription has been cancelled (but may still be active during grace period)
}

// Check if subscription is on grace period
if ($subscription->onGracePeriod()) {
    // Subscription is cancelled but still active until ends_at
    echo "Subscription ends on " . $subscription->ends_at->format('Y-m-d');
}

// Combined status checking
if ($subscription->cancelled() && $subscription->onGracePeriod()) {
    // Subscription is cancelled but user still has access
    echo "Your subscription will end on " . $subscription->ends_at->format('Y-m-d');
} elseif ($subscription->cancelled()) {
    // Subscription has ended
    echo "Your subscription has ended";
} else {
    // Subscription is active
    echo "Your subscription is active";
}
```

#### Billable Model Convenience Methods

```php
// Cancel specific subscription by name
$user->cancelSubscription('default');     // Graceful cancellation
$user->cancelSubscription('premium');     // Cancel premium subscription

// Cancel immediately by name
$user->cancelSubscriptionNow('default');  // Immediate cancellation

// Cancel all active subscriptions
$user->cancelAllSubscriptions();          // Cancels all active subscriptions

// These methods return the subscription instance or null
$subscription = $user->cancelSubscription('premium');
if ($subscription) {
    echo "Premium subscription cancelled successfully";
}
```

#### Trial Subscription Handling

The cancellation system intelligently handles trial subscriptions:

```php
// Trial-only subscriptions (no API calls made)
$trialSubscription = $user->newSubscription('default', 'price_monthly')
    ->trialDays(14)
    ->create();

$trialSubscription->cancel(); // Local cancellation only, no CHIP API call

// Paid subscriptions (full API integration)
$paidSubscription = $user->newSubscription('premium', 'price_yearly')
    ->create();

$paidSubscription->cancel(); // Makes API call to CHIP + local cancellation
```

#### Advanced Cancellation Examples

```php
// Check subscription status and handle accordingly
$subscription = $user->subscription('default');

if ($subscription && $subscription->active()) {
    if ($subscription->onTrial()) {
        // Cancel trial immediately (no billing impact)
        $subscription->cancelNow();
        $message = "Trial cancelled immediately";
    } else {
        // Cancel at end of billing period (user keeps access)
        $subscription->cancel();
        $message = "Subscription cancelled. Access until " . $subscription->ends_at->format('M d, Y');
    }
}

// Bulk cancellation with notifications
$cancelledCount = 0;
$user->subscriptions()->active()->each(function ($subscription) use (&$cancelledCount) {
    $subscription->cancel();
    $cancelledCount++;
});

if ($cancelledCount > 0) {
    // Send cancellation notification email
    Mail::to($user->email)->send(new SubscriptionsCancelledMail($cancelledCount));
}

// Restore cancelled subscription
$subscription = $user->subscription('default');
if ($subscription->cancelled() && $subscription->onGracePeriod()) {
    $subscription->resume();
    $message = "Subscription restored successfully";
}
```

#### Error Handling

```php
try {
    $subscription = $user->subscription('default');
    
    if (!$subscription) {
        throw new \Exception('Subscription not found');
    }
    
    if (!$subscription->active()) {
        throw new \Exception('Cannot cancel inactive subscription');
    }
    
    $subscription->cancel();
    
    // Dispatch custom event
    event(new SubscriptionCancellationRequested($user, $subscription));
    
} catch (\Exception $e) {
    Log::error('Subscription cancellation failed: ' . $e->getMessage());
    return back()->with('error', 'Failed to cancel subscription. Please try again.');
}
```

#### Subscription Plan Changes

```php
// Change subscription plan
$user->subscription('default')->swap('price_yearly');

// Change plan with prorating
$user->subscription('default')->swapAndInvoice('price_yearly');
```

## 🏦 FPX (Malaysian Bank Transfers)

### FPX Payments

```php
use Aizuddinmanap\CashierChip\FPX;

// Create FPX payment
$checkout = FPX::createPayment(10000, 'MYR'); // RM 100.00

// Direct bank selection
$checkout = FPX::payWithBank(10000, 'maybank2u');

// Get supported banks
$banks = FPX::getSupportedBanks();

// Check bank availability
$status = FPX::getBankStatus('maybank2u');
```

### Real-time FPX Status

```php
// Get comprehensive FPX status
$status = $user->getFPXSystemStatus();

// Check if FPX is available
if ($user->supportsFPX()) {
    // FPX is available for this user
}

// Get banks with live status
$banks = $user->getFPXBanksWithStatus();
```

## 🔔 Webhooks

### Automatic Setup

Webhooks are **automatically registered** - no manual middleware setup required! The package handles:

- ✅ Automatic middleware registration
- ✅ Signature verification
- ✅ Event processing
- ✅ Error handling

### Webhook Management

```php
// Create webhook via Artisan command
php artisan cashier:webhook create

// List existing webhooks
php artisan cashier:webhook list

// Delete webhook
php artisan cashier:webhook delete
```

### Webhook Events

The package automatically handles these webhook events:

- `purchase.completed` - Payment completed successfully
- `purchase.failed` - Payment failed or was declined
- `purchase.refunded` - Payment was refunded (full or partial)
- `subscription.created` - New subscription activated
- `subscription.updated` - Subscription plan or status changes
- `subscription.cancelled` - Subscription cancelled or expired

#### Webhook Event Handling

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

## 💰 Transaction Management

Laravel Cashier Chip provides comprehensive transaction tracking and management capabilities.

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

### Transaction Relationships

```php
// Get customer associated with transaction
$customer = $transaction->customer();

// Get billable model (User) associated with transaction
$user = $transaction->billable;

// For refund transactions, get original transaction
$refund = $user->transactions()->refunds()->first();
$originalTransaction = $user->findTransaction($refund->refunded_from);
```

## 📊 Customer Management

### Customer Creation

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

// Sync customer data
$user->syncChipCustomerData();
```

## 🧾 Invoices

### Invoice Generation

```php
// Create invoice
$invoice = $user->invoiceFor('Premium Subscription', 10000);

// Get invoice PDF
$pdf = $invoice->downloadPDF();

// Get all invoices
$invoices = $user->invoices();

// Find specific invoice
$invoice = $user->findInvoice('invoice_id');
```

## 🔍 Testing

### Running Tests

```bash
composer test
```

### Test Coverage

The package includes comprehensive tests:

- ✅ 52 passing tests
- ✅ API integration tests
- ✅ Database schema tests
- ✅ Webhook processing tests
- ✅ FPX functionality tests
- ✅ Refund processing tests (full and partial)
- ✅ Transaction tracking tests
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

## 🗄️ Database Schema

Laravel Cashier Chip uses a well-structured database schema to track all payment and subscription data.

### Required Migrations

The package includes these migrations:

```bash
2024_01_01_000001_add_chip_customer_columns.php    # Adds chip_id to users table
2024_01_01_000002_create_subscriptions_table.php   # Subscription management
2024_01_01_000003_create_customers_table.php       # Customer data
2024_01_01_000003_create_subscription_items_table.php # Subscription items
2024_01_01_000004_create_transactions_table.php    # Transaction tracking
```

### Transactions Table Schema

```sql
-- The transactions table handles all payment and refund records
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
    updated_at TIMESTAMP,
    
    INDEX(billable_type, billable_id),
    INDEX(customer_id),
    INDEX(status),
    INDEX(type),
    INDEX(chip_id),
    FOREIGN KEY(customer_id) REFERENCES customers(id)
);
```

### Refund Transaction Tracking

```php
// Example of refund transaction relationship
$originalTransaction = [
    'id' => 'txn_original_123',
    'chip_id' => 'purchase_456',
    'type' => 'charge',
    'status' => 'success',
    'total' => 10000,
    'refunded_from' => null,
];

$refundTransaction = [
    'id' => 'txn_refund_789',
    'chip_id' => 'refund_101',
    'type' => 'refund',
    'status' => 'refunded',
    'total' => 5000,
    'refunded_from' => 'txn_original_123',  // Links to original
];
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
$formatted = Cashier::formatAmount(10000, 'MYR'); // "RM 100.00"

// Use custom currency
Cashier::useCurrency('USD', 'en_US');
```

## 🐛 Troubleshooting

### Common Issues

1. **Missing Client Email**: Ensure all API calls include client email
2. **Webhook Verification**: Check webhook secret configuration
3. **Database Columns**: Use `total` instead of `amount` for transactions
4. **API Endpoints**: All endpoints use trailing slashes

### Refund-Specific Issues

1. **Refund Amount Exceeds Original**: Ensure refund amount doesn't exceed original transaction amount
2. **Transaction Not Found**: Verify transaction ID exists and belongs to the user
3. **Already Refunded**: Check if transaction has already been refunded before processing
4. **Refund API Failures**: Check Chip API credentials and network connectivity

```php
// Debug refund issues
$transaction = $user->findTransaction('transaction_id');

if (!$transaction) {
    Log::error("Transaction not found: transaction_id");
    return;
}

Log::info("Transaction status: " . $transaction->status);
Log::info("Transaction type: " . $transaction->type());
Log::info("Refund eligible: " . ($transaction->successful() ? 'Yes' : 'No'));
```

### Webhook Debugging

```php
// Add webhook debugging in your EventServiceProvider
Event::listen(\Aizuddinmanap\CashierChip\Events\WebhookReceived::class, function ($event) {
    Log::info('Webhook received', [
        'event_type' => $event->payload['event_type'] ?? 'unknown',
        'payload' => $event->payload,
    ]);
});
```

### Debug Mode

Enable debug logging in your configuration:

```php
'logging' => [
    'enabled' => true,
    'channel' => 'daily',
],
```

## 📚 Laravel Cashier Compatibility

This package is **100% compatible** with Laravel Cashier patterns:

| Feature | Laravel Cashier | Cashier Chip |
|---------|-----------------|--------------|
| Billable Trait | ✅ | ✅ |
| Subscriptions | ✅ | ✅ |
| One-time Payments | ✅ | ✅ |
| Webhooks | ✅ | ✅ |
| Customer Management | ✅ | ✅ |
| Invoices | ✅ | ✅ |
| Trials | ✅ | ✅ |
| Method Signatures | ✅ | ✅ |

## 🤝 Contributing

We welcome contributions! Please see our [Contributing Guide](CONTRIBUTING.md) for details.

### Development Setup

```bash
git clone https://github.com/aizuddinmanap/cashier-chip.git
cd cashier-chip
composer install
composer test
```

## 📄 License

Laravel Cashier Chip is open-sourced software licensed under the [MIT license](LICENSE.md).

## 🙏 Credits

- **Aizuddin Manap** - Original author and maintainer
- **Laravel Cashier** - Inspiration and API patterns
- **Chip** - Payment processing platform
- **Laravel Community** - Framework and ecosystem

## 📞 Support

- **Documentation**: [Full documentation](https://github.com/aizuddinmanap/cashier-chip/wiki)
- **Issues**: [GitHub Issues](https://github.com/aizuddinmanap/cashier-chip/issues)
- **Discussions**: [GitHub Discussions](https://github.com/aizuddinmanap/cashier-chip/discussions)

---