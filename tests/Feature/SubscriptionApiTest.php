<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Subscription;
use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Aizuddinmanap\CashierChip\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class SubscriptionApiTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser();
    }

    protected function makeSubscription(array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'user_id' => $this->user->id,
            'name' => 'default',
            'chip_id' => 'trial_' . uniqid(), // no API by default (trial_ prefix)
            'chip_status' => 'active',
            'chip_price_id' => 'price_basic',
            'quantity' => 1,
        ], $overrides));
    }

    #[Test]
    public function swap_schedules_the_change_for_the_next_renewal(): void
    {
        $subscription = $this->makeSubscription();

        $subscription->swap('price_pro');

        $fresh = $subscription->fresh();
        // Current plan stays in effect until renewal; the change is pending.
        $this->assertEquals('price_basic', $fresh->chip_price_id);
        $this->assertEquals('price_pro', $fresh->pending_plan_id);
    }

    #[Test]
    public function swap_makes_no_api_call_and_does_not_charge(): void
    {
        Http::fake();

        $subscription = $this->makeSubscription(['chip_id' => 'sub_123']);

        $subscription->swap('price_pro', ['quantity' => 3]);

        $fresh = $subscription->fresh();
        $this->assertEquals('price_pro', $fresh->pending_plan_id);
        $this->assertEquals(3, $fresh->quantity);

        Http::assertNothingSent();
        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function cancel_at_sets_ends_at_to_the_given_date(): void
    {
        $subscription = $this->makeSubscription();
        $date = now()->addDays(10);

        $subscription->cancelAt($date);

        $this->assertEquals(
            $date->toDateString(),
            $subscription->fresh()->ends_at->toDateString()
        );
        $this->assertTrue($subscription->fresh()->cancelled());
    }

    #[Test]
    public function past_due_reflects_chip_status(): void
    {
        $this->assertTrue($this->makeSubscription(['chip_status' => 'past_due'])->pastDue());
        $this->assertFalse($this->makeSubscription(['chip_status' => 'active'])->pastDue());
    }

    #[Test]
    public function has_price_and_has_product_match_the_price_id(): void
    {
        $subscription = $this->makeSubscription(['chip_price_id' => 'price_gold']);

        $this->assertTrue($subscription->hasPrice('price_gold'));
        $this->assertFalse($subscription->hasPrice('price_silver'));
        // Chip has no product layer — product matches the price id.
        $this->assertTrue($subscription->hasProduct('price_gold'));
    }

    #[Test]
    public function subscribed_to_price_checks_active_subscription(): void
    {
        $this->makeSubscription(['chip_price_id' => 'price_team']);

        $user = $this->user->fresh();

        $this->assertTrue($user->subscribedToPrice('price_team'));
        $this->assertTrue($user->subscribedToPrice(['price_team', 'price_other']));
        $this->assertFalse($user->subscribedToPrice('price_other'));
        // Product alias resolves to the same price check.
        $this->assertTrue($user->subscribedToProduct('price_team'));
    }
}
