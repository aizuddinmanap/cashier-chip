<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Concerns;

use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\Http\ChipApi;
use Aizuddinmanap\CashierChip\PaymentBuilder;
use Aizuddinmanap\CashierChip\PaymentMethod;
use Aizuddinmanap\CashierChip\Transaction;
use Illuminate\Support\Facades\Log;

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
        $api = new ChipApi();

        $transaction = $this->findTransaction($transactionId);

        if (! $transaction) {
            throw new \Exception("Transaction {$transactionId} not found.");
        }

        try {
            $refundData = [];
            if ($amount !== null) {
                $refundData['amount'] = $amount;
            }

            $response = $api->refundPurchase($transaction->chip_id, $refundData);

            return $this->transactions()->create([
                'id' => 'txn_' . uniqid(),
                'chip_id' => $response['id'] ?? 'refund_' . uniqid(),
                'total' => $amount ?? $transaction->rawAmount(),
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
     * Charge a customer using a saved recurring token.
     *
     * Creates a new purchase then charges it with the stored token.
     *
     * @param  int     $amount       Amount in cents
     * @param  string  $description  Product/charge description
     * @param  array   $options      Additional options
     */
    public function chargeWithToken(int $amount, string $description = 'Renewal Payment', array $options = []): Transaction
    {
        $api = new ChipApi();

        // Resolve the recurring token
        $token = $this->resolveRecurringToken($options['payment_method'] ?? null);

        if (! $token) {
            throw new \Exception('No recurring payment token available to charge.');
        }

        $currency = strtoupper($options['currency'] ?? config('cashier.currency', 'MYR'));

        // Step 1: Create a new purchase
        $params = [
            'send_receipt' => $options['send_receipt'] ?? false,
            'creator_agent' => config(
                'cashier.recurring.creator_agent',
                'Laravel-Cashier-Chip/' . Cashier::$version
            ),
            'platform' => config('cashier.recurring.platform', 'api'),
            'brand_id' => config('cashier.chip.brand_id'),
            'client' => [
                'email' => $this->email ?? $options['email'] ?? null,
                'full_name' => $this->name ?? $options['full_name'] ?? null,
            ],
            'purchase' => [
                'currency' => $currency,
                'due_strict' => $options['due_strict'] ?? false,
                'total_override' => $amount,
                'products' => [
                    [
                        'name' => substr($description, 0, 256),
                        'price' => $amount,
                        'quantity' => 1,
                    ],
                ],
            ],
        ];

        if (isset($options['success_callback'])) {
            $params['success_callback'] = $options['success_callback'];
        }

        if (isset($options['reference'])) {
            $params['reference'] = (string) $options['reference'];
        }

        try {
            // Create the purchase
            $payment = $api->createPurchase($params);

            $purchaseId = $payment['id'];

            // Step 2: Charge using recurring token
            $chargeResponse = $api->chargePurchase($purchaseId, [
                'recurring_token' => $token,
            ]);

            // Handle invalid_recurring_token error
            $this->handleChargeResponse($chargeResponse, $token);

            // Determine status from charge response
            $status = 'pending';
            if (is_array($chargeResponse)) {
                if (($chargeResponse['status'] ?? null) === 'paid') {
                    $status = 'success';
                } elseif (($chargeResponse['status'] ?? null) === 'pending_charge') {
                    $status = 'pending';
                } elseif (array_key_exists('__all__', $chargeResponse)) {
                    $status = 'failed';
                }
            }

            // Create transaction record
            return $this->transactions()->create([
                'id' => 'txn_' . uniqid(),
                'chip_id' => $purchaseId,
                'total' => $amount,
                'currency' => $currency,
                'status' => $status,
                'type' => 'charge',
                'description' => $description,
                'payment_method' => 'recurring_token',
                'metadata' => json_encode([
                    'charged_with_token' => true,
                    'recurring_token' => $token,
                ]),
            ]);

        } catch (\Exception $e) {
            throw new \Exception("Failed to charge with token: {$e->getMessage()}");
        }
    }

    /**
     * Resolve the recurring token to use for charging.
     */
    protected function resolveRecurringToken(?string $paymentMethodId = null): ?string
    {
        // If a specific payment method was provided, use it
        if ($paymentMethodId) {
            $pm = $this->paymentMethods()
                ->where('id', $paymentMethodId)
                ->orWhere('chip_token_id', $paymentMethodId)
                ->first();

            return $pm ? $pm->token() : $paymentMethodId;
        }

        // Use the default payment method
        $defaultPm = $this->defaultPaymentMethod();

        return $defaultPm ? $defaultPm->token() : null;
    }

    /**
     * Handle the charge response and clean up invalid tokens.
     */
    protected function handleChargeResponse(array $chargeResponse, string $tokenId): void
    {
        if (! array_key_exists('__all__', $chargeResponse)) {
            return;
        }

        $errors = $chargeResponse['__all__'] ?? [];

        foreach ($errors as $error) {
            if (isset($error['code']) && $error['code'] === 'invalid_recurring_token') {
                // Delete the invalid token locally
                PaymentMethod::where('chip_token_id', $tokenId)->delete();

                Log::warning('Invalid recurring token deleted', ['token_id' => $tokenId]);

                throw new \Exception('Recurring payment token is invalid and has been removed.');
            }
        }
    }

    /**
     * Find a transaction by its ID.
     */
    public function findTransaction(string $transactionId): ?Transaction
    {
        return $this->transactions()->where('id', $transactionId)->first();
    }
}
