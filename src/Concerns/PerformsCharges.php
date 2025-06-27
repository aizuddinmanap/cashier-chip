<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Concerns;

use Aizuddinmanap\CashierChip\Payment;
use Aizuddinmanap\CashierChip\PaymentBuilder;

trait PerformsCharges
{
    /**
     * Make a one-time charge on the customer for the given amount.
     */
    public function charge(int $amount, array $options = []): Payment
    {
        return $this->newCharge($amount, $options)->create();
    }

    /**
     * Begin creating a new charge for the given amount.
     */
    public function newCharge(int $amount, array $options = []): PaymentBuilder
    {
        return new PaymentBuilder($this, $amount, $options);
    }

    /**
     * Refund a customer for a charge.
     */
    public function refund(string $paymentId, ?int $amount = null): Payment
    {
        $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
        
        // Find the original payment
        $payment = $this->findPayment($paymentId);
        
        if (! $payment) {
            throw new \Exception("Payment {$paymentId} not found.");
        }

        try {
            // Use the official Chip API refund endpoint
            $refundData = [];
            if ($amount !== null) {
                $refundData['amount'] = $amount;
            }
            
            $response = $api->refundPurchase($payment->chip_id, $refundData);
            
            // Create refund payment record based on API response
            return $this->payments()->create([
                'id' => 'pay_' . uniqid(),
                'chip_id' => $response['id'] ?? 'refund_' . uniqid(),
                'amount' => $amount ?? $payment->rawAmount(),
                'currency' => $payment->currency(),
                'status' => 'refunded',
                'refunded_from' => $payment->id,
            ]);
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to process refund: {$e->getMessage()}");
        }
    }

    /**
     * Charge a customer using a saved token.
     */
    public function chargeWithToken(string $purchaseId, array $options = []): Payment
    {
        $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
        
        try {
            $response = $api->chargePurchase($purchaseId, $options);
            
            // Create payment record based on API response
            return $this->payments()->create([
                'id' => 'pay_' . uniqid(),
                'chip_id' => $response['id'] ?? $purchaseId,
                'amount' => $response['amount'] ?? ($options['amount'] ?? 0),
                'currency' => $response['currency'] ?? ($options['currency'] ?? 'MYR'),
                'status' => $response['status'] ?? 'processing',
                'charged_with_token' => true,
            ]);
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to charge with token: {$e->getMessage()}");
        }
    }

    /**
     * Find a payment by its ID.
     */
    public function findPayment(string $paymentId): ?Payment
    {
        return $this->payments()->where('id', $paymentId)->first();
    }

    /**
     * Get all payments for the billable entity.
     */
    public function payments()
    {
        return $this->morphMany(Payment::class, 'billable')->orderByDesc('created_at');
    }
} 