# Laravel Cashier Chip

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)
[![Total Downloads](https://img.shields.io/packagist/dt/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)
[![License](https://img.shields.io/packagist/l/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)

Laravel Cashier Chip provides an expressive, fluent interface to [Chip's](https://www.chip-in.asia/) payment and subscription billing services. Following Laravel Cashier patterns, it handles subscription management, one-time payments, webhooks, and customer management with a clean, Laravel-native API.

## Features

- 🔄 **Subscription Management** - Complete subscription lifecycle with trials, grace periods, and cancellations
- 💳 **One-time Payments** - Cards, FPX, e-wallets, and DuitNow QR support
- 🏦 **FPX Integration** - Real-time Malaysian banking with 18+ banks
- 🔒 **Webhook Security** - Automatic HMAC signature verification
- 💰 **Transaction Management** - Full refund support and transaction tracking
- 🎯 **Laravel Cashier Compatible** - Familiar API patterns and method signatures
- 🚀 **Auto-Configuration** - Automatic middleware registration and service setup

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, 12.x
- Chip merchant account ([Sign up here](https://www.chip-in.asia/))

## Installation

Install the package via Composer:

```bash
composer require aizuddinmanap/cashier-chip
```

### Database Migrations

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="cashier-migrations"
php artisan migrate
```

This creates the following tables:
- `customers` - Customer records linked to your billable models
- `subscriptions` - Subscription management
- `subscription_items` - Subscription line items
- `transactions` - Payment and refund records

### Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag="cashier-config"
```

Add your Chip credentials to your `.env` file:

```env
CHIP_API_KEY=your_api_key
CHIP_BRAND_ID=your_brand_id
CHIP_WEBHOOK_SECRET=your_webhook_secret
CASHIER_CURRENCY=MYR
```

### Billable Model

Add the `Billable` trait to your User model:

```php
<?php

namespace App\Models;

use Aizuddinmanap\CashierChip\Billable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Billable;
    
    // ... rest of your model
}
```

## Quick Start

### One-time Payments

```php
// Simple charge
$user->charge(5000); // RM 50.00 in cents

// Charge with options
$user->charge(10000, [
    'currency' => 'MYR',
    'description' => 'Premium subscription',
    'metadata' => ['order_id' => '12345']
]);

// Using payment builder
$transaction = $user->newCharge(10000)
    ->currency('MYR')
    ->description('Premium subscription')
    ->withMetadata(['order_id' => '12345'])
    ->create();

// Get checkout URL
$checkoutUrl = $transaction->checkout_url;
```

### Subscriptions

```php
// Create subscription
$subscription = $user->newSubscription('default', 'price_monthly')
    ->trialDays(14)
    ->quantity(2)
    ->create();

// Check subscription status
if ($user->subscribed('default')) {
    // User has active subscription
}

// Check trial status
if ($user->onTrial('default')) {
    // User is on trial
}

// Cancel subscription (with grace period)
$user->subscription('default')->cancel();

// Cancel immediately
$user->subscription('default')->cancelNow();

// Resume subscription
$user->subscription('default')->resume();
```

### Customer Management

```php
// Create Chip customer
$customer = $user->createAsChipCustomer([
    'full_name' => 'John Doe',
    'email' => 'john@example.com'
]);

// Update customer
$user->updateChipCustomer([
    'full_name' => 'John Updated'
]);

// Get customer
$customer = $user->asChipCustomer();
```

## Advanced Usage

### FPX Payments (Malaysian Banking)

```php
use Aizuddinmanap\CashierChip\FPX;

// Create FPX payment
$transaction = $user->charge(10000, [
    'payment_method' => 'fpx',
    'fpx_bank' => 'maybank2u'
]);

// Check real-time bank availability
if (FPX::isB2cAvailable()) {
    $banks = FPX::getSupportedBanks();
}

// Get banks with status
$banksWithStatus = FPX::getBanksWithStatus();
```

### Transaction Management

```php
// Find transaction
$transaction = $user->findTransaction('txn_123');

// Get all transactions
$transactions = $user->transactions()->get();

// Refund transaction
$refund = $user->refund('txn_123', 2500); // Partial refund RM 25.00

// Full refund
$refund = $user->refund('txn_123');
```

### Subscription Queries

```php
// Active subscriptions
$active = $user->subscriptions()->active()->get();

// Cancelled subscriptions
$cancelled = $user->subscriptions()->cancelled()->get();

// Subscriptions on trial
$onTrial = $user->subscriptions()->onTrial()->get();

// Subscriptions on grace period
$onGrace = $user->subscriptions()->onGracePeriod()->get();
```

## Webhooks

### Automatic Setup

Webhooks are automatically configured with secure signature verification. The webhook endpoint is automatically registered at:

```
POST /chip/webhook
```

### Webhook Events

Listen for webhook events in your `EventServiceProvider`:

```php
use Aizuddinmanap\CashierChip\Events\WebhookReceived;
use Aizuddinmanap\CashierChip\Events\TransactionCompleted;
use Aizuddinmanap\CashierChip\Events\SubscriptionUpdated;

protected $listen = [
    WebhookReceived::class => [
        // Handle any webhook
    ],
    TransactionCompleted::class => [
        // Handle completed transactions
    ],
    SubscriptionUpdated::class => [
        // Handle subscription updates
    ],
];
```

### Webhook Commands

Manage webhooks using Artisan commands:

```bash
# List all webhooks
php artisan cashier:webhook list

# Create new webhook
php artisan cashier:webhook create

# Delete webhook
php artisan cashier:webhook delete
```

## Configuration

The configuration file (`config/cashier.php`) provides comprehensive settings:

```php
return [
    // Chip API Configuration
    'chip' => [
        'api_key' => env('CHIP_API_KEY'),
        'brand_id' => env('CHIP_BRAND_ID'),
        'api_url' => env('CHIP_API_URL', 'https://gate.chip-in.asia/api/v1'),
    ],

    // Webhook Configuration
    'webhook' => [
        'secret' => env('CHIP_WEBHOOK_SECRET'),
        'tolerance' => env('CHIP_WEBHOOK_TOLERANCE', 300),
    ],

    // Currency Settings
    'currency' => env('CASHIER_CURRENCY', 'MYR'),
    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'ms_MY'),

    // Model Configuration
    'model' => env('CASHIER_MODEL', App\Models\User::class),

    // Path Configuration
    'path' => env('CASHIER_PATH', 'chip'),

    // Test Mode
    'test_mode' => env('CHIP_TEST_MODE', false),
];
```

## FPX (Malaysian Banking)

### Supported Banks

- Maybank2U
- CIMB Clicks
- Public Bank
- RHB Bank
- Hong Leong Bank
- AmBank
- Bank Islam
- Affin Bank
- Alliance Bank
- Bank Rakyat
- BSN
- HSBC Bank
- Kuwait Finance House
- Bank Muamalat
- OCBC Bank
- Standard Chartered
- UOB Bank
- Agro Bank

### Real-time Status

```php
use Aizuddinmanap\CashierChip\FPX;

// Check system status
$status = FPX::getSystemStatus();

// Check specific availability
$b2cAvailable = FPX::isB2cAvailable();  // Personal banking
$b2b1Available = FPX::isB2b1Available(); // Corporate banking

// Get banks with real-time status
$banks = FPX::getBanksWithStatus();
```

## Testing

### Running Tests

```bash
composer test
```

### Test Configuration

Set up test environment variables:

```env
CHIP_API_KEY=test_api_key
CHIP_BRAND_ID=test_brand_id
CHIP_WEBHOOK_SECRET=test_webhook_secret
CHIP_TEST_MODE=true
```

## API Reference

### Billable Methods

| Method | Description |
|--------|-------------|
| `charge($amount, $options)` | Create one-time payment |
| `newCharge($amount)` | Create payment builder |
| `refund($transactionId, $amount)` | Refund transaction |
| `findTransaction($id)` | Find transaction by ID |
| `transactions()` | Get transaction relationship |
| `newSubscription($type, $price)` | Create subscription builder |
| `subscription($type)` | Get subscription |
| `subscribed($type)` | Check if subscribed |
| `onTrial($type)` | Check if on trial |
| `createAsChipCustomer($data)` | Create Chip customer |
| `updateChipCustomer($data)` | Update customer |
| `asChipCustomer()` | Get Chip customer |
| `hasChipId()` | Check if has Chip customer ID |

### Subscription Builder Methods

| Method | Description |
|--------|-------------|
| `trialDays($days)` | Set trial period |
| `trialUntil($date)` | Set trial end date |
| `skipTrial()` | Skip trial period |
| `quantity($quantity)` | Set quantity |
| `withMetadata($metadata)` | Add metadata |
| `withOptions($options)` | Add options |
| `create()` | Create subscription |
| `add()` | Add to existing subscription |

### Transaction Builder Methods

| Method | Description |
|--------|-------------|
| `currency($currency)` | Set currency |
| `description($description)` | Set description |
| `withMetadata($metadata)` | Add metadata |
| `withOptions($options)` | Add options |
| `create()` | Create transaction |

## Migration from Other Providers

### From Laravel Cashier Stripe

```php
// Stripe
$user->charge(5000, ['currency' => 'myr']);
$user->newSubscription('default', 'price_123')->create();

// Chip equivalent  
$user->charge(5000, ['currency' => 'MYR']);
$user->newSubscription('default', 'price_123')->create();
```

### From Laravel Cashier Paddle

```php
// Paddle
$user->newSubscription('default', 'plan_123')->create();

// Chip equivalent
$user->newSubscription('default', 'price_123')->create();
```

## Laravel Cashier Compatibility

This package follows Laravel Cashier patterns and provides compatible method signatures:

- ✅ `Billable` trait with standard methods
- ✅ Subscription management with trials and grace periods
- ✅ Customer model with morphed relationships
- ✅ Automatic webhook handling with signature verification
- ✅ Transaction management with refund support
- ✅ Query scopes for subscriptions and transactions
- ✅ Fluent builder patterns for payments and subscriptions

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email security@aizuddinmanap.com instead of using the issue tracker.

## Credits

- [Aizuddin Manap](https://github.com/aizuddinmanap)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Official Links

- [Chip Website](https://www.chip-in.asia/)
- [Chip API Documentation](https://docs.chip-in.asia/)
- [Chip Developer Dashboard](https://portal.chip-in.asia/)
- [Laravel Cashier Documentation](https://laravel.com/docs/billing) 