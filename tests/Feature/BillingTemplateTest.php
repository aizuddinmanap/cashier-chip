<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\Models\BillingTemplate;
use Aizuddinmanap\CashierChip\Subscription;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class BillingTemplateTest extends TestCase
{
    #[Test]
    public function it_creates_a_subscription_billing_template(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/billing_templates/' => Http::response([
                'id' => 'bt_1',
                'title' => 'Monthly Subscription',
                'is_subscription' => true,
            ]),
        ]);

        $template = BillingTemplate::create([
            'title' => 'Monthly Subscription',
            'is_subscription' => true,
            'subscription_period' => 1,
            'subscription_period_units' => 'month',
            'purchase' => [
                'currency' => 'MYR',
                'products' => [['name' => 'Pro plan', 'price' => 5000]],
            ],
        ]);

        $this->assertEquals('bt_1', $template->id);
        $this->assertTrue($template->is_subscription);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/billing_templates/')
                && $request['is_subscription'] === true
                && $request['purchase']['currency'] === 'MYR'
                && $request['purchase']['products'][0]['price'] === 5000;
        });
    }

    #[Test]
    public function a_user_can_subscribe_to_a_template(): void
    {
        $user = $this->createUser(['chip_id' => 'client_123']);

        $template = new BillingTemplate(['id' => 'bt_1', 'is_subscription' => true]);

        Http::fake([
            'api.test.chip-in.asia/api/v1/billing_templates/bt_1/add_subscriber/' => Http::response([
                'id' => 'btc_1',
                'client_id' => 'client_123',
                'status' => 'active',
            ]),
        ]);

        $subscription = $user->subscribeToTemplate($template);

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertEquals('btc_1', $subscription->chip_id);
        $this->assertEquals('bt_1', $subscription->chip_billing_template_id);
        $this->assertEquals('active', $subscription->chip_status);
        $this->assertTrue($user->subscriptions()->where('chip_billing_template_id', 'bt_1')->exists());

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/billing_templates/bt_1/add_subscriber/')
                && ($request['billing_template_client']['client_id'] ?? null) === 'client_123';
        });
    }

    #[Test]
    public function adding_a_subscriber_to_a_trial_template_starts_trialing(): void
    {
        $user = $this->createUser(['chip_id' => 'client_123']);

        $template = new BillingTemplate([
            'id' => 'bt_1',
            'is_subscription' => true,
            'subscription_trial_periods' => 1,
        ]);

        Http::fake([
            'api.test.chip-in.asia/api/v1/billing_templates/bt_1/add_subscriber/' => Http::response([
                'id' => 'btc_2',
                'subscription_billing_scheduled_on' => 1893456000, // future epoch
            ]),
        ]);

        $subscription = $template->addSubscriber($user);

        $this->assertEquals('trialing', $subscription->chip_status);
        $this->assertNotNull($subscription->trial_ends_at);
    }

    #[Test]
    public function the_chip_client_facade_matches_the_documented_snippet(): void
    {
        Http::fake([
            'api.test.chip-in.asia/api/v1/billing_templates/' => Http::response(['id' => 'bt_9']),
        ]);

        $template = new BillingTemplate();
        $template->title = 'Monthly Subscription';
        $template->is_subscription = true;

        $chip = Cashier::client();
        $created = $chip->billing->createTemplate($template);

        $this->assertEquals('bt_9', $created->id);
    }
}
