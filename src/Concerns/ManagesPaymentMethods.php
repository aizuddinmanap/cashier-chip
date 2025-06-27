<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Concerns;

use Aizuddinmanap\CashierChip\PaymentMethod;
use Illuminate\Support\Collection;

trait ManagesPaymentMethods
{
    /**
     * Get all payment methods for the billable entity.
     */
    public function paymentMethods(): Collection
    {
        // TODO: Implement payment methods retrieval from Chip API
        return collect();
    }

    /**
     * Add a payment method to the billable entity.
     */
    public function addPaymentMethod(string $paymentMethodId): PaymentMethod
    {
        // TODO: Implement payment method addition via Chip API
        return new PaymentMethod([
            'id' => $paymentMethodId,
            'customer_id' => $this->chipId(),
        ]);
    }

    /**
     * Remove a payment method from the billable entity.
     */
    public function removePaymentMethod(string $paymentMethodId): bool
    {
        // TODO: Implement payment method removal via Chip API
        return true;
    }

    /**
     * Get the default payment method for the billable entity.
     */
    public function defaultPaymentMethod(): ?PaymentMethod
    {
        // TODO: Implement default payment method retrieval from Chip API
        return null;
    }

    /**
     * Set the default payment method for the billable entity.
     */
    public function updateDefaultPaymentMethod(string $paymentMethodId): PaymentMethod
    {
        // TODO: Implement default payment method update via Chip API
        
        // Update local payment method information
        $this->fill([
            'pm_type' => 'card', // This would come from Chip API
            'pm_last_four' => '1234', // This would come from Chip API
        ])->save();

        return new PaymentMethod([
            'id' => $paymentMethodId,
            'customer_id' => $this->chipId(),
        ]);
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
    public function getAvailablePaymentMethods(): array
    {
        $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
        
        try {
            return $api->getPaymentMethods();
        } catch (\Exception $e) {
            // Fallback to default payment methods if API fails
            return [
                [
                    'id' => 'pm_card',
                    'type' => 'card',
                    'name' => 'Credit/Debit Card',
                    'description' => 'Pay with Visa, Mastercard',
                ],
                [
                    'id' => 'pm_fpx',
                    'type' => 'fpx',
                    'name' => 'FPX Online Banking',
                    'description' => 'Pay with Malaysian online banking',
                ],
                [
                    'id' => 'pm_ewallet',
                    'type' => 'ewallet',
                    'name' => 'E-Wallets',
                    'description' => 'Touch n Go, GrabPay, ShopeePay, etc.',
                ],
                [
                    'id' => 'pm_duitnow_qr',
                    'type' => 'duitnow_qr',
                    'name' => 'DuitNow QR',
                    'description' => 'Scan QR code to pay',
                ],
            ];
        }
    }

    /**
     * Get available FPX banks from Chip API.
     */
    public function getFPXBanks(): array
    {
        $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
        
        try {
            $paymentMethods = $api->getPaymentMethods();
            
            // Extract FPX banks from payment methods response
            foreach ($paymentMethods as $method) {
                if (($method['type'] ?? null) === 'fpx' && isset($method['banks'])) {
                    return $method['banks'];
                }
            }
            
            // Fallback to static list if not found in API response
            return \Aizuddinmanap\CashierChip\Checkout::getSupportedFPXBanks();
            
        } catch (\Exception $e) {
            // Fallback to static list if API fails
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
     * Delete a recurring payment token for a specific purchase.
     */
    public function deleteRecurringToken(string $purchaseId): bool
    {
        $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
        
        try {
            $api->deleteRecurringToken($purchaseId);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Search for existing Chip customer by email.
     */
    public static function findChipCustomerByEmail(string $email): ?array
    {
        $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
        
        try {
            $results = $api->searchClientsByEmail($email);
            
            // Return first match if found
            if (!empty($results) && is_array($results)) {
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
     * Get comprehensive payment method information with live status.
     */
    public function getPaymentMethodsWithStatus(): array
    {
        $methods = $this->getAvailablePaymentMethods();
        $fpxStatus = $this->getFPXSystemStatus();
        
        // Enhance methods with real-time status
        foreach ($methods as &$method) {
            if (($method['type'] ?? null) === 'fpx') {
                $method['b2c_available'] = $fpxStatus['b2c']['available'] ?? true;
                $method['b2b1_available'] = $fpxStatus['b2b1']['available'] ?? true;
                $method['banks'] = $this->getFPXBanksWithStatus();
                $method['status_checked_at'] = $fpxStatus['checked_at'] ?? null;
            }
        }
        
        return $methods;
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
                // These are typically always available
                return true;
                
            default:
                // Check if the payment method exists in available methods
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