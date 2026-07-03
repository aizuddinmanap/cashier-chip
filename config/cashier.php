<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chip API Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Chip API settings. You should set your
    | Chip API key, brand ID, and API endpoint URL. You can find these
    | credentials in your Chip merchant dashboard.
    |
    */

    'chip' => [
        'api_key' => env('CHIP_API_KEY'),
        'brand_id' => env('CHIP_BRAND_ID'),
        'api_url' => env('CHIP_API_URL', 'https://gate.chip-in.asia/api/v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chip Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Chip can notify your application of various billing events via webhook
    | endpoints. You should set the webhook secret key that Chip provides
    | to verify the authenticity of incoming webhook requests.
    |
    */

    'webhook' => [
        'secret' => env('CHIP_WEBHOOK_SECRET'),
        'tolerance' => env('CHIP_WEBHOOK_TOLERANCE', 300),

        // Re-fetch the authoritative purchase from Chip on each purchase webhook
        // instead of trusting the (replayable) callback body. Mirrors the official
        // WooCommerce plugin. Disable only if you cannot reach the Chip API.
        'requery' => env('CHIP_WEBHOOK_REQUERY', true),

        // Seconds a webhook will wait to acquire the per-purchase processing lock
        // before treating the delivery as a duplicate already being handled. The
        // lock serializes concurrent deliveries (server callback + retry/redirect),
        // equivalent to the official WooCommerce plugin's GET_LOCK. For cross-process
        // protection use a shared cache store (redis/memcached/database/file).
        'lock_wait' => env('CHIP_WEBHOOK_LOCK_WAIT', 10),
    ],

    'checkout' => [

        // Minutes before an unpaid checkout expires, sent to Chip as the purchase
        // `due` timestamp. 0 disables expiry.
        'expiry_minutes' => env('CHIP_CHECKOUT_EXPIRY_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | The `cashier:reconcile` command re-queries non-terminal transactions to
    | recover from missed webhooks. `older_than` is the minimum age (minutes)
    | before a pending transaction is reconciled, leaving in-flight payments
    | alone. Schedule the command to run regularly.
    |
    */

    'reconcile' => [
        'older_than' => env('CHIP_RECONCILE_OLDER_THAN', 5),

        // Backstop (minutes): stop polling rows older than this. Keep it greater
        // than checkout.expiry_minutes. Default 2880 (48h).
        'max_age' => env('CHIP_RECONCILE_MAX_AGE', 2880),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure logging for Chip API requests and webhooks.
    |
    */

    'logging' => [
        'enabled' => env('CHIP_LOGGING_ENABLED', false),
        'channel' => env('CHIP_LOGGING_CHANNEL', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency Configuration
    |--------------------------------------------------------------------------
    |
    | This is the default currency that will be used when generating charges
    | from your application. You are free to customize this value to your
    | application's requirements.
    |
    */

    'currency' => env('CASHIER_CURRENCY', 'myr'),

    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'ms_MY'),

    /*
    |--------------------------------------------------------------------------
    | Billable Model
    |--------------------------------------------------------------------------
    |
    | This is the model in your application that includes the Billable trait
    | provided by Cashier. It will serve as the primary model you use while
    | interacting with Cashier related methods, subscriptions, and so on.
    |
    */

    'model' => env('CASHIER_MODEL', App\Models\User::class),

    /*
    |--------------------------------------------------------------------------
    | Customer Model
    |--------------------------------------------------------------------------
    |
    | This is the model Cashier uses to store customer records associated with
    | your billable models. You may swap it for your own model — for example
    | App\Models\Customer — as long as it extends the package's Customer model
    | (or replicates its table, columns, and "billable" morph relation).
    |
    */

    'customer_model' => env('CASHIER_CUSTOMER_MODEL', Aizuddinmanap\CashierChip\Customer::class),

    /*
    |--------------------------------------------------------------------------
    | Cashier Path
    |--------------------------------------------------------------------------
    |
    | This is the base URI path where Cashier's views, such as the payment
    | verification and webhook URIs will be available from. You are free
    | to tweak this path according to your preferences and requirements.
    |
    */

    'path' => env('CASHIER_PATH', 'chip'),

    /*
    |--------------------------------------------------------------------------
    | Payment Methods
    |--------------------------------------------------------------------------
    |
    | This array contains the payment methods that are available for your
    | application. You may customize this list based on the payment methods
    | that are supported by your Chip merchant account.
    |
    */

    'payment_methods' => [
        'fpx',
        'card',
        'ewallet',
        'direct_debit',
        'atome',
        'grab_pay',
        'shopee_pay',
        'boost',
        'tng',
        'mcash',
        'maybank_qr',
        'duitnow_qr',
        'paypal',
        'apple_pay',
        'google_pay',
    ],

    /*
    |--------------------------------------------------------------------------
    | Test Mode
    |--------------------------------------------------------------------------
    |
    | This option allows you to toggle between live and test modes. When
    | test mode is enabled, no real transactions will be processed and
    | you can use test credentials for development purposes.
    |
    */

    'test_mode' => env('CHIP_TEST_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | FPX Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for FPX (Financial Process Exchange) payment method.
    | This includes settings for Malaysian bank transfers.
    |
    */

    'fpx' => [
        'enabled' => env('CHIP_FPX_ENABLED', true),
        'banks' => [
            'maybank2u',
            'cimb',
            'public_bank',
            'rhb',
            'hong_leong',
            'ambank',
            'affin',
            'alliance',
            'islam',
            'muamalat',
            'rakyat',
            'bsn',
            'ocbc',
            'standard_chartered',
            'uob',
            'kfh',
            'pb_enterprise',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for subscription billing features.
    |
    */

    'subscription' => [
        'grace_period' => env('CHIP_SUBSCRIPTION_GRACE_PERIOD', 3), // days
        'trial_period' => env('CHIP_SUBSCRIPTION_TRIAL_PERIOD', 14), // days
    ],

    /*
    |--------------------------------------------------------------------------
    | Renewal Run
    |--------------------------------------------------------------------------
    |
    | `cashier:renew` charges each due subscription inside a per-subscription
    | cache lock so overlapping runs (scheduler overlap, or a manual run fired
    | while the scheduled one is in flight) can't double-charge the same row.
    | Use a shared cache store (redis/memcached/database/file) for cross-process
    | protection; the array driver only protects within a single process.
    |
    */

    'renewal' => [
        // Seconds a subscription's renewal lock is held (the critical section).
        'lock_ttl' => env('CHIP_RENEWAL_LOCK_TTL', 300),

        // Seconds a run will wait to acquire a subscription's lock before
        // treating it as already being processed by another run.
        'lock_wait' => env('CHIP_RENEWAL_LOCK_WAIT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recurring Payment Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for recurring/tokenized payments via Chip's
    | recurring_token mechanism.
    |
    | payment_methods: Card brands that support tokenization for recurring.
    |   Only visa, mastercard, maestro can be tokenized by Chip.
    |
    | creator_agent: Identifies this package in Chip API requests.
    |
    | platform: Platform identifier sent with purchase requests.
    |
    */

    'recurring' => [
        'payment_methods' => ['visa', 'mastercard', 'maestro'],
        'creator_agent' => env('CHIP_CREATOR_AGENT', 'Laravel-Cashier-Chip'),
        'platform' => env('CHIP_PLATFORM', 'api'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for invoice generation and management.
    |
    */

    'invoice' => [
        'prefix' => env('CHIP_INVOICE_PREFIX', 'INV'),
        'paper' => env('CHIP_INVOICE_PAPER', 'a4'),
        'locale' => env('CHIP_INVOICE_LOCALE', 'en'),
    ],

]; 