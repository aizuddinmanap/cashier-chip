<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Database\Factories;

use Aizuddinmanap\CashierChip\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $id = 'price_' . uniqid();

        return [
            'id' => $id,
            'chip_price_id' => $id,
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'price' => 29.00,
            'currency' => 'MYR',
            'interval' => 'month',
            'interval_count' => 1,
            'features' => null,
            'active' => true,
            'sort_order' => 0,
            'stripe_price_id' => null,
        ];
    }

    /**
     * Monthly interval (the default, made explicit).
     */
    public function monthly(): self
    {
        return $this->state([
            'interval' => 'month',
            'interval_count' => 1,
        ]);
    }

    /**
     * Annual interval.
     */
    public function yearly(): self
    {
        return $this->state([
            'interval' => 'year',
            'interval_count' => 1,
        ]);
    }
}
