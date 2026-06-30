<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Unit;

use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\ChipServiceProvider;
use Aizuddinmanap\CashierChip\Customer;
use Aizuddinmanap\CashierChip\Tests\Fixtures\CustomCustomer;
use Aizuddinmanap\CashierChip\Tests\TestCase;

class CustomerModelTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset to the package default so static state doesn't leak between tests.
        Cashier::useCustomerModel(Customer::class);

        parent::tearDown();
    }

    public function test_default_customer_model_is_the_package_model(): void
    {
        $this->assertSame(Customer::class, Cashier::customerModel());
    }

    public function test_use_customer_model_overrides_the_model(): void
    {
        Cashier::useCustomerModel(CustomCustomer::class);

        $this->assertSame(CustomCustomer::class, Cashier::customerModel());
    }

    public function test_service_provider_applies_the_customer_model_from_config(): void
    {
        config()->set('cashier.customer_model', CustomCustomer::class);

        $this->bootChipServiceProvider();

        $this->assertSame(CustomCustomer::class, Cashier::customerModel());
    }

    public function test_service_provider_leaves_default_when_config_is_empty(): void
    {
        config()->set('cashier.customer_model', null);

        $this->bootChipServiceProvider();

        $this->assertSame(Customer::class, Cashier::customerModel());
    }

    /**
     * Re-run the provider's model registration against the current config.
     */
    protected function bootChipServiceProvider(): void
    {
        $provider = new ChipServiceProvider($this->app);

        $register = new \ReflectionMethod($provider, 'registerModels');
        $register->setAccessible(true);
        $register->invoke($provider);
    }
}
