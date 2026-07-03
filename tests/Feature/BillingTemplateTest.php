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
            'subscription_period_units' => 'months',
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
    public function it_accepts_the_documented_subscription_fields(): void
    {
        // Mirrors CHIP's docs: is_subscription + subscription_* fields, with
        // subscription_charge_period_end set to `true` (the docs' literal example)
        // and a trial via subscription_trial_periods.
        Http::fake([
            'api.test.chip-in.asia/api/v1/billing_templates/' => Http::response(['id' => 'bt_docs']),
        ]);

        BillingTemplate::create([
            'title' => 'End-of-cycle Subscription',
            'is_subscription' => true,
            'subscription_period' => 1,
            'subscription_period_units' => 'months',
            'subscription_charge_period_end' => true,
            'subscription_trial_periods' => 2,
            'subscription_active' => true,
            'purchase' => ['currency' => 'MYR', 'products' => [['name' => 'Pro', 'price' => 5000]]],
        ]);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/billing_templates/')
                && $request['is_subscription'] === true
                && $request['subscription_charge_period_end'] === true
                && $request['subscription_trial_periods'] === 2
                && $request['subscription_active'] === true;
        });
    }

    #[Test]
    public function it_hydrates_a_response_with_string_priced_products(): void
    {
        // Chip returns price/quantity as strings; hydrating the response into the
        // typed int properties must not throw under strict_types.
        Http::fake([
            'api.test.chip-in.asia/api/v1/billing_templates/' => Http::response([
                'id' => 'bt_hydrate',
                'is_subscription' => true,
                'purchase' => [
                    'currency' => 'MYR',
                    'products' => [['name' => 'Pro', 'price' => '5000', 'quantity' => '2']],
                ],
            ]),
        ]);

        $template = BillingTemplate::create(['title' => 'X', 'is_subscription' => true]);

        $product = $template->purchase->products[0];
        $this->assertSame(5000, $product->price);
        $this->assertSame(2, $product->quantity);
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
    public function add_subscriber_rejects_a_stale_placeholder_client_id(): void
    {
        // A "cust_" id is a local placeholder (real Chip client ids are UUIDs).
        // Enrolling it would 400; fail fast with a clear message and send nothing.
        $user = $this->createUser(['chip_id' => 'cust_stale']);
        $template = new BillingTemplate(['id' => 'bt_1', 'is_subscription' => true]);

        Http::fake();

        try {
            $user->subscribeToTemplate($template);
            $this->fail('Expected InvalidArgumentException for a placeholder client id.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('chip_id', $e->getMessage());
        }

        Http::assertNothingSent();
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
