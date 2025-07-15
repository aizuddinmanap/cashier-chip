# Laravel Cashier Chip - Alignment with Official Cashier Patterns

## Executive Summary

After analyzing the official Laravel Cashier packages (Stripe and Paddle), our Cashier Chip implementation has significant architectural differences that deviate from Laravel's established patterns. This document outlines the necessary changes to align with official Cashier standards.

## 🚨 Critical Architectural Issues

### 1. **Missing Core Cashier Infrastructure**

**Official Cashier Pattern:**
- Central `Cashier` facade/class for global configuration
- Standardized model relationships and methods
- Consistent service provider architecture
- Unified webhook handling system

**Current Cashier Chip Issues:**
- No central `Cashier` class
- Inconsistent model relationships
- Missing standardized configuration patterns
- Ad-hoc webhook handling

### 2. **Inconsistent Model Architecture**

**Official Cashier Models:**
```php
// Stripe/Paddle Pattern
class Subscription extends Model
{
    public function user(): BelongsTo
    public function items(): HasMany  
    public function scopeActive($query)
    public function cancel(): self
    public function swap($price): self
}

class Customer extends Model
{
    public function subscriptions(): HasMany
    public function asStripeCustomer(): StripeCustomer
}
```

**Current Cashier Chip Models:**
```php
// Inconsistent relationships and methods
class Payment extends Model {} // Missing in official Cashier
class Subscription extends Model {} // Different structure
// Missing Customer model entirely
```

### 3. **Non-Standard Billable Trait Implementation**

**Official Cashier Billable Trait:**
```php
trait Billable
{
    // Subscription Management
    public function newSubscription($name, $price): SubscriptionBuilder
    public function subscription($name = 'default'): Subscription
    public function subscribed($name = 'default'): bool
    
    // Customer Management  
    public function createAsStripeCustomer(): Customer
    public function asStripeCustomer(): StripeCustomer
    
    // Payment Methods
    public function charge($amount, $options = []): Payment
    public function refund($paymentId): Payment
    
    // Checkout
    public function checkout($prices, $options = []): Checkout
}
```

**Current Cashier Chip Billable Issues:**
- Missing subscription lifecycle methods
- Inconsistent payment method naming
- No standardized customer management
- Custom checkout patterns that don't match official

## 📋 Required Alignment Changes

### Phase 1: Core Infrastructure Alignment

#### 1.1 Create Central Cashier Class
```php
<?php

namespace Aizuddinmanap\CashierChip;

use Money\Currency;

class Cashier
{
    // Configuration
    public static function usesCurrency(): string
    public static function useCurrency(string $currency): void
    public static function formatAmount(int $amount, ?string $currency = null): string
    
    // Model Management
    public static function useCustomerModel(string $model): void
    public static function useSubscriptionModel(string $model): void
    public static function useTransactionModel(string $model): void
    
    // API Configuration
    public static function chipApiKey(): string
    public static function chipBrandId(): string
    public static function chipApiUrl(): string
    
    // Webhook Management
    public static function ignoreRoutes(): static
    public static function ignoreMigrations(): static
}
```

#### 1.2 Standardize Model Relationships
```php
// Customer Model (NEW)
class Customer extends Model
{
    public function billable(): MorphTo
    public function subscriptions(): HasMany
    public function transactions(): HasMany
}

// Subscription Model (UPDATED)
class Subscription extends Model
{
    public function customer(): BelongsTo
    public function items(): HasMany
    public function user(): BelongsTo  // Billable relationship
    
    // Status Methods
    public function active(): bool
    public function cancelled(): bool
    public function onGracePeriod(): bool
    public function onTrial(): bool
    
    // Lifecycle Methods
    public function cancel(): self
    public function cancelNow(): self
    public function resume(): self
    public function swap($price): self
}

// Transaction Model (RENAMED from Payment)
class Transaction extends Model
{
    public function customer(): BelongsTo
    public function subscription(): BelongsTo
    public function refund($amount = null): self
}
```

#### 1.3 Update Billable Trait
```php
trait Billable
{
    // Customer Management
    public function createAsChipCustomer($options = []): Customer
    public function asChipCustomer(): ChipCustomer
    public function hasChipId(): bool
    public function chipId(): ?string
    
    // Subscription Management
    public function newSubscription($name, $price): SubscriptionBuilder
    public function subscription($name = 'default'): ?Subscription
    public function subscriptions(): HasMany
    public function subscribed($name = 'default'): bool
    public function subscribedToPrice($price, $subscription = 'default'): bool
    public function subscribedToProduct($product, $subscription = 'default'): bool
    
    // Payment Management
    public function charge($amount, $options = []): Transaction
    public function refund($transactionId, $amount = null): Transaction
    
    // Checkout
    public function checkout($prices, $options = []): Checkout
    
    // Trials
    public function onTrial($subscription = 'default'): bool
    public function onGenericTrial(): bool
    public function trialEndsAt($subscription = 'default'): ?Carbon
}
```

### Phase 2: Database Schema Alignment

#### 2.1 Required Migrations
```php
// customers table (NEW)
Schema::create('customers', function (Blueprint $table) {
    $table->id();
    $table->morphs('billable');
    $table->string('chip_id')->unique();
    $table->string('name')->nullable();
    $table->string('email')->nullable();
    $table->timestamp('trial_ends_at')->nullable();
    $table->timestamps();
});

// subscriptions table (UPDATED)
Schema::create('subscriptions', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('customer_id');
    $table->string('name');
    $table->string('chip_id')->unique();
    $table->string('chip_status');
    $table->string('chip_price_id')->nullable();
    $table->integer('quantity')->nullable();
    $table->timestamp('trial_ends_at')->nullable();
    $table->timestamp('paused_at')->nullable();
    $table->timestamp('ends_at')->nullable();
    $table->timestamps();
    
    $table->foreign('customer_id')->references('id')->on('customers');
});

// transactions table (RENAMED from payments)
Schema::create('transactions', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('customer_id');
    $table->unsignedBigInteger('subscription_id')->nullable();
    $table->string('chip_id')->unique();
    $table->string('chip_status');
    $table->string('currency');
    $table->integer('amount');
    $table->json('metadata')->nullable();
    $table->timestamps();
    
    $table->foreign('customer_id')->references('id')->on('customers');
    $table->foreign('subscription_id')->references('id')->on('subscriptions');
});
```

### Phase 3: API Consistency Alignment

#### 3.1 Standardize Checkout Process
```php
// Official Cashier Pattern
class Checkout
{
    public static function guest($prices): self
    public function returnTo($url): self
    public function customData($data): self
    public function create(): array
}

// Current Implementation Needs:
class Checkout
{
    public static function forPayment($amount, $currency): self
    public static function forSubscription($price): self
    public function customer($customerId): self
    public function successUrl($url): self
    public function cancelUrl($url): self
    public function withMetadata($metadata): self
    public function create(): array
}
```

#### 3.2 Standardize Subscription Builder
```php
// Official Cashier Pattern
class SubscriptionBuilder
{
    public function __construct($billable, $name, $price)
    public function quantity($quantity): self
    public function trialDays($days): self
    public function withMetadata($metadata): self
    public function create($paymentMethod = null): Subscription
    public function add(): Subscription
}
```

### Phase 4: Service Provider Alignment

#### 4.1 Update Service Provider
```php
class ChipServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cashier.php', 'cashier');
        
        $this->app->bind(ChipApi::class, function ($app) {
            return new ChipApi(
                Cashier::chipApiKey(),
                Cashier::chipBrandId(),
                Cashier::chipApiUrl()
            );
        });
    }
    
    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerResources();
        $this->registerPublishing();
        $this->registerCommands();
    }
    
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ChipWebhookCommand::class,
            ]);
        }
    }
}
```

### Phase 5: Configuration Alignment

#### 5.1 Standardize Configuration
```php
// config/cashier.php (RENAMED from cashier-chip.php)
return [
    'api_key' => env('CHIP_API_KEY'),
    'brand_id' => env('CHIP_BRAND_ID'),
    'api_url' => env('CHIP_API_URL', 'https://gate.chip-in.asia/api/v1'),
    'webhook_secret' => env('CHIP_WEBHOOK_SECRET'),
    
    'currency' => env('CASHIER_CURRENCY', 'myr'),
    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en'),
    
    'model' => env('CASHIER_MODEL', App\Models\User::class),
    'path' => env('CASHIER_PATH', 'chip'),
    
    'webhook_tolerance' => env('CHIP_WEBHOOK_TOLERANCE', 300),
    'logger' => env('CASHIER_LOGGER'),
];
```

## 🎯 Implementation Priority

### High Priority (Breaking Changes)
1. **Create Cashier facade class** - Core infrastructure
2. **Rename Payment to Transaction** - Model consistency
3. **Create Customer model** - Missing core model
4. **Update Billable trait** - API consistency
5. **Standardize configuration** - Laravel conventions

### Medium Priority (Enhancements)
1. **Add subscription scopes** - Query builder consistency
2. **Implement checkout sessions** - Modern checkout patterns
3. **Add webhook commands** - CLI tooling
4. **Standardize error handling** - Exception consistency

### Low Priority (Polish)
1. **Add Blade components** - UI helpers
2. **Implement invoice generation** - PDF support
3. **Add testing helpers** - Test utilities
4. **Create upgrade guide** - Migration assistance

## 🔄 Migration Strategy

### Step 1: Create New Architecture (Non-Breaking)
- Add new Cashier class alongside existing
- Create new models with different names
- Implement new traits as alternatives

### Step 2: Update Documentation
- Document new patterns
- Provide migration examples
- Create upgrade guide

### Step 3: Deprecation Period
- Mark old methods as deprecated
- Provide compatibility layer
- Issue warnings for old usage

### Step 4: Breaking Changes
- Remove deprecated methods
- Update all internal usage
- Release new major version

## 📊 Compatibility Matrix

| Feature | Current Chip | Stripe Cashier | Paddle Cashier | Alignment Status |
|---------|-------------|----------------|----------------|------------------|
| Billable Trait | ✅ | ✅ | ✅ | ⚠️ Needs updates |
| Customer Model | ❌ | ✅ | ✅ | ❌ Missing |
| Subscription Model | ⚠️ | ✅ | ✅ | ⚠️ Needs updates |
| Transaction Model | ⚠️ | ✅ | ✅ | ⚠️ Wrong name |
| Checkout Sessions | ⚠️ | ✅ | ✅ | ⚠️ Different pattern |
| Webhook Handling | ⚠️ | ✅ | ✅ | ⚠️ Basic implementation |
| Central Cashier Class | ❌ | ✅ | ✅ | ❌ Missing |
| Artisan Commands | ❌ | ✅ | ✅ | ❌ Missing |
| Blade Components | ❌ | ✅ | ✅ | ❌ Missing |

## 🏆 Expected Benefits

### For Developers
- **Familiar API** - Consistent with other Cashier packages
- **Better Documentation** - Follows Laravel conventions
- **Easier Migration** - Standard patterns from Stripe/Paddle
- **Enhanced Features** - Full feature parity

### For Laravel Ecosystem
- **Consistency** - Matches official package patterns
- **Maintainability** - Easier to contribute and maintain
- **Reliability** - Proven architectural patterns
- **Integration** - Better ecosystem compatibility

## 📝 Conclusion

The current Cashier Chip implementation, while functional, significantly deviates from Laravel's established Cashier patterns. Aligning with official standards will require substantial refactoring but will result in a more maintainable, familiar, and feature-complete package that developers can easily adopt and contribute to.

The alignment process should be done incrementally with proper deprecation periods to minimize disruption to existing users while moving toward a more robust and standardized implementation.

---

*Analysis conducted on: July 14, 2025*  
*Based on: Laravel Cashier Stripe 15.x and Cashier Paddle 2.x*  
*Recommendation: Proceed with alignment in phases* 