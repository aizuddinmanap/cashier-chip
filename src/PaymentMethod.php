<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Aizuddinmanap\CashierChip\Database\Factories\PaymentMethodFactory;
use Aizuddinmanap\CashierChip\Http\ChipApi;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentMethod extends Model implements Arrayable, Jsonable
{
    use HasFactory;

    /**
     * Resolve the factory directly so Model::factory() works in consumer apps
     * regardless of Factory::$namespace or any app-level factory resolver.
     */
    protected static function newFactory(): Factory
    {
        return PaymentMethodFactory::new();
    }

    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Get the billable entity that owns the payment method.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the Chip token ID (purchase ID used as recurring token).
     */
    public function token(): string
    {
        return $this->chip_token_id;
    }

    /**
     * Get the card brand (visa, mastercard, maestro).
     */
    public function brand(): ?string
    {
        return $this->card_brand;
    }

    /**
     * Get the last four digits of the card.
     */
    public function lastFour(): ?string
    {
        return $this->card_last_four;
    }

    /**
     * Get the card expiry month.
     */
    public function expiryMonth(): ?string
    {
        return $this->card_expiry_month;
    }

    /**
     * Get the card expiry year.
     */
    public function expiryYear(): ?string
    {
        return $this->card_expiry_year;
    }

    /**
     * Get the cardholder name.
     */
    public function cardholderName(): ?string
    {
        return $this->cardholder_name;
    }

    /**
     * Get the card type (debit, credit).
     */
    public function cardType(): ?string
    {
        return $this->card_type;
    }

    /**
     * Determine if this is the default payment method.
     */
    public function isDefault(): bool
    {
        return $this->is_default;
    }

    /**
     * Determine if the card is expired.
     */
    public function isExpired(): bool
    {
        if (! $this->card_expiry_year || ! $this->card_expiry_month) {
            return false;
        }

        $expiry = \Carbon\Carbon::createFromDate(
            (int) $this->card_expiry_year,
            (int) $this->card_expiry_month,
            1
        )->endOfMonth();

        return $expiry->isPast();
    }

    /**
     * Delete the payment method locally and from Chip API.
     */
    public function deletePaymentMethod(): bool
    {
        try {
            $api = new ChipApi();
            $api->deleteRecurringToken($this->chip_token_id);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Failed to delete recurring token from Chip API: ' . $e->getMessage()
            );
        }

        return $this->delete();
    }

    /**
     * Create a PaymentMethod from a Chip payment response.
     */
    public static function fromChipPayment(array $payment, Model $billable): ?self
    {
        $tokenId = $payment['id'];

        if (! ($payment['is_recurring_token'] ?? false)) {
            $tokenId = $payment['recurring_token'] ?? $payment['id'];
        }

        $existing = static::where('chip_token_id', $tokenId)->first();
        if ($existing) {
            return $existing;
        }

        $extra = $payment['transaction_data']['extra'] ?? [];

        return static::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'chip_token_id' => $tokenId,
            'card_brand' => $extra['card_brand'] ?? null,
            'card_last_four' => isset($extra['masked_pan']) ? substr($extra['masked_pan'], -4) : null,
            'card_expiry_month' => $extra['expiry_month'] ?? null,
            'card_expiry_year' => isset($extra['expiry_year']) ? '20' . $extra['expiry_year'] : null,
            'cardholder_name' => $extra['cardholder_name'] ?? null,
            'card_issuer_country' => $extra['card_issuer_country'] ?? null,
            'masked_pan' => $extra['masked_pan'] ?? null,
            'card_type' => $extra['card_type'] ?? null,
            'is_default' => false,
        ]);
    }

    /**
     * Convert the payment method to JSON.
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }
}
