<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Database\Factories;

use Aizuddinmanap\CashierChip\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'id' => 'txn_' . uniqid(),
            'chip_id' => 'purchase_' . uniqid(),
            'customer_id' => null,
            'billable_type' => null,
            'billable_id' => null,
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 2900,
            'payment_method' => 'recurring_token',
            'description' => $this->faker->sentence(),
            'metadata' => null,
            'refunded_from' => null,
            'processed_at' => Carbon::now(),
        ];
    }

    /**
     * Attach the transaction to a billable model (morph).
     */
    public function forBillable(Model $billable): self
    {
        return $this->state([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
        ]);
    }

    public function success(): self
    {
        return $this->state(['status' => 'success']);
    }

    public function pending(): self
    {
        return $this->state(['status' => 'pending']);
    }

    public function failed(): self
    {
        return $this->state(['status' => 'failed']);
    }

    public function refunded(): self
    {
        return $this->state(['status' => 'refunded', 'type' => 'refund']);
    }

    public function charge(): self
    {
        return $this->state(['type' => 'charge']);
    }

    public function refund(): self
    {
        return $this->state(['type' => 'refund']);
    }
}
