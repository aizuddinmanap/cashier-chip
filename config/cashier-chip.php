<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chip API Key
    |--------------------------------------------------------------------------
    |
    | The Chip API key give you access to Chip's API. The "Secret" key
    | is typically used when interacting with Chip programmatically.
    |
    */

    'chip_api_key' => env('CHIP_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Chip Brand ID
    |--------------------------------------------------------------------------
    |
    | This is your Chip brand ID that identifies your brand/company in the
    | Chip system. You can find this in your Chip dashboard.
    |
    */

    'brand_id' => env('CHIP_BRAND_ID'),

    /*
    |--------------------------------------------------------------------------
    | Chip Webhook Secret
    |--------------------------------------------------------------------------
    |
    | Your Chip webhook secret is used to verify that any webhooks are
    | actually sent by Chip, and not a third-party pretending to be Chip.
    |
    */

    'chip_webhook_secret' => env('CHIP_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Chip API URL
    |--------------------------------------------------------------------------
    |
    | This is the base URL for the Chip API that this package will use
    | when communicating with Chip. You typically don't need to change this.
    |
    */

    'chip_api_url' => env('CHIP_API_URL', 'https://gate.chip-in.asia/api/v1'),

    /*
    |--------------------------------------------------------------------------
    | Cashier Path
    |--------------------------------------------------------------------------
    |
    | This is the base URI path where Cashier's views, such as the checkout
    | page, will be available from. You're free to tweak this path according
    | to your preferences and application design.
    |
    */

    'path' => env('CASHIER_PATH', 'chip'),

    /*
    |--------------------------------------------------------------------------
    | Cashier Model
    |--------------------------------------------------------------------------
    |
    | This is the model in your application that implements the Billable trait
    | provided by Cashier. It will serve as the primary model you use while
    | interacting with Cashier related methods, subscriptions, and so on.
    |
    */

    'model' => env('CASHIER_MODEL', App\Models\User::class),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | This is the default currency that will be used when generating charges
    | from your application. Chip supports MYR for Malaysian market including
    | FPX online banking, credit cards, e-wallets, and DuitNow QR payments.
    |
    */

    'currency' => env('CASHIER_CURRENCY', 'myr'),

    /*
    |--------------------------------------------------------------------------
    | Tax Rates
    |--------------------------------------------------------------------------
    |
    | This configuration option allows you to specify the tax rates that will
    | be applied to payments. This should be an array of tax rate IDs that
    | have been configured in your Chip dashboard for the given rates.
    |
    */

    'tax_rates' => [
        // 'txr_1234567890'
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Tolerance
    |--------------------------------------------------------------------------
    |
    | This value represents the maximum age of an incoming webhook request
    | in seconds. If a webhook is older than this value, it will be rejected
    | to prevent replay attacks.
    |
    */

    'webhook_tolerance' => env('CHIP_WEBHOOK_TOLERANCE', 300),

    /*
    |--------------------------------------------------------------------------
    | Invoices
    |--------------------------------------------------------------------------
    |
    | This array contains configuration options for generating and sending
    | customer invoices. You may specify a custom view for invoice emails
    | as well as the default subject line and footer text.
    |
    */

    'invoices' => [
        'paper' => env('CASHIER_PAPER', 'letter'),
        'disk' => env('CASHIER_INVOICE_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logger
    |--------------------------------------------------------------------------
    |
    | This configuration option controls the logger that will be used to log
    | incoming webhook requests. You may use any PSR-3 compatible logger.
    |
    */

    'logger' => env('CASHIER_LOGGER'),

]; 