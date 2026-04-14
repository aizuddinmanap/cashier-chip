<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Concerns;

use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\Http\ChipApi;
use Aizuddinmanap\CashierChip\PaymentMethod;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

trait ManagesPaymentMethods
{
    /**
     * Get all payment methods for the billable entity.
     * Aligned with Laravel Cashier Stripe: paymentMethods().
     */
    public function paymentMethods(): MorphMany
    {
        return $this->morphMany(PaymentMethod::class, 'billable')->orderByDesc('created_at');
    }

    /**
     * Get all non-expired payment methods.
     */
    public function validPaymentMethods(): Collection
    {
        return $this->paymentMethods()->get()->reject(fn (PaymentMethod $pm) => $pm->isExpired());
    }

    /**
     * Add a payment method via zero-amount tokenization (RM0 preauthorization).
     * Equivalent to Laravel Cashier Stripe's createSetupIntent() flow.
     *
     * Returns the Chip checkout URL for the customer to complete card tokenization.
     */
    public function addPaymentMethodIntent(array $options = []): array
    {
        $api = new ChipApi();

        $recurringMethods = config('cashier.recurring.payment_methods', ['visa', 'mastercard', 'maestro']);

        $params = [
            'payment_method_whitelist' => $recurringMethods,
            'creator_agent' => config('cashier.recurring.creator_agent', 'Laravel-Cashier-Chip/' . Cashier::$version),
            'platform' => config('cashier.recurring.platform', 'api'),
            'force_recurring' => true,
            'skip_capture' => true,
            'brand_id' => config('cashier.chip.brand_id'),
            'client' => [
                'email' => $this->email ?? $options['email'] ?? null,
                'full_name' => $this->name ?? $options['full_name'] ?? null,
            ],
            'purchase' => [
                'currency' => strtoupper($options['currency'] ?? config('cashier.currency', 'MYR')),
                'products' => [
                    [
                        'name' => 'Add payment method',
                        'price' => 0,
                    ],
                ],
            ],
        ];

        if (isset($options['success_redirect'])) {
            $params['success_redirect'] = $options['success_redirect'];
        }

        if (isset($options['failure_redirect'])) {
            $params['failure_redirect'] = $options['failure_redirect'];
        }

        if (isset($options['success_callback'])) {
            $params['success_callback'] = $options['success_callback'];
        }

        $payment = $api->createPurchase($params);

        return [
            'id' => $payment['id'],
            'checkout_url' => $payment['checkout_url'] ?? null,
            'direct_post_url' => $payment['direct_post_url'] ?? null,
        ];
    }

    /**
     * Store a recurring token from a Chip payment response.
     */
    public function storePaymentMethodFromChip(array $payment): ?PaymentMethod
    {
        if (! ($payment['is_recurring_token'] ?? false) && empty($payment['recurring_token'])) {
            return null;
        }

        $paymentMethod = PaymentMethod::fromChipPayment($payment, $this);

        if ($paymentMethod) {
            $this->updateDefaultPaymentMethodFromModel($paymentMethod);
        }

        return $paymentMethod;
    }

    /**
     * Remove a payment method by its ID.
     * Aligned with Laravel Cashier Stripe: deletePaymentMethod().
     */
    public function removePaymentMethod($paymentMethodId): bool
    {
        $paymentMethod = $this->paymentMethods()
            ->where('id', $paymentMethodId)
            ->orWhere('chip_token_id', $paymentMethodId)
            ->first();

        if (! $paymentMethod) {
            return false;
        }

        $wasDefault = $paymentMethod->isDefault();

        $paymentMethod->deletePaymentMethod();

        if ($wasDefault) {
            $this->clearDefaultPaymentMethod();
        }

        return true;
    }

    /**
     * Get the default payment method for the billable entity.
     * Aligned with Laravel Cashier Stripe: defaultPaymentMethod().
     */
    public function defaultPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethods()->where('is_default', true)->first();
    }

    /**
     * Update the default payment method.
     * Aligned with Laravel Cashier Stripe: updateDefaultPaymentMethod().
     */
    public function updateDefaultPaymentMethod($paymentMethodId): ?PaymentMethod
    {
        $paymentMethod = $this->paymentMethods()
            ->where('id', $paymentMethodId)
            ->orWhere('chip_token_id', $paymentMethodId)
            ->first();

        if (! $paymentMethod) {
            return null;
        }

        return $this->updateDefaultPaymentMethodFromModel($paymentMethod);
    }

    /**
     * Set a PaymentMethod model as the default and update user columns.
     */
    protected function updateDefaultPaymentMethodFromModel(PaymentMethod $paymentMethod): PaymentMethod
    {
        $this->paymentMethods()->update(['is_default' => false]);

        $paymentMethod->update(['is_default' => true]);

        $this->forceFill([
            'pm_type' => $paymentMethod->card_brand,
            'pm_last_four' => $paymentMethod->card_last_four,
        ])->save();

        return $paymentMethod;
    }

    /**
     * Clear the default payment method columns.
     */
    protected function clearDefaultPaymentMethod(): void
    {
        $next = $this->paymentMethods()->latest()->first();

        if ($next) {
            $this->updateDefaultPaymentMethodFromModel($next);
        } else {
            $this->forceFill([
                'pm_type' => null,
                'pm_last_four' => null,
            ])->save();
        }
    }

    /**
     * Determine if the billable entity has a default payment method.
     */
    public function hasDefaultPaymentMethod(): bool
    {
        return ! is_null($this->pm_type);
    }

    /**
     * Get the last four digits of the default payment method.
     */
    public function pmLastFour(): ?string
    {
        return $this->pm_last_four;
    }

    /**
     * Get the type of the default payment method.
     */
    public function pmType(): ?string
    {
        return $this->pm_type;
    }

    /**
     * Get the available payment methods for the customer from Chip API.
     */
    public function getAvailablePaymentMethods(string $currency = 'MYR'): array
    {
        try {
            $api = new ChipApi();
            return $api->getPaymentMethods($currency);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get recurring-capable payment methods from Chip API.
     */
    public function getRecurringPaymentMethods(string $currency = 'MYR'): array
    {
        try {
            $api = new ChipApi();
            return $api->getRecurringPaymentMethods($currency);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get available FPX banks from Chip API.
     */
    public function getFPXBanks(): array
    {
        try {
            $api = new ChipApi();
            $paymentMethods = $api->getPaymentMethods();

            foreach ($paymentMethods as $method) {
                if (($method['type'] ?? null) === 'fpx' && isset($method['banks'])) {
                    return $method['banks'];
                }
            }

            return \Aizuddinmanap\CashierChip\Checkout::getSupportedFPXBanks();
        } catch (\Exception $e) {
            return \Aizuddinmanap\CashierChip\Checkout::getSupportedFPXBanks();
        }
    }

    /**
     * Check if FPX is available for this customer.
     */
    public function supportsFPX(): bool
    {
        $paymentMethods = $this->getAvailablePaymentMethods();

        foreach ($paymentMethods as $method) {
            if (($method['type'] ?? null) === 'fpx') {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete a recurring payment token from Chip API.
     */
    public function deleteRecurringToken(string $purchaseId): bool
    {
        try {
            $api = new ChipApi();
            $api->deleteRecurringToken($purchaseId);
            return true;
        } catch (\Exception $e) {
            Log::warning('Failed to delete recurring token: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Search for existing Chip customer by email.
     */
    public static function findChipCustomerByEmail(string $email): ?array
    {
        try {
            $api = new ChipApi();
            $results = $api->searchClientsByEmail($email);

            if (! empty($results) && is_array($results)) {
                return $results[0] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get FPX banks with real-time availability status.
     */
    public function getFPXBanksWithStatus(): array
    {
        return \Aizuddinmanap\CashierChip\FPX::getBanksWithStatus();
    }

    /**
     * Check current FPX system status.
     */
    public function getFPXSystemStatus(): array
    {
        return \Aizuddinmanap\CashierChip\FPX::getSystemStatus();
    }

    /**
     * Check if a specific payment method type is currently available.
     */
    public function isPaymentMethodAvailable(string $type): bool
    {
        switch ($type) {
            case 'fpx':
                return $this->supportsFPX() &&
                       (\Aizuddinmanap\CashierChip\FPX::isB2cAvailable() ||
                        \Aizuddinmanap\CashierChip\FPX::isB2b1Available());

            case 'card':
            case 'ewallet':
            case 'duitnow_qr':
                return true;

            default:
                $methods = $this->getAvailablePaymentMethods();
                foreach ($methods as $method) {
                    if (($method['type'] ?? null) === $type) {
                        return true;
                    }
                }
                return false;
        }
    }
}
