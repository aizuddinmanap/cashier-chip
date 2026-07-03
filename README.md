# Laravel Cashier Chip

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)
[![Total Downloads](https://img.shields.io/packagist/dt/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)
[![License](https://img.shields.io/packagist/l/aizuddinmanap/cashier-chip.svg?style=flat-square)](https://packagist.org/packages/aizuddinmanap/cashier-chip)

A Laravel Cashier–style interface to [CHIP](https://www.chip-in.asia/) for payments, subscriptions, invoices, and FPX. If you know Cashier for Stripe/Paddle, you already know this package — `$user->charge()`, `$user->newSubscription()`, `$user->invoices()`, and friends.

## Features

- **Cashier-style API** — the same patterns you know from Stripe/Paddle Cashier
- **One-time payments** — charges, authorize/capture/void, hosted checkout
- **Subscriptions, two ways** — token-based (you schedule renewals) or **native CHIP Billing Templates** (CHIP runs the whole recurring engine)
- **Recurring tokenization** — save a card once, charge renewals with no user interaction
- **Invoices + optional PDF** — transactions double as Cashier invoices
- **Refunds, customers, FPX** — full and partial refunds, customer sync, Malaysian bank transfers
- **Hardened webhooks** — RSA signature verification, authoritative re-query, idempotent delivery

## Requirements

| PHP | Laravel |
|---|---|
| 8.1 – 8.4 | 10, 11, 12, 13 |

Composer resolves the right dependency combination for your PHP version.

## Installation

```bash
composer require aizuddinmanap/cashier-chip

php artisan vendor:publish --tag="cashier-migrations"
php artisan migrate

# optional
php artisan vendor:publish --tag="cashier-config"   # config/cashier.php
composer require dompdf/dompdf                        # PDF invoices (2.x or 3.x)
```

Add your credentials to `.env`:

```env
CHIP_API_KEY=your_chip_api_key
CHIP_BRAND_ID=your_chip_brand_id
CHIP_WEBHOOK_PUBLIC_KEY=   # optional; PEM key to pin (recommended in production)
```

Add the `Billable` trait to your model:

```php
use Aizuddinmanap\CashierChip\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

The migrations add `chip_id`, `trial_ends_at`, `pm_type`, `pm_last_four` to `users`, plus tables for `customers`, `subscriptions`, `subscription_items`, `transactions`, `payment_methods`, and `plans` (the plans table is optional — delete its migration if unused).

## One-Time Payments

```php
// Direct charge (amount in cents)
$transaction = $user->charge(2990, ['description' => 'Premium Service']);

// Fluent builder → hosted checkout URL
$payment = $user->newCharge(2990)->withDescription('Order #123')->create();
return redirect($payment->url());

// Standalone checkout session
$checkout = Checkout::forAmount(2990, 'MYR')
    ->client('customer@example.com', 'John Doe')
    ->successUrl(route('success'))
    ->cancelUrl(route('cancel'))
    ->create();
return redirect($checkout['checkout_url']);
```

### Authorize → Capture / Void

```php
$checkout = $user->newCharge(5000)->skipCapture()->checkout();  // authorize only

$transaction->capture();      // capture full amount (or ->capture(2000) for partial)
$transaction->void();         // release the authorization without charging
```

## Subscriptions (token-based)

CashierChip stores a recurring token and **you** schedule renewals. Full control over when and how much you charge.

```php
// Start a tokenizing checkout (sets force_recurring, limits to card methods)
$checkout = $user->newSubscription('default', 'price_monthly')
    ->trialDays(14)
    ->checkout(['success_url' => route('sub.success'), 'cancel_url' => route('sub.cancel')]);
return redirect($checkout->url());
```

### Status checks

```php
$user->subscribed('default');                       // active OR trialing
$user->subscribedToPrice('price_monthly', 'default');
$user->onTrial('default');

$sub = $user->subscription('default');
$sub->active();  $sub->onTrial();  $sub->onGracePeriod();  $sub->pastDue();
```

`chip_status` values: `active`, `trialing`, `canceled`, `expired`, `past_due`. Both `active` and `trialing` count as **valid** (access control, upcoming invoice, etc.).

### Manage

```php
$sub->cancel();          // at period end
$sub->cancelNow();       // immediately
$sub->resume();          // while on grace period
$sub->swap('new_price'); // change plan
$sub->renew();           // charge next renewal via saved token
```

### Schedule renewals

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    \App\Models\User::has('subscriptions')->chunk(100, fn ($users) =>
        $users->each(fn ($u) => optional($u->subscription('default'))->recurring() && $u->subscription('default')->renew())
    );
})->daily();
```

## Native Subscriptions (CHIP Billing Templates)

Prefer to let **CHIP run the recurring engine**? Use a Billing Template with `is_subscription: true`. CHIP then handles everything **server-side**: billing-cycle math, auto-charging the tokenized card each cycle, trials, dunning (a failed charge emails the customer a payable invoice), and receipts. Your app just creates the template, adds subscribers, and lets webhooks mirror state.

> **Which to use?** Billing Templates to offload scheduling/dunning/receipts to CHIP. Token-based (above) when you need full control over each charge.

### Create a template

```php
use Aizuddinmanap\CashierChip\Models\BillingTemplate;

$template = BillingTemplate::create([
    'title' => 'Monthly Subscription',
    'is_subscription' => true,
    'subscription_period' => 1,
    'subscription_period_units' => 'month',   // day | week | month | year
    'subscription_trial_periods' => 0,
    'purchase' => [
        'currency' => 'MYR',
        'products' => [['name' => 'Pro plan', 'price' => 5000, 'quantity' => 1]],
    ],
]);

$template->id;   // "bt_..." — your reusable plan
```

### Subscribe a user

```php
// Creates the CHIP client if needed and mirrors a local subscription row,
// so $user->subscriptions / ->subscribedToPlan / ->cancel keep working.
$subscription = $user->subscribeToTemplate($template);       // or ('bt_123')

$user->subscribeToTemplate($template, [
    'send_invoice_on_charge_failure' => true,
    'send_receipt' => true,
    'payment_method_whitelist' => ['visa', 'mastercard'],
]);
```

CHIP now charges automatically each cycle. A successful `purchase.paid` keeps the subscription `active` (and records a renewal transaction); a `purchase.subscription_charge_failure` sets it `past_due` and fires `SubscriptionChargeFailed`.

### Manage & handle failures

```php
BillingTemplate::find('bt_123');   BillingTemplate::all();
$template->refresh();   $template->delete();

// Imperative client, mirrors the CHIP SDK snippet:
$chip = \Aizuddinmanap\CashierChip\Cashier::client();
$chip->billing->createTemplate($template);
$chip->billing->addSubscriber($template->id, $user->chipId());

// EventServiceProvider
protected $listen = [
    \Aizuddinmanap\CashierChip\Events\SubscriptionChargeFailed::class => [NotifyCustomer::class],
];
```

> The older `createSubscription()`/`getSubscription()` methods posted to `/subscriptions/` (not a documented CHIP endpoint) and are **deprecated** — use Billing Templates.

## Payment Methods & Recurring Tokens

CHIP returns a `recurring_token` on a `force_recurring` checkout; the `purchase.paid` / `purchase.preauthorized` webhook saves it as a `PaymentMethod`.

```php
// Add a card without charging (RM0 preauthorization — SetupIntent-style)
$intent = $user->addPaymentMethodIntent(['success_redirect' => route('pm.confirm')]);
return redirect($intent['checkout_url']);

$user->paymentMethods()->get();     // list ($pm->card_brand, ->card_last_four, ->isExpired()…)
$user->defaultPaymentMethod();
$user->updateDefaultPaymentMethod($id);
$user->removePaymentMethod($id);    // deletes locally AND on CHIP

// Charge the saved token directly
$user->chargeWithToken(2999, 'Premium — Jan 2026', ['reference' => $invoiceId]);
```

A charge that fails with `invalid_recurring_token` auto-deletes the token locally — the user re-adds a card.

## Invoices

Transactions double as Cashier invoices.

```php
$user->invoices();                 // paid invoices
$user->invoices(true);             // include pending
$user->findInvoice('txn_123');
$user->latestInvoice();
$user->upcomingInvoice();
$user->invoiceFor('Premium Service', 2990);
$user->invoicesForPeriod($start, $end);
$user->invoiceTotalForPeriod($start, $end);

$invoice->total();   // "RM 29.90"
$invoice->status();  // paid | open | void | draft
$invoice->paid();    $invoice->date();   $invoice->lines();

// PDF (requires dompdf)
return $user->downloadInvoice('txn_123', ['company_name' => config('app.name')]);
return $invoice->viewPDF();
```

## Transactions

```php
$user->transactions()->successful()->get();   // ->failed() ->refunded() ->charges()
$t = $user->findTransaction($id);
$t->successful();  $t->pending();  $t->refunded();
$t->amount();      // "RM 100.00"
$t->rawAmount();   // 10000
$t->paymentMethod();  $t->metadata();
```

## Refunds

```php
$user->refund('transaction_id');          // full
$user->refund('transaction_id', 1000);    // partial (RM 10.00)

$t = $user->findTransaction('transaction_id');
$t->canBeRefunded();  $t->refundableAmount();  $t->totalRefunded();  $t->refunds();
```

## Customers

```php
$user->createAsChipCustomer(['name' => 'John Doe', 'email' => 'john@example.com']);
$user->updateChipCustomer(['name' => 'John Smith']);
$user->asChipCustomer();
$user->hasChipId();   $user->chipId();
```

## FPX (Malaysian Bank Transfer)

```php
$fpx = FPX::forAmount(2990)->bank('maybank2u')
    ->client('customer@example.com', 'John Doe')
    ->successUrl(route('success'))->cancelUrl(route('cancel'))
    ->create();
return redirect($fpx['checkout_url']);

FPX::banks();               // [code => name]
FPX::status('purchase_id'); // ['status' => 'success', …]
```

## Webhooks

The package auto-registers `POST /chip/webhook`. Point your CHIP dashboard there and register events:

```bash
php artisan cashier:webhook create   # registers the recommended set
```

Handled events (both account webhooks with `event_type` and per-purchase `success_callback` with `status`):

| Event | Effect |
|---|---|
| `purchase.paid` | Transaction `success`; stores recurring token; keeps Billing Template subs active |
| `purchase.preauthorized` | Stores recurring token (RM0 card verification) |
| `purchase.payment_failure` | Transaction `failed`; deletes invalid token |
| `purchase.hold` / `pending_charge` | Marks `on_hold` / `pending_charge` |
| `payment.refunded` | Transaction `refunded` |
| `purchase.subscription_charge_failure` | Billing Template sub → `past_due`, fires `SubscriptionChargeFailed` |

Signatures are RSA-verified (CHIP's public key is fetched and cached for 24h), deliveries are re-queried against CHIP for the authoritative status, and duplicates are idempotent.

```php
Event::listen(\Aizuddinmanap\CashierChip\Events\TransactionCompleted::class, function ($event) {
    Mail::to($event->transaction->billable->email)->send(new PaymentConfirmationMail($event->transaction));
});
```

## Reconcile (missed-webhook backstop)

Webhooks are the real-time path; `cashier:reconcile` recovers payments whose webhook was never delivered by re-querying non-terminal transactions from CHIP and applying the authoritative status.

```php
Schedule::command('cashier:reconcile')->everyFifteenMinutes();
```

Tunable via `CHIP_RECONCILE_OLDER_THAN` (default 5 min) and `CHIP_RECONCILE_MAX_AGE` (default 48h). Unpaid checkouts also carry a `due` expiry (`CHIP_CHECKOUT_EXPIRY_MINUTES`, default 60), so dead orders expire on CHIP rather than lingering.

## Configuration model swaps

Swap any model via config or `Cashier::use*Model()`:

```php
Cashier::useCustomerModel(App\Models\Customer::class);       // or CASHIER_CUSTOMER_MODEL env
Cashier::useSubscriptionModel(App\Models\Subscription::class);
```

## Testing

```bash
composer test
```

150+ tests cover the Cashier-compatible API, transactions, invoices/PDF, subscriptions (token-based and Billing Templates), webhooks, and CHIP API integration.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
