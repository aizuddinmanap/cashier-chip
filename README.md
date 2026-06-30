# Laravel Cashier Chip

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)
[![Total Downloads](https://img.shields.io/packagist/dt/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)
[![License](https://img.shields.io/packagist/l/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)

Laravel Cashier Chip provides an expressive, fluent interface to [Chip's](https://www.chip-in.asia/) payment and subscription billing services. It bridges CashierChip's transaction-based architecture with Laravel Cashier's familiar invoice patterns.

## 🎉 **Stable Release: v1.3.0**

**New in v1.3.0 — Configurable customer model:**

- ✅ **`cashier.customer_model` config** — swap the customer model (e.g. `App\Models\Customer`) via config or the `CASHIER_CUSTOMER_MODEL` env var, without code. `Cashier::useCustomerModel()` still works for runtime overrides. Your model should extend `Aizuddinmanap\CashierChip\Customer`.

**New in v1.2.0 — Closer Cashier parity:**

- ✅ **`swap()` / `swapAndInvoice()`** — change a subscription's price/plan (and optionally charge the new price immediately).
- ✅ **`cancelAt($date)`** — schedule cancellation for a specific date.
- ✅ **`pastDue()`, `hasPrice()`, `hasProduct()`** — Cashier-style subscription guards.
- ✅ **`subscribedToPrice()` / `subscribedToProduct()`** — Cashier-named billable checks (Chip has no product layer, so product matches the plan/price id).

**Manual Capture, Void & Reconciliation:**

- ✅ **Authorize → Capture / Void flow** — complete the `skip_capture` (authorize) lifecycle. Capture a held/preauthorized payment with `$transaction->capture()` (supports partial amounts) or release it with `$transaction->void()`. Billable wrappers `captureCharge($id, $amount)` / `voidCharge($id)` are also available. Mirrors the official WooCommerce plugin's manual capture/void.

```php
// Authorize at checkout
$checkout = $user->newCharge(5000)->skipCapture()->checkout();

// Later — capture the full amount (or pass cents for a partial capture)
$transaction->capture();        // or ->capture(2000)

// …or release the authorization without charging
$transaction->void();
```

- 🔄 **`cashier:reconcile` command** — recovers payments whose webhook was never delivered. Re-queries non-terminal transactions (`pending` / `preauthorized` / `on_hold` / `pending_charge`) from Chip, applies the authoritative status, and fires `TransactionCompleted` on recovery — the analog of the WooCommerce plugin's scheduled requery. Schedule it to run regularly:

```php
// routes/console.php (or the scheduler)
Schedule::command('cashier:reconcile')->everyFifteenMinutes();
```

  Webhooks remain the real-time path — this command is only a backstop for the rare missed webhook. Two independent knobs control it: `cashier.reconcile.older_than` minutes (`CHIP_RECONCILE_OLDER_THAN`, default 5) is how long a transaction must sit before it's assumed missed (so in-flight payments are left alone), and the **schedule interval** is how often you sweep. A stuck-but-paid order recovers in roughly `older_than + one interval` — so every 15 min ≈ ~20 min worst case. When nothing is stuck the run is a single empty query (no API calls), so a short interval is cheap; tune to taste.

  **Dead checkouts terminate themselves.** Every checkout is created with a `due` expiry (see below), so an unpaid purchase becomes `expired` on Chip, which the sweep resolves to `failed` through the normal path — no special "abandoned" state. As a backstop, the sweep also ignores anything older than `cashier.reconcile.max_age` minutes (`CHIP_RECONCILE_MAX_AGE`, default 2880 = 48h) so dead rows are never polled indefinitely.

- ⏳ **Purchase expiry (`due`)** — checkouts now send a `due` timestamp so unpaid purchases expire on Chip instead of lingering forever, matching the official WooCommerce plugin. Default 60 minutes via `cashier.checkout.expiry_minutes` (`CHIP_CHECKOUT_EXPIRY_MINUTES`); override per-checkout with `->expiresIn($minutes)`, or set `0` to disable.

**Webhook fixes & stability (earlier in the 1.1.x line):**

- 🐛 **Fixed logging crash** — `ChipApi::sanitizeLogData()` threw a `TypeError` (`strtolower()` on an integer list key, under `strict_types`) whenever a request body contained a list such as `products`. This fired only with `CHIP_LOGGING_ENABLED=true`; you can now safely enable logging again.
- 🧹 **Modern route registration** — the auto-registered `/chip/webhook` route now uses array-callable syntax (`[WebhookController::class, 'handleWebhook']`) instead of the legacy `Controller@method` string, removing reliance on route-group namespace resolution. Fully robust on Laravel 12.

**Webhook fixes & hardening (also in this release):**

- 🐛 **Fixed `success_callback` handling** — Chip's per-purchase callback POSTs the raw Purchase object (with a `status`, no `event_type`). Earlier versions rejected this with a `400`, so paid orders were never marked successful. The webhook now derives the event from `status` when `event_type` is absent.
- 🐛 **Corrected webhook event names** — now uses Chip's real identifiers (`purchase.paid`, `purchase.payment_failure`, `payment.refunded`) instead of the previous non-existent names (`purchase.completed`, `purchase.failed`, `purchase.refunded`). Old names are still accepted as legacy aliases.
- 🐛 **Terminal-state protection** — a stale or duplicate `failed`/`hold`/`preauthorized`/`pending_charge` callback can no longer downgrade an already-successful transaction.
- 🔒 **Authoritative re-query** — purchase webhooks now re-fetch the purchase from Chip and trust the API's status over the (replayable) callback body, mirroring the official WooCommerce plugin's `get_payment()`. Configurable via `cashier.webhook.requery` (`CHIP_WEBHOOK_REQUERY`, default on).
- 🔒 **Per-purchase idempotency lock** — concurrent deliveries (server callback + retry/redirect) are serialized with an atomic lock, the portable equivalent of the official plugin's `GET_LOCK`/`pg_advisory_lock`. Wait time configurable via `cashier.webhook.lock_wait` (`CHIP_WEBHOOK_LOCK_WAIT`). Use a shared cache store (redis/memcached/database/file) for cross-process protection.
- 🐛 **Public-key newline normalization** — RSA keys pasted into `.env`/config with literal `\n` escapes are now normalized before `openssl_verify`, instead of silently failing and 403-ing every webhook (matches the official plugin).
- ✅ **Test-mode visibility** — `is_test` payments are recorded in the transaction's `metadata` (the equivalent of the plugin's test-mode order note).
- ✅ **Idempotent delivery** — duplicate callbacks no longer re-dispatch `TransactionCompleted`.

> **Action required after upgrading:** re-register your account webhook (`php artisan cashier:webhook create`) so Chip sends the corrected event names. The per-purchase `success_callback` flow works immediately with no re-registration.

> **137 tests passing.** A familiar Laravel Cashier-style API covering the common surface — customers, charges, subscriptions, invoices, and payment methods. (Stripe-only features like the billing portal, SCA, and promotion codes aren't applicable to Chip.) Recurring tokenization, subscriptions, refunds, and capture/void are documented in detail below.

## ✨ Laravel Cashier Invoices

**CashierChip mirrors the Laravel Cashier API:**

- ✅ **Cashier-style API** - The same patterns you know from Stripe/Paddle Cashier
- ✅ **Transaction-to-Invoice Bridge** - Your transactions work as invoices automatically
- ✅ **PDF Invoice Generation** - Professional PDFs with company branding (optional)
- ✅ **Query Scopes & Filtering** - Powerful invoice management capabilities
- ✅ **Status Management** - Proper invoice statuses (paid, open, void, draft)
- ✅ **Zero Breaking Changes** - Existing transaction code still works

## 🚀 Features

- **Laravel Cashier-style API**: familiar methods from Stripe/Paddle Cashier
- **Transaction-Based Billing**: Fast, local storage of all payment data
- **Invoice Generation**: Convert transactions to invoices with optional PDF export
- **Subscription Management**: Create, modify, cancel, and resume subscriptions
- **Recurring Tokenization**: Save cards, charge renewals without user interaction
- **One-time Payments**: Process single charges with full transaction tracking
- **Refund Processing**: Full and partial refunds with automatic transaction linking
- **Customer Management**: Automatic customer creation and synchronization
- **Webhook Handling**: RSA signature verification + comprehensive event handling
- **FPX Support**: Malaysian bank transfers with real-time status checking
- **Optional PDF Generation**: Customizable invoice templates with company branding (requires dompdf)

## ✅ Requirements

| Requirement | Supported |
|---|---|
| **PHP** | 8.1 – 8.4 |
| **Laravel** | 10, 11, 12, 13 |

Each Laravel release pulls its matching dependencies automatically (e.g. Laravel 13 requires PHP 8.3+ and Symfony 7.4/8). Composer resolves the right combination for your PHP version, so older PHP simply installs an older supported Laravel.

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

> **Note:** This includes an optional plans table migration (`2024_01_01_000005_create_plans_table.php`). If you don't want local plan management, simply delete this file before running `migrate`.

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
```

### Webhook Signature Verification (RSA)

Chip signs webhooks with RSA. CashierChip fetches Chip's public key from `/public_key/` and caches it for 24 hours, so verification works out of the box. If you want to pin the key explicitly (recommended for production):

```php
// config/cashier.php
'webhook' => [
    'public_key' => env('CHIP_WEBHOOK_PUBLIC_KEY'), // PEM-formatted public key
    'tolerance' => env('CHIP_WEBHOOK_TOLERANCE', 300),
],
```

### Recurring Payment Defaults

```php
// config/cashier.php — defaults work for most apps
'recurring' => [
    'payment_methods' => ['visa', 'mastercard', 'maestro'],
    'creator_agent' => env('CHIP_CREATOR_AGENT', 'Laravel-Cashier-Chip'),
    'platform' => env('CHIP_PLATFORM', 'api'),
],
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

The published migrations add these columns to the users table automatically (matching Laravel Cashier convention):

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('chip_id')->nullable()->index();
    $table->timestamp('trial_ends_at')->nullable();
    $table->string('pm_type')->nullable();        // cached card brand (e.g. 'visa')
    $table->string('pm_last_four', 4)->nullable(); // cached last 4 digits
});
```

The package also creates dedicated tables for `customers`, `subscriptions`, `subscription_items`, `transactions`, `payment_methods`, and `plans`.

## 🧾 Working with Invoices (Laravel Cashier Compatible)

### Basic Invoice Operations

CashierChip automatically converts your transactions to invoices with Laravel Cashier-style invoice methods:

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

### Subscription Status Types

CashierChip follows Laravel Cashier standards for subscription status handling:

```php
// Subscription statuses (chip_status field)
'active'    // Paid subscription with valid payment
'trialing'  // Trial subscription (no payment required yet)
'canceled'  // Subscription cancelled
'expired'   // Subscription ended
'past_due'  // Payment failed, awaiting retry
```

**Important:** Both `'active'` and `'trialing'` subscriptions are considered **valid** subscriptions for:
- User access control (`$user->subscribed()` returns `true`)
- Feature availability 
- Billing operations (`upcomingInvoice()` works for both)
- Business logic checks

```php
// All these work correctly for BOTH active and trial subscriptions:
$user->subscribed('default');                    // ✅ true for both
$user->subscription('default')->valid();         // ✅ true for both  
$user->upcomingInvoice();                        // ✅ works for both
$subscription->active();                         // ✅ true for both

// Specific trial checks:
$user->onTrial('default');                       // ✅ true only for trials
$subscription->onTrial();                        // ✅ true only for trials
$subscription->chip_status === 'trialing';       // ✅ trial status check
```

This matches [Laravel Cashier Paddle](https://github.com/laravel/cashier-paddle/) behavior, where `'trialing'` is treated as a valid subscription state alongside `'active'`.

> **Note:** Trial status recognition in `upcomingInvoice()` and subscription queries was fixed in v1.0.17+ to properly support both `'active'` and `'trialing'` statuses.

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

## 💳 Recurring Payments & Payment Methods

CashierChip supports Chip's recurring token mechanism — save a customer's card once, then charge them for renewals without any further interaction. This is how subscription billing actually works in practice.

### How Recurring Works on Chip

Unlike Stripe (which manages cards server-side), Chip returns a `recurring_token` (the purchase ID) in the payment response. Your app stores this token locally and reuses it for future charges.

**The flow:**

1. Customer completes a checkout with `force_recurring: true` — Chip tokenizes the card
2. Your webhook receives `purchase.paid` with `is_recurring_token: true`
3. CashierChip saves the token as a `PaymentMethod` record
4. Future renewal charges use the saved token — no user interaction needed

### Subscription Checkout (with Tokenization)

```php
// Starts a checkout that tokenizes the card for future renewals.
// Automatically sets force_recurring=true and limits to card methods (visa/mastercard/maestro).
$checkout = $user->newSubscription('default', 'price_monthly')
    ->trialDays(14)
    ->checkout([
        'success_url' => route('subscription.success'),
        'cancel_url' => route('subscription.cancel'),
    ]);

return redirect($checkout->url());
```

### Adding a Payment Method (SetupIntent-style)

Equivalent to Stripe Cashier's `createSetupIntent()` flow. Creates an RM0 preauthorization so the customer can verify a card without charging it.

```php
$intent = $user->addPaymentMethodIntent([
    'success_redirect' => route('payment-methods.confirm'),
    'failure_redirect' => route('payment-methods.index'),
]);

return redirect($intent['checkout_url']);
```

When the customer completes card entry, the `purchase.preauthorized` webhook fires and the token is saved automatically.

### Managing Saved Payment Methods

```php
// List all saved payment methods
$methods = $user->paymentMethods()->get();

foreach ($methods as $pm) {
    echo $pm->card_brand;         // visa
    echo $pm->card_last_four;     // 1234
    echo $pm->card_expiry_month;  // 12
    echo $pm->card_expiry_year;   // 2028
    echo $pm->cardholder_name;    // JOHN DOE
    echo $pm->isDefault();        // true/false
    echo $pm->isExpired();        // true/false
}

// Get the default payment method
$default = $user->defaultPaymentMethod();

// Change default
$user->updateDefaultPaymentMethod($paymentMethodId);

// Remove a payment method (deletes locally AND from Chip API)
$user->removePaymentMethod($paymentMethodId);

// Only non-expired methods
$valid = $user->validPaymentMethods();
```

The `pm_type` and `pm_last_four` columns on the users table stay in sync with the default method automatically (matching Laravel Cashier Stripe convention).

### Charging Subscription Renewals

Renewal payments use the saved token — no customer interaction required:

```php
// Charge the next renewal for a subscription
$transaction = $user->subscription('default')->renew();

// Or charge manually with a custom amount
$transaction = $user->chargeWithToken(
    2999,                        // amount in cents
    'Premium plan - Jan 2026',   // description
    [
        'payment_method' => $specificTokenId, // optional — defaults to user's default
        'due_strict' => true,
        'reference' => $invoiceId,
    ]
);

if ($transaction->status === 'success') {
    // Renewal charged successfully
}
```

### Invalid Token Cleanup

When a charge fails with Chip's `invalid_recurring_token` error (e.g., customer's card got cancelled), CashierChip **automatically deletes the token locally**. Your user's `defaultPaymentMethod()` is cleared and they'll need to add a new card. The webhook handles this without any action from you.

### Schedule Renewals (Laravel Scheduler)

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->call(function () {
        \App\Models\User::has('subscriptions')->chunk(100, function ($users) {
            foreach ($users as $user) {
                $sub = $user->subscription('default');
                if ($sub && $sub->recurring()) {
                    try {
                        $sub->renew();
                    } catch (\Exception $e) {
                        // Token invalid, customer needs to re-add card
                        logger()->warning("Renewal failed for user {$user->id}: {$e->getMessage()}");
                    }
                }
            }
        });
    })->daily();
}
```

### Webhook Events Handled

CashierChip automatically handles these Chip webhook events:

CashierChip handles both of Chip's delivery mechanisms: account-level webhooks (which carry an `event_type`) and per-purchase `success_callback` / `failure_callback` (which POST the raw Purchase object with a `status` and no `event_type`).

| Event (`event_type` / Purchase `status`) | What happens |
|-------|--------------|
| `purchase.paid` / `paid` | Marks transaction as `success`, stores recurring token if present |
| `purchase.preauthorized` / `preauthorized` | Stores recurring token (RM0 card verification flow) |
| `purchase.payment_failure` / `error`, `blocked`, `cancelled`, `expired` | Marks transaction as `failed`, deletes token if `invalid_recurring_token` |
| `purchase.hold` / `hold` | Marks transaction as `on_hold` (delayed capture) |
| `purchase.pending_charge` / `pending_charge` | Marks transaction as `pending_charge` (renewal in progress) |
| `payment.refunded` / `refunded` | Marks transaction as `refunded` |

> **Legacy aliases:** the older `purchase.completed`, `purchase.failed`, and `purchase.refunded` names are still accepted so webhooks registered by earlier versions keep working. Chip has no native `subscription.*` events — subscriptions are derived automatically from `purchase.paid`.

Register webhooks with Chip:

```bash
php artisan cashier:webhook create \
  --url=https://yourapp.com/chip/webhook \
  --events=purchase.paid \
  --events=purchase.payment_failure \
  --events=payment.refunded \
  --events=purchase.preauthorized
```

> Running `cashier:webhook create` with no `--events` registers the full recommended set: `purchase.paid`, `purchase.payment_failure`, `payment.refunded`, `purchase.preauthorized`, `purchase.hold`, `purchase.pending_charge`.

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

CashierChip includes an optional local plans table for better performance and developer experience. This allows you to store plan details locally instead of making API calls to fetch plan information.

### Benefits of Local Plans

- **🚀 Performance**: No external API calls to display pricing pages
- **💻 Better DX**: Rich local plan queries and relationships  
- **🎨 Flexibility**: Custom features, descriptions, sorting, promotional pricing
- **🔄 Reliability**: Works offline, no external dependencies for plan display
- **📱 Modern Pattern**: Follows Paddle/Stripe Cashier conventions

### Setting Up Plans

First, make sure you've published the migrations and kept the plans migration:

```bash
php artisan vendor:publish --tag="cashier-migrations"
# Keep the 2024_01_01_000005_create_plans_table.php file
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

### Plans Table (Optional)

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

## 🔒 Security

If you discover any security related issues, please email aizuddinmanap@gmail.com instead of using the issue tracker.

## 📄 License

Laravel Cashier Chip is open-sourced software licensed under the [MIT license](LICENSE.md).

## 💡 Key Benefits Recap

1. **🎯 Cashier-style API** - Familiar methods from Stripe/Paddle Cashier
2. **⚡ High Performance** - Local transaction storage, no external API calls for listings
3. **🧾 Professional Invoices** - PDF generation with company branding (optional dompdf)
4. **🔄 Transaction Foundation** - Fast, reliable transaction-based architecture
5. **🇲🇾 Malaysia Ready** - FPX support and MYR currency optimized
6. **🛡️ Zero Breaking Changes** - Existing code continues to work
7. **📊 Powerful Queries** - Rich filtering and reporting capabilities
8. **🎨 UI Ready** - Complete Blade templates and examples included
9. **✅ Production Stable** - v1.3.0 with all 141 tests passing (433+ assertions)
10. **🔧 Battle-Tested** - Metadata, invoice conversion, and PDF generation all verified
11. **🧪 Modern PHPUnit** - Compatible with PHPUnit 11, reduced deprecations by 98.6%
12. **🗄️ Database Flexible** - Works with both old and new transaction table schemas
13. **⏰ Timestamp Perfect** - Full Laravel timestamp field compatibility for views
14. **🛡️ Regression Protected** - Comprehensive test coverage prevents timestamp bugs

## 🐛 Troubleshooting

### Missing PDF Dependencies

**Issue**: "PDF generation requires dompdf" error

**Solution**: Install the optional PDF dependency:

```bash
composer require dompdf/dompdf
```

PDF generation is optional - only install if you need invoice PDFs.

**CashierChip bridges the gap between transaction-based performance and Laravel Cashier's familiar invoice patterns - giving you the best of both worlds with production-grade stability, modern testing, and bulletproof timestamp handling!** 🚀