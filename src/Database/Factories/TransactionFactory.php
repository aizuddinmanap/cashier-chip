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

    /**
     * Create a default billable owner if none was attached via forBillable(),
     * so a bare Transaction::factory()->create() is valid (morphs are NOT NULL).
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Transaction $transaction) {
            if (! $transaction->billable_type || ! $transaction->billable_id) {
                $model = config('cashier.model');
                $owner = new $model();
                $owner->fill([
                    'name' => 'Factory User',
                    'email' => 'factory_' . uniqid() . '@example.com',
                    'password' => bcrypt('password'),
                ])->save();

                $transaction->billable_type = $owner->getMorphClass();
                $transaction->billable_id = $owner->getKey();
            }
        });
    }

    public function definition(): array
    {
        return [
            'id' => 'txn_' . uniqid(),
            'chip_id' => 'purchase_' . uniqid(),
            'customer_id' => null,
            'billable_type' => null, // seeded by configure() if unset
            'billable_id' => null,   // seeded by configure() if unset
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
