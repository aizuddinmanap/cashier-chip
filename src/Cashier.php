<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Money\Currency;

class Cashier
{
    /**
     * The Cashier library version.
     */
    public static string $version = '1.0.0';

    /**
     * Indicates if Cashier routes will be registered.
     */
    public static bool $registersRoutes = true;

    /**
     * Indicates if Cashier migrations will be run.
     */
    public static bool $runsMigrations = true;

    /**
     * The default currency.
     */
    public static string $currency = 'myr';

    /**
     * The currency locale.
     */
    public static string $currencyLocale = 'en';

    /**
     * The custom currency formatter.
     */
    protected static $formatCurrencyUsing;

    /**
     * The customer model class name.
     */
    public static string $customerModel = 'Aizuddinmanap\\CashierChip\\Customer';

    /**
     * The subscription model class name.
     */
    public static string $subscriptionModel = 'Aizuddinmanap\\CashierChip\\Subscription';

    /**
     * The subscription item model class name.
     */
    public static string $subscriptionItemModel = 'Aizuddinmanap\\CashierChip\\SubscriptionItem';

    /**
     * The transaction model class name.
     */
    public static string $transactionModel = 'Aizuddinmanap\\CashierChip\\Transaction';

    /**
     * Get the default currency used by Cashier.
     */
    public static function usesCurrency(): string
    {
        return static::$currency;
    }

    /**
     * Set the currency to be used when billing customers.
     */
    public static function useCurrency(string $currency, ?string $locale = null): void
    {
        static::$currency = strtolower($currency);

        if ($locale) {
            static::$currencyLocale = $locale;
        }
    }

    /**
     * Get the currency locale used by Cashier.
     */
    public static function usesCurrencyLocale(): string
    {
        return static::$currencyLocale;
    }

    /**
     * Get the currency instance for the current currency used by Cashier.
     */
    public static function currency(): Currency
    {
        return new Currency(strtoupper(static::usesCurrency()));
    }

    /**
     * Set the custom currency formatter.
     */
    public static function formatCurrencyUsing(callable $callback): void
    {
        static::$formatCurrencyUsing = $callback;
    }

    /**
     * Format the given amount into a displayable currency.
     */
    public static function formatAmount(int $amount, ?string $currency = null): string
    {
        if (static::$formatCurrencyUsing) {
            return call_user_func(static::$formatCurrencyUsing, $amount, $currency ?: static::usesCurrency());
        }

        $money = new \Money\Money($amount, new Currency(strtoupper($currency ?: static::usesCurrency())));
        $numberFormatter = new \NumberFormatter(static::usesCurrencyLocale(), \NumberFormatter::CURRENCY);

        return $numberFormatter->formatCurrency($money->getAmount() / 100, $money->getCurrency()->getCode());
    }

    /**
     * Configure Cashier to not register its routes.
     */
    public static function ignoreRoutes(): static
    {
        static::$registersRoutes = false;

        return new static;
    }

    /**
     * Configure Cashier to not run its migrations.
     */
    public static function ignoreMigrations(): static
    {
        static::$runsMigrations = false;

        return new static;
    }

    /**
     * Set the customer model class name.
     */
    public static function useCustomerModel(string $customerModel): void
    {
        static::$customerModel = $customerModel;
    }

    /**
     * Get the customer model class name.
     */
    public static function customerModel(): string
    {
        return static::$customerModel;
    }

    /**
     * Set the subscription model class name.
     */
    public static function useSubscriptionModel(string $subscriptionModel): void
    {
        static::$subscriptionModel = $subscriptionModel;
    }

    /**
     * Get the subscription model class name.
     */
    public static function subscriptionModel(): string
    {
        return static::$subscriptionModel;
    }

    /**
     * Set the subscription item model class name.
     */
    public static function useSubscriptionItemModel(string $subscriptionItemModel): void
    {
        static::$subscriptionItemModel = $subscriptionItemModel;
    }

    /**
     * Get the subscription item model class name.
     */
    public static function subscriptionItemModel(): string
    {
        return static::$subscriptionItemModel;
    }

    /**
     * Set the transaction model class name.
     */
    public static function useTransactionModel(string $transactionModel): void
    {
        static::$transactionModel = $transactionModel;
    }

    /**
     * Get the transaction model class name.
     */
    public static function transactionModel(): string
    {
        return static::$transactionModel;
    }

    /**
     * Get the Chip API key.
     */
    public static function chipApiKey(): string
    {
        return config('cashier.chip_api_key', '');
    }

    /**
     * Get the Chip brand ID.
     */
    public static function chipBrandId(): string
    {
        return config('cashier.chip_brand_id', '');
    }

    /**
     * Get the Chip webhook secret.
     */
    public static function chipWebhookSecret(): ?string
    {
        return config('cashier.chip_webhook_secret');
    }

    /**
     * Get the Chip API URL.
     */
    public static function chipApiUrl(): string
    {
        return config('cashier.chip.api_url', 'https://gate.chip-in.asia/api/v1');
    }

    /**
     * Get the billable entity instance by Chip ID.
     */
    public static function findBillable(string $chipId)
    {
        return config('cashier.model', config('auth.providers.users.model', 'App\\Models\\User'))::where('chip_id', $chipId)->first();
    }

    /**
     * Get the default Chip payment methods.
     */
    public static function chipPaymentMethods(): array
    {
        return config('cashier.payment_methods', ['fpx', 'card']);
    }

    /**
     * Determine if the application is running in test mode.
     */
    public static function isTestMode(): bool
    {
        return config('cashier.test_mode', false) || str_contains(static::chipApiKey(), 'test_');
    }

    /**
     * Get the webhook tolerance in seconds.
     */
    public static function webhookTolerance(): int
    {
        return config('cashier.webhook_tolerance', 300);
    }

    /**
     * Get the default billable model.
     */
    public static function billableModel(): string
    {
        return config('cashier.model', config('auth.providers.users.model', 'App\\Models\\User'));
    }

    /**
     * Configure the billable model.
     */
    public static function useBillableModel(string $model): void
    {
        config(['cashier.model' => $model]);
    }
} 