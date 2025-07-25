<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests;

use Aizuddinmanap\CashierChip\ChipServiceProvider;
use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Aizuddinmanap\\CashierChip\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ChipServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set up Chip configuration for testing
        config()->set('cashier.chip.brand_id', 'test_brand_id');
        config()->set('cashier.chip.api_key', 'test_api_key');
        config()->set('cashier.chip.api_url', 'https://api.test.chip-in.asia/api/v1');
        config()->set('cashier.webhook.secret', 'test_webhook_secret');
    }

    protected function setUpDatabase(): void
    {
        // Create users table for testing
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('chip_id')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // Create subscriptions table
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('chip_id')->unique();
            $table->string('chip_status');
            $table->string('chip_price_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'chip_status']);
        });

        // Create subscription_items table
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('subscription_id');
            $table->string('chip_id')->unique();
            $table->string('chip_product');
            $table->string('chip_price');
            $table->integer('quantity');
            $table->timestamps();

            $table->unique(['subscription_id', 'chip_price']);
        });

        // Create transactions table
        Schema::create('transactions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('chip_id')->unique()->nullable();
            $table->string('customer_id')->nullable();
            $table->morphs('billable');
            $table->string('type')->default('charge');
            $table->string('status');
            $table->string('currency', 3)->default('MYR');
            $table->integer('total');
            $table->string('payment_method')->nullable();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('refunded_from')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            
            $table->index('status');
        });
    }

    protected function createUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ], $attributes));
    }

    protected function mockChipApiResponse(array $response, int $status = 200): void
    {
        Http::fake([
            'api.test.chip-in.asia/*' => Http::response($response, $status),
        ]);
    }

    protected function mockChipApiError(int $status = 400, string $message = 'API Error'): void
    {
        Http::fake([
            'api.test.chip-in.asia/*' => Http::response(['error' => $message], $status),
        ]);
    }
} 