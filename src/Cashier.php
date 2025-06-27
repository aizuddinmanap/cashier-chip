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
     * The custom currency formatter.
     */
    protected static $formatCurrencyUsing;

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
    public static function useCurrency(string $currency): void
    {
        static::$currency = strtolower($currency);
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
        $numberFormatter = new \NumberFormatter(config('app.locale', 'en'), \NumberFormatter::CURRENCY);

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
     * Get the Chip API key.
     */
    public static function chipApiKey(): string
    {
        return config('cashier-chip.chip_api_key');
    }

    /**
     * Get the Chip webhook secret.
     */
    public static function chipWebhookSecret(): ?string
    {
        return config('cashier-chip.chip_webhook_secret');
    }

    /**
     * Get the Chip API URL.
     */
    public static function chipApiUrl(): string
    {
        return config('cashier-chip.chip_api_url', 'https://gate.chip-in.asia/api/v1');
    }
} 