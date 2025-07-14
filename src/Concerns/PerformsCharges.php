<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Concerns;

use Aizuddinmanap\CashierChip\Transaction;
use Aizuddinmanap\CashierChip\PaymentBuilder;

trait PerformsCharges
{
    /**
     * Make a one-time charge on the customer for the given amount.
     */
    public function charge(int $amount, array $options = []): Transaction
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
    public function refund(string $transactionId, ?int $amount = null): Transaction
    {
        $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
        
        // Find the original transaction
        $transaction = $this->findTransaction($transactionId);
        
        if (! $transaction) {
            throw new \Exception("Transaction {$transactionId} not found.");
        }

        try {
            // Use the official Chip API refund endpoint
            $refundData = [];
            if ($amount !== null) {
                $refundData['amount'] = $amount;
            }
            
            $response = $api->refundPurchase($transaction->chip_id, $refundData);
            
            // Create refund transaction record based on API response
            return $this->transactions()->create([
                'id' => 'txn_' . uniqid(),
                'chip_id' => $response['id'] ?? 'refund_' . uniqid(),
                'amount' => $amount ?? $transaction->rawAmount(),
                'currency' => $transaction->currency(),
                'status' => 'refunded',
                'type' => 'refund',
                'refunded_from' => $transaction->id,
            ]);
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to process refund: {$e->getMessage()}");
        }
    }

    /**
     * Charge a customer using a saved token.
     */
    public function chargeWithToken(string $purchaseId, array $options = []): Transaction
    {
        $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();
        
        try {
            $response = $api->chargePurchase($purchaseId, $options);
            
            // Create transaction record based on API response
            return $this->transactions()->create([
                'id' => 'txn_' . uniqid(),
                'chip_id' => $response['id'] ?? $purchaseId,
                'amount' => $response['amount'] ?? ($options['amount'] ?? 0),
                'currency' => $response['currency'] ?? ($options['currency'] ?? 'MYR'),
                'status' => $response['status'] ?? 'processing',
                'type' => 'charge',
                'charged_with_token' => true,
            ]);
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to charge with token: {$e->getMessage()}");
        }
    }

    /**
     * Find a transaction by its ID.
     */
    public function findTransaction(string $transactionId): ?Transaction
    {
        return $this->transactions()->where('id', $transactionId)->first();
    }

    /**
     * Get all transactions for the billable entity.
     */
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'billable')->orderByDesc('created_at');
    }


} 