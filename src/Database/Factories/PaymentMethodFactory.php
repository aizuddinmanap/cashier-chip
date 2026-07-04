<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Database\Factories;

use Aizuddinmanap\CashierChip\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return [
            'chip_token_id' => 'tok_' . uniqid(),
            'billable_type' => null,
            'billable_id' => null,
            'card_brand' => 'visa',
            'card_last_four' => '4242',
            'card_expiry_month' => '12',
            'card_expiry_year' => (string) now()->addYears(2)->year,
            'cardholder_name' => $this->faker->name(),
            'card_issuer_country' => 'MY',
            'masked_pan' => '424242xxxxxx4242',
            'card_type' => 'credit',
            'is_default' => false,
            'metadata' => null,
        ];
    }

    /**
     * Attach the payment method to a billable model (morph).
     */
    public function forBillable(Model $billable): self
    {
        return $this->state([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
        ]);
    }

    /**
     * Mark as the billable's default payment method.
     */
    public function default(): self
    {
        return $this->state(['is_default' => true]);
    }
}
