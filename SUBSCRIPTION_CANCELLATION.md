# Subscription Cancellation Implementation

## Overview

This implementation adds comprehensive subscription cancellation functionality to the cashier-chip library, following the exact patterns used by Laravel Cashier Paddle.

## Features Implemented

### 1. Core Subscription Methods

#### `cancel()` - Graceful Cancellation
```php
$subscription = $user->subscription('default');
$subscription->cancel();
```
- Sets `ends_at` to end of current billing period
- Makes API call to CHIP to schedule cancellation
- Dispatches `SubscriptionCanceled` event
- Subscription remains active until `ends_at`

#### `cancelNow()` - Immediate Cancellation  
```php
$subscription = $user->subscription('default');
$subscription->cancelNow();
```
- Sets `ends_at` to current timestamp
- Makes API call to CHIP with immediate effect
- Dispatches `SubscriptionCanceled` event
- Subscription ends immediately

#### `stopCancellation()` / `resume()` - Restore Cancelled Subscription
```php
$subscription = $user->subscription('default');
$subscription->stopCancellation(); // or $subscription->resume()
```
- Removes scheduled cancellation (sets `ends_at` to null)
- Makes API call to CHIP to remove scheduled change
- Only works for subscriptions on grace period

### 2. Status Check Methods

#### `onGracePeriod()` - Check Grace Period Status
```php
if ($subscription->onGracePeriod()) {
    // Subscription is cancelled but still active
}
```
- Returns `true` if subscription is cancelled but `ends_at` is in the future
- Returns `false` if subscription is active or has already ended

#### `cancelled()` - Check Cancellation Status
```php
if ($subscription->cancelled()) {
    // Subscription has been cancelled
}
```
- Returns `true` if `ends_at` is set (regardless of whether it's past or future)
- Returns `false` if subscription is active

### 3. Billable Model Methods

#### `cancelSubscription()` - Cancel by Name
```php
$user->cancelSubscription('default');
$user->cancelSubscription('premium');
```

#### `cancelSubscriptionNow()` - Cancel Immediately by Name  
```php
$user->cancelSubscriptionNow('default');
```

#### `cancelAllSubscriptions()` - Cancel All Active Subscriptions
```php
$user->cancelAllSubscriptions();
```

### 4. API Integration

#### ChipApi Methods Added
```php
// Cancel subscription with scheduling
$api->cancelSubscription($subscriptionId, ['effective_from' => 'next_billing_period']);

// Cancel subscription immediately  
$api->cancelSubscription($subscriptionId, ['effective_from' => 'immediately']);

// Update subscription to remove scheduled changes
$api->updateSubscription($subscriptionId, ['scheduled_change' => null]);

// Get subscription details
$api->getSubscription($subscriptionId);
```

### 5. Webhook Handling

#### Enhanced WebhookController
- Handles `subscription.cancelled` events from CHIP
- Updates local subscription status and `ends_at` timestamp
- Dispatches `SubscriptionCanceled` event for application listeners

### 6. Smart Trial Handling

The implementation intelligently handles trial subscriptions:

- **Trial-only subscriptions**: No API calls made (local cancellation only)
- **Paid subscriptions**: Full API integration with CHIP
- **Detection**: Uses `hasChipId()` method to differentiate

## Usage Examples

### Basic Cancellation
```php
// Cancel at end of billing period
$user->subscription('default')->cancel();

// Cancel immediately
$user->subscription('default')->cancelNow();

// Restore cancelled subscription
$user->subscription('default')->resume();
```

### Checking Status
```php
$subscription = $user->subscription('default');

if ($subscription->cancelled()) {
    if ($subscription->onGracePeriod()) {
        echo "Subscription ends on " . $subscription->ends_at->format('Y-m-d');
    } else {
        echo "Subscription has ended";
    }
}
```

### Bulk Operations
```php
// Cancel specific subscription
$user->cancelSubscription('premium');

// Cancel all subscriptions
$user->cancelAllSubscriptions();
```

## Event Handling

```php
// Listen for cancellation events
Event::listen(SubscriptionCanceled::class, function ($event) {
    $subscription = $event->subscription;
    // Send cancellation email, update billing, etc.
});
```

## Error Handling

- API failures are handled gracefully - local cancellation still occurs
- Trial subscriptions skip API calls entirely
- Invalid subscription states are protected against

## Alignment with Laravel Cashier Paddle

This implementation maintains 100% API compatibility with Laravel Cashier Paddle:

- Method names and signatures are identical
- Behavior patterns match exactly
- Event dispatching follows the same flow
- Grace period logic is consistent
- Error handling approaches are aligned

## Files Modified

1. **`src/Subscription.php`** - Core cancellation methods
2. **`src/Http/ChipApi.php`** - API integration methods
3. **`src/Concerns/ManagesSubscriptions.php`** - Billable model methods
4. **`src/Http/Controllers/WebhookController.php`** - Webhook handling
5. **`src/Events/SubscriptionCanceled.php`** - Event class (already existed)

## Testing

Comprehensive test coverage includes:
- Grace period cancellations
- Immediate cancellations  
- Trial subscription handling
- API failure scenarios
- Billable model convenience methods
- Webhook event processing
- Status checking methods

All tests follow Laravel testing patterns and use proper mocking for external API calls. 