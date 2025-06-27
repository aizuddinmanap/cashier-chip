# Laravel Cashier Chip

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)
[![Total Downloads](https://img.shields.io/packagist/dt/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)
[![License](https://img.shields.io/packagist/l/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)

Laravel Cashier Chip provides an expressive, fluent interface to [Chip's](https://www.chip-in.asia/) payment services. It handles almost all of the boilerplate subscription billing code you are dreading writing. In addition to basic subscription management, Cashier can handle coupons, swapping subscription, subscription "quantities", cancellation grace periods, and even generate invoice PDFs.

## Features

- 💳 **Complete Payment Support** - Cards, FPX, e-wallets, DuitNow QR
- 🔄 **Subscription Management** - Recurring billing with trials and grace periods
- 🏦 **FPX Integration** - Real-time Malaysian banking with 18+ banks
- 💰 **Refunds & Tokens** - Full refund support and token management
- 🔒 **Webhook Security** - HMAC signature verification
- 📊 **Real-time Status** - Live FPX bank availability
- 🚀 **Laravel Native** - Follows Laravel Cashier patterns

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

Publish and run the migrations to add the necessary columns to your users table and create the subscription tables:

```bash
php artisan vendor:publish --tag="cashier-chip-migrations"
php artisan migrate
```

### Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag="cashier-chip-config"
```

Add your Chip credentials to your `.env` file:

```env
CHIP_BRAND_ID=your_brand_id
CHIP_API_KEY=your_api_key
CHIP_ENDPOINT=https://gate.chip-in.asia/api/v1
CHIP_WEBHOOK_SECRET=your_webhook_secret
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

// Charge with metadata
$payment = $user->newCharge(10000)
    ->currency('MYR')
    ->metadata(['order_id' => '12345'])
    ->create();

echo $payment->url(); // Redirect user to checkout
```

### FPX Payments (Malaysian Banking)

```php
use Aizuddinmanap\CashierChip\FPX;

// Create FPX payment
$checkout = FPX::createPayment(10000) // RM 100.00
    ->fpxBank('maybank2u')
    ->successUrl(route('payment.success'))
    ->create();

// Check real-time bank status
if (FPX::isB2cAvailable()) {
    // FPX banks are online
}

// Get all banks with status
$banks = FPX::getBanksWithStatus();
foreach ($banks as $code => $bank) {
    echo "{$bank['name']}: " . ($bank['b2c_available'] ? 'Online' : 'Offline');
}
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

// Subscription with trial
if ($user->onTrial()) {
    // User is on trial
}

// Cancel subscription
$user->subscription('default')->cancel();

// Resume subscription
$user->subscription('default')->resume();
```

## Advanced Usage

### Payment Methods

```php
// Get available payment methods
$methods = $user->getAvailablePaymentMethods();

// Check FPX support
if ($user->supportsFPX()) {
    $banks = $user->getFPXBanks();
}

// Get payment methods with real-time status
$methodsWithStatus = $user->getPaymentMethodsWithStatus();
```

### Refunds

```php
// Full refund
$refund = $user->refund('payment_id');

// Partial refund
$refund = $user->refund('payment_id', 2500); // Refund RM 25.00
```

### Token Management

```php
// Charge with saved token
$payment = $user->chargeWithToken('purchase_id', [
    'amount' => 10000,
    'currency' => 'MYR'
]);

// Delete recurring token
$user->deleteRecurringToken('purchase_id');
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

// Find existing customer by email
$existing = $user->findChipCustomerByEmail('john@example.com');
```

## Webhooks

### Setup

Add the webhook route to your `routes/web.php`:

```php
Route::post(
    '/chip/webhook',
    [Aizuddinmanap\CashierChip\Http\Controllers\WebhookController::class, 'handleWebhook']
);
```

Configure your webhook URL in the Chip dashboard:
```
https://your-domain.com/chip/webhook
```

### Webhook Events

Listen for webhook events in your `EventServiceProvider`:

```php
use Aizuddinmanap\CashierChip\Events\WebhookReceived;
use Aizuddinmanap\CashierChip\Events\TransactionCompleted;

protected $listen = [
    WebhookReceived::class => [
        // Handle any webhook
    ],
    TransactionCompleted::class => [
        // Handle completed transactions
    ],
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
/*
[
    'b2c' => [
        'available' => true,
        'status' => ['status' => 'online'],
        'description' => 'Business to Consumer (Personal Banking)'
    ],
    'b2b1' => [
        'available' => true,
        'status' => ['status' => 'online'], 
        'description' => 'Business to Business Level 1 (Corporate Banking)'
    ],
    'checked_at' => '2024-01-01T12:00:00Z'
]
*/

// Check specific availability
FPX::isB2cAvailable();  // Personal banking
FPX::isB2b1Available(); // Corporate banking
```

### Payment Limits & Fees

- **Minimum**: RM 1.00
- **Maximum**: RM 30,000.00
- **B2C Fee**: RM 1.00 per transaction
- **B2B1 Fee**: RM 2.00 per transaction
- **Settlement**: Next business day

## Testing

### Running Tests

```bash
composer test
```

### Test Configuration

Set up test environment variables:

```env
CHIP_BRAND_ID=test_brand_id
CHIP_API_KEY=test_api_key
CHIP_ENDPOINT=https://api.test.chip-in.asia/api/v1
CHIP_WEBHOOK_SECRET=test_webhook_secret
```

### Mocking API Responses

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api.test.chip-in.asia/*' => Http::response([
        'id' => 'purchase_123',
        'checkout_url' => 'https://checkout.chip-in.asia/123'
    ]),
]);
```

## API Reference

### Billable Methods

| Method | Description |
|--------|-------------|
| `charge($amount, $options)` | Create one-time payment |
| `newCharge($amount, $options)` | Create payment builder |
| `refund($paymentId, $amount)` | Refund payment |
| `chargeWithToken($purchaseId, $options)` | Charge with saved token |
| `newSubscription($type, $price)` | Create subscription builder |
| `subscription($type)` | Get subscription |
| `subscribed($type)` | Check if subscribed |
| `onTrial()` | Check if on trial |
| `createAsChipCustomer($data)` | Create Chip customer |
| `updateChipCustomer($data)` | Update customer |
| `getAvailablePaymentMethods()` | Get payment methods |
| `supportsFPX()` | Check FPX support |
| `getFPXBanks()` | Get FPX banks |
| `isPaymentMethodAvailable($type)` | Check method availability |

### FPX Methods

| Method | Description |
|--------|-------------|
| `FPX::createPayment($amount, $currency)` | Create FPX payment |
| `FPX::getSupportedBanks()` | Get all banks |
| `FPX::getPopularBanks()` | Get popular banks |
| `FPX::isB2cAvailable()` | Check B2C status |
| `FPX::isB2b1Available()` | Check B2B1 status |
| `FPX::getSystemStatus()` | Get full status |
| `FPX::getBanksWithStatus()` | Get banks with status |
| `FPX::validateAmount($amount)` | Validate amount |

## Configuration Options

```php
// config/cashier-chip.php
return [
    'brand_id' => env('CHIP_BRAND_ID'),
    'api_key' => env('CHIP_API_KEY'),
    'endpoint' => env('CHIP_ENDPOINT', 'https://gate.chip-in.asia/api/v1'),
    'webhook_secret' => env('CHIP_WEBHOOK_SECRET'),
    'currency' => 'MYR',
    'logger' => null, // Set logging channel
];
```

## Migration from Other Providers

### From Laravel Cashier Stripe

```php
// Stripe
$user->charge(5000, ['currency' => 'myr']);

// Chip equivalent  
$user->charge(5000, ['currency' => 'MYR']);
```

### From Laravel Cashier Paddle

```php
// Paddle
$user->newSubscription('default', 'plan_123')->create();

// Chip equivalent
$user->newSubscription('default', 'price_123')->create();
```

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