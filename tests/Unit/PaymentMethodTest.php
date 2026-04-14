<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Unit;

use Aizuddinmanap\CashierChip\PaymentMethod;
use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class PaymentMethodTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser();
    }

    #[Test]
    public function it_creates_payment_method_from_chip_payment_response(): void
    {
        $payment = $this->samplePaymentResponse('purchase_abc', isRecurringToken: true);

        $pm = PaymentMethod::fromChipPayment($payment, $this->user);

        $this->assertNotNull($pm);
        $this->assertEquals('purchase_abc', $pm->chip_token_id);
        $this->assertEquals('visa', $pm->card_brand);
        $this->assertEquals('1234', $pm->card_last_four);
        $this->assertEquals('12', $pm->card_expiry_month);
        $this->assertEquals('2025', $pm->card_expiry_year);
        $this->assertEquals('JOHN DOE', $pm->cardholder_name);
        $this->assertEquals('MY', $pm->card_issuer_country);
        $this->assertEquals('debit', $pm->card_type);
    }

    #[Test]
    public function it_uses_recurring_token_field_when_payment_is_not_the_token(): void
    {
        $payment = $this->samplePaymentResponse(
            'purchase_xyz',
            isRecurringToken: false,
            recurringToken: 'token_abc'
        );

        $pm = PaymentMethod::fromChipPayment($payment, $this->user);

        // When is_recurring_token is false, the recurring_token field is the token
        $this->assertEquals('token_abc', $pm->chip_token_id);
    }

    #[Test]
    public function it_returns_existing_payment_method_when_token_already_exists(): void
    {
        $existing = PaymentMethod::create([
            'billable_type' => $this->user->getMorphClass(),
            'billable_id' => $this->user->getKey(),
            'chip_token_id' => 'purchase_dup',
            'card_brand' => 'mastercard',
        ]);

        $payment = $this->samplePaymentResponse('purchase_dup', isRecurringToken: true);

        $pm = PaymentMethod::fromChipPayment($payment, $this->user);

        $this->assertEquals($existing->id, $pm->id);
        $this->assertEquals('mastercard', $pm->card_brand);
    }

    #[Test]
    public function it_detects_expired_card(): void
    {
        $expired = PaymentMethod::create([
            'billable_type' => $this->user->getMorphClass(),
            'billable_id' => $this->user->getKey(),
            'chip_token_id' => 'token_old',
            'card_expiry_month' => '01',
            'card_expiry_year' => '2020',
        ]);

        $valid = PaymentMethod::create([
            'billable_type' => $this->user->getMorphClass(),
            'billable_id' => $this->user->getKey(),
            'chip_token_id' => 'token_new',
            'card_expiry_month' => '12',
            'card_expiry_year' => '2099',
        ]);

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($valid->isExpired());
    }

    #[Test]
    public function it_deletes_payment_method_locally_and_from_chip(): void
    {
        $pm = PaymentMethod::create([
            'billable_type' => $this->user->getMorphClass(),
            'billable_id' => $this->user->getKey(),
            'chip_token_id' => 'purchase_to_delete',
            'card_brand' => 'visa',
        ]);

        Http::fake([
            'api.test.chip-in.asia/api/v1/purchases/purchase_to_delete/delete_recurring_token/' =>
                Http::response(['success' => true]),
        ]);

        $result = $pm->deletePaymentMethod();

        $this->assertTrue($result);
        $this->assertDatabaseMissing('payment_methods', ['chip_token_id' => 'purchase_to_delete']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/delete_recurring_token/');
        });
    }

    /**
     * Build a sample Chip payment response with card metadata.
     */
    protected function samplePaymentResponse(
        string $id,
        bool $isRecurringToken = true,
        ?string $recurringToken = null,
    ): array {
        return [
            'id' => $id,
            'is_recurring_token' => $isRecurringToken,
            'recurring_token' => $recurringToken,
            'transaction_data' => [
                'extra' => [
                    'card_brand' => 'visa',
                    'masked_pan' => '••••••••••••1234',
                    'expiry_month' => '12',
                    'expiry_year' => '25',
                    'cardholder_name' => 'JOHN DOE',
                    'card_issuer_country' => 'MY',
                    'card_type' => 'debit',
                ],
            ],
        ];
    }
}
