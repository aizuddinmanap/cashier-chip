<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Aizuddinmanap\CashierChip\Http\ChipApi;

class FPX
{
    /**
     * Create an FPX payment checkout.
     */
    public static function createPayment(int $amount, string $currency = 'MYR'): Checkout
    {
        return Checkout::forFPX($amount, $currency);
    }

    /**
     * Get all supported FPX banks.
     */
    public static function getSupportedBanks(): array
    {
        return Checkout::getSupportedFPXBanks();
    }

    /**
     * Get FPX banks from Chip API.
     */
    public static function getAvailableBanks(): array
    {
        $api = new ChipApi();
        
        try {
            $paymentMethods = $api->getPaymentMethods();
            
            foreach ($paymentMethods as $method) {
                if (($method['type'] ?? null) === 'fpx' && isset($method['banks'])) {
                    return $method['banks'];
                }
            }
            
            return static::getSupportedBanks();
            
        } catch (\Exception $e) {
            return static::getSupportedBanks();
        }
    }

    /**
     * Check if a specific bank is supported.
     */
    public static function isBankSupported(string $bankCode): bool
    {
        $banks = static::getSupportedBanks();
        return array_key_exists($bankCode, $banks);
    }

    /**
     * Get bank name by bank code.
     */
    public static function getBankName(string $bankCode): ?string
    {
        $banks = static::getSupportedBanks();
        return $banks[$bankCode] ?? null;
    }

    /**
     * Create an FPX payment with a specific bank.
     */
    public static function payWithBank(int $amount, string $bankCode, string $currency = 'MYR'): Checkout
    {
        if (!static::isBankSupported($bankCode)) {
            throw new \InvalidArgumentException("Bank code '{$bankCode}' is not supported for FPX payments.");
        }

        return static::createPayment($amount, $currency)->fpxBank($bankCode);
    }

    /**
     * Get FPX payment information and limits.
     */
    public static function getPaymentInfo(): array
    {
        return [
            'name' => 'FPX (Financial Process Exchange)',
            'description' => 'Malaysian online banking payment system',
            'currency' => 'MYR',
            'min_amount' => 100, // RM 1.00 in cents
            'max_amount' => 3000000, // RM 30,000.00 in cents (as per industry standards)
            'settlement_time' => 'Next business day',
            'fees' => [
                'b2c' => 'RM 1.00', // Business to Consumer
                'b2b1' => 'RM 2.00', // Business to Business (Level 1)
            ],
            'supported_banks' => count(static::getSupportedBanks()),
            'redirect_flow' => true,
            'real_time' => true,
        ];
    }

    /**
     * Validate FPX payment amount.
     */
    public static function validateAmount(int $amount): bool
    {
        $info = static::getPaymentInfo();
        return $amount >= $info['min_amount'] && $amount <= $info['max_amount'];
    }

    /**
     * Format amount for FPX (amounts should be in cents).
     */
    public static function formatAmount(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Get popular Malaysian banks for FPX.
     */
    public static function getPopularBanks(): array
    {
        return [
            'maybank2u' => 'Maybank2U',
            'cimb' => 'CIMB Clicks',
            'public' => 'Public Bank',
            'rhb' => 'RHB Bank',
            'hongleong' => 'Hong Leong Bank',
            'ambank' => 'AmBank',
        ];
    }

    /**
     * Get real-time FPX B2C bank status from Chip API.
     */
    public static function getFpxB2cStatus(): array
    {
        $api = new ChipApi();
        
        try {
            return $api->getFpxB2cStatus();
        } catch (\Exception $e) {
            // Return empty array on failure - calling code should handle gracefully
            return [];
        }
    }

    /**
     * Get real-time FPX B2B1 bank status from Chip API.
     */
    public static function getFpxB2b1Status(): array
    {
        $api = new ChipApi();
        
        try {
            return $api->getFpxB2b1Status();
        } catch (\Exception $e) {
            // Return empty array on failure - calling code should handle gracefully
            return [];
        }
    }

    /**
     * Check if FPX B2C banks are currently available.
     */
    public static function isB2cAvailable(): bool
    {
        $status = static::getFpxB2cStatus();
        
        // Check if the API returned a positive status
        if (isset($status['status']) && $status['status'] === 'online') {
            return true;
        }
        
        // If we can't determine status, assume available for better UX
        return true;
    }

    /**
     * Check if FPX B2B1 banks are currently available.
     */
    public static function isB2b1Available(): bool
    {
        $status = static::getFpxB2b1Status();
        
        // Check if the API returned a positive status
        if (isset($status['status']) && $status['status'] === 'online') {
            return true;
        }
        
        // If we can't determine status, assume available for better UX
        return true;
    }

    /**
     * Get comprehensive FPX status information.
     */
    public static function getSystemStatus(): array
    {
        return [
            'b2c' => [
                'available' => static::isB2cAvailable(),
                'status' => static::getFpxB2cStatus(),
                'description' => 'Business to Consumer (Personal Banking)',
            ],
            'b2b1' => [
                'available' => static::isB2b1Available(), 
                'status' => static::getFpxB2b1Status(),
                'description' => 'Business to Business Level 1 (Corporate Banking)',
            ],
            'checked_at' => now()->toISOString(),
        ];
    }

    /**
     * Get banks with their current availability status.
     */
    public static function getBanksWithStatus(): array
    {
        $banks = static::getSupportedBanks();
        $b2cStatus = static::isB2cAvailable();
        $b2b1Status = static::isB2b1Available();
        
        $banksWithStatus = [];
        
        foreach ($banks as $code => $name) {
            $banksWithStatus[$code] = [
                'name' => $name,
                'b2c_available' => $b2cStatus,
                'b2b1_available' => $b2b1Status,
                'recommended' => in_array($code, array_keys(static::getPopularBanks())),
            ];
        }
        
        return $banksWithStatus;
    }
} 