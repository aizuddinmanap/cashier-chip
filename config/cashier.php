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