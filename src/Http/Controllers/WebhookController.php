<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Http\Controllers;

use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\Events\WebhookReceived;
use Aizuddinmanap\CashierChip\Http\Middleware\VerifyWebhookSignature;
use Aizuddinmanap\CashierChip\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Create a new webhook controller instance.
     */
    public function __construct()
    {
        $this->middleware(VerifyWebhookSignature::class);
    }

    /**
     * Handle the incoming webhook.
     *
     * Chip delivers payment notifications through two different mechanisms:
     *
     *   1. Account-level webhooks (registered via cashier:webhook create) POST an
     *      envelope containing an explicit "event_type" such as "purchase.paid".
     *   2. Per-purchase success_callback / failure_callback POST the raw Purchase
     *      object directly. That payload has a "status" (e.g. "paid") but NO
     *      "event_type". This is the mechanism the official WooCommerce plugin uses.
     *
     * We accept both: an explicit event_type wins, otherwise we derive the event
     * from the Purchase "status".
     */
    public function handleWebhook(Request $request): Response
    {
        $payload = $request->all();

        // A webhook must carry at least one identifier: event_type or status.
        if (! isset($payload['event_type']) && ! isset($payload['status'])) {
            return response('Webhook missing event type', 400);
        }

        $event = $this->resolveEvent($payload);

        $this->logWebhook($event ?? ($payload['event_type'] ?? $payload['status']), $payload);

        Event::dispatch(new WebhookReceived($payload));

        $method = $event ? ($this->eventHandlers()[$event] ?? null) : null;

        if ($method && method_exists($this, $method)) {
            try {
                $this->{$method}($payload);
            } catch (\Exception $e) {
                Log::error('Webhook handling failed', [
                    'event' => $event,
                    'error' => $e->getMessage(),
                    'payload' => $payload,
                ]);

                return response('Webhook handling failed', 500);
            }
        }

        return response('Webhook handled', 200);
    }

    /**
     * Map a canonical event name to its handler method.
     *
     * Keys use Chip's real event identifiers. Legacy aliases that earlier
     * versions of this package registered (purchase.completed / purchase.failed /
     * purchase.refunded) are kept so previously-registered webhooks keep working.
     */
    protected function eventHandlers(): array
    {
        return [
            // Payment succeeded.
            'purchase.paid' => 'handlePurchasePaid',
            'purchase.completed' => 'handlePurchasePaid', // legacy alias

            // Payment failed.
            'purchase.payment_failure' => 'handlePurchaseFailed',
            'purchase.failed' => 'handlePurchaseFailed', // legacy alias

            // Refunds (Chip emits payment.refunded, not purchase.refunded).
            'payment.refunded' => 'handlePurchaseRefunded',
            'purchase.refunded' => 'handlePurchaseRefunded', // legacy alias

            // Authorization / capture lifecycle.
            'purchase.preauthorized' => 'handlePurchasePreauthorized',
            'purchase.hold' => 'handlePurchaseHold',
            'purchase.pending_charge' => 'handlePurchasePendingCharge',

            // Subscription lifecycle. Chip has no native subscription.* webhooks —
            // subscriptions are derived from purchase.paid (see
            // maybeCreateSubscriptionFromPurchase) — but these handlers remain
            // reachable for manual dispatch and backward compatibility.
            'subscription.created' => 'handleSubscriptionCreated',
            'subscription.updated' => 'handleSubscriptionUpdated',
            'subscription.cancelled' => 'handleSubscriptionCancelled',
            'subscription.expired' => 'handleSubscriptionExpired',
        ];
    }

    /**
     * Resolve the canonical event name from a webhook payload.
     *
     * Prefers an explicit event_type; otherwise derives it from the Purchase
     * status delivered by a success_callback / failure_callback. Returns null
     * when the payload carries a status we deliberately do not act on (e.g.
     * created / sent / viewed), which is acknowledged as a no-op.
     */
    protected function resolveEvent(array $payload): ?string
    {
        if (! empty($payload['event_type'])) {
            return $payload['event_type'];
        }

        if (! empty($payload['status'])) {
            return $this->statusToEvent($payload['status']);
        }

        return null;
    }

    /**
     * Translate a Chip Purchase "status" into a canonical event name.
     */
    protected function statusToEvent(string $status): ?string
    {
        return match ($status) {
            'paid' => 'purchase.paid',
            'preauthorized' => 'purchase.preauthorized',
            'hold' => 'purchase.hold',
            'pending_charge' => 'purchase.pending_charge',
            'refunded' => 'payment.refunded',
            'error', 'blocked', 'cancelled', 'expired', 'overdue' => 'purchase.payment_failure',
            default => null,
        };
    }

    /**
     * Handle a successful purchase (Chip event: purchase.paid, or a
     * success_callback Purchase object with status "paid").
     *
     * Stores the recurring token from the response if present, marks the
     * transaction successful, and creates a subscription record when applicable.
     * Guarded against duplicate deliveries so the TransactionCompleted event
     * fires at most once per purchase.
     */
    protected function handlePurchasePaid(array $payload): void
    {
        $purchaseId = $payload['id'] ?? null;

        if (! $purchaseId) {
            return;
        }

        // Store recurring token if present in the payment response
        $this->maybeStoreRecurringToken($payload);

        // Find the transaction and update its status
        $transaction = \Aizuddinmanap\CashierChip\Transaction::where('chip_id', $purchaseId)->first();

        // Only transition (and dispatch) once — duplicate callbacks are no-ops.
        if ($transaction && $transaction->status !== 'success') {
            $transaction->update([
                'status' => 'success',
                'payment_method' => $payload['transaction_data']['payment_method'] ?? null,
                'processed_at' => now(),
            ]);

            Event::dispatch(new \Aizuddinmanap\CashierChip\Events\TransactionCompleted($transaction));
        }

        // Also update payment if the model exists
        if (class_exists(\Aizuddinmanap\CashierChip\Payment::class)) {
            $payment = \Aizuddinmanap\CashierChip\Payment::where('chip_id', $purchaseId)->first();

            if ($payment && $payment->status !== 'success') {
                $payment->update(['status' => 'success']);
            }
        }

        // If this is a subscription checkout, create the subscription record
        $this->maybeCreateSubscriptionFromPurchase($payload);
    }

    /**
     * Handle purchase.failed webhook.
     */
    protected function handlePurchaseFailed(array $payload): void
    {
        $purchaseId = $payload['id'] ?? null;

        if (! $purchaseId) {
            return;
        }

        $transaction = \Aizuddinmanap\CashierChip\Transaction::where('chip_id', $purchaseId)->first();

        if ($transaction) {
            $transaction->update(['status' => 'failed']);
        }

        if (class_exists(\Aizuddinmanap\CashierChip\Payment::class)) {
            $payment = \Aizuddinmanap\CashierChip\Payment::where('chip_id', $purchaseId)->first();

            if ($payment) {
                $payment->update(['status' => 'failed']);
            }
        }

        // Clean up invalid recurring tokens
        $this->maybeDeleteInvalidToken($payload);
    }

    /**
     * Handle purchase.refunded webhook.
     */
    protected function handlePurchaseRefunded(array $payload): void
    {
        $purchaseId = $payload['id'] ?? null;

        if (! $purchaseId) {
            return;
        }

        $transaction = \Aizuddinmanap\CashierChip\Transaction::where('chip_id', $purchaseId)->first();

        if ($transaction) {
            $transaction->update(['status' => 'refunded']);
        }
    }

    /**
     * Handle purchase.preauthorized webhook.
     *
     * Fires when a card is verified via skip_capture (RM0 authorization)
     * or when a payment is authorized but not yet captured.
     */
    protected function handlePurchasePreauthorized(array $payload): void
    {
        $purchaseId = $payload['id'] ?? null;

        if (! $purchaseId) {
            return;
        }

        // Card verification flow stores the recurring token without charging
        $this->maybeStoreRecurringToken($payload);

        $transaction = \Aizuddinmanap\CashierChip\Transaction::where('chip_id', $purchaseId)->first();

        if ($transaction) {
            $transaction->update([
                'status' => 'preauthorized',
                'payment_method' => $payload['transaction_data']['payment_method'] ?? null,
            ]);
        }
    }

    /**
     * Handle purchase.hold webhook.
     *
     * Fires when a payment is on hold pending review or delayed capture.
     */
    protected function handlePurchaseHold(array $payload): void
    {
        $purchaseId = $payload['id'] ?? null;

        if (! $purchaseId) {
            return;
        }

        $transaction = \Aizuddinmanap\CashierChip\Transaction::where('chip_id', $purchaseId)->first();

        if ($transaction) {
            $transaction->update(['status' => 'on_hold']);
        }
    }

    /**
     * Handle purchase.pending_charge webhook.
     *
     * Fires when a token-based charge has been initiated but not yet finalized
     * (typically during subscription renewal).
     */
    protected function handlePurchasePendingCharge(array $payload): void
    {
        $purchaseId = $payload['id'] ?? null;

        if (! $purchaseId) {
            return;
        }

        $transaction = \Aizuddinmanap\CashierChip\Transaction::where('chip_id', $purchaseId)->first();

        if ($transaction) {
            $transaction->update(['status' => 'pending_charge']);
        }
    }

    /**
     * Handle subscription.created webhook.
     */
    protected function handleSubscriptionCreated(array $payload): void
    {
        $subscriptionData = $payload['subscription'] ?? $payload;
        $subscriptionId = $subscriptionData['id'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        $subscription = \Aizuddinmanap\CashierChip\Subscription::where('chip_id', $subscriptionId)->first();

        if ($subscription) {
            $subscription->update([
                'chip_status' => $subscriptionData['status'] ?? 'active',
            ]);

            Event::dispatch(new \Aizuddinmanap\CashierChip\Events\SubscriptionCreated($subscription));
        }
    }

    /**
     * Handle subscription.updated webhook.
     */
    protected function handleSubscriptionUpdated(array $payload): void
    {
        $subscriptionData = $payload['subscription'] ?? $payload;
        $subscriptionId = $subscriptionData['id'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        $subscription = \Aizuddinmanap\CashierChip\Subscription::where('chip_id', $subscriptionId)->first();

        if ($subscription) {
            $subscription->update([
                'chip_status' => $subscriptionData['status'] ?? $subscription->chip_status,
                'quantity' => $subscriptionData['quantity'] ?? $subscription->quantity,
            ]);

            Event::dispatch(new \Aizuddinmanap\CashierChip\Events\SubscriptionUpdated($subscription));
        }
    }

    /**
     * Handle subscription.cancelled webhook.
     */
    protected function handleSubscriptionCancelled(array $payload): void
    {
        $subscriptionData = $payload['subscription'] ?? $payload;
        $subscriptionId = $subscriptionData['id'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        $subscription = \Aizuddinmanap\CashierChip\Subscription::where('chip_id', $subscriptionId)->first();

        if ($subscription) {
            $cancelledAt = isset($subscriptionData['cancelled_at'])
                ? Carbon::parse($subscriptionData['cancelled_at'])
                : now();

            $endsAt = $cancelledAt;

            if (isset($subscriptionData['next_billing_date']) &&
                Carbon::parse($subscriptionData['next_billing_date'])->isFuture()) {
                $endsAt = Carbon::parse($subscriptionData['next_billing_date']);
            }

            $subscription->update([
                'chip_status' => 'cancelled',
                'ends_at' => $endsAt,
            ]);

            Event::dispatch(new \Aizuddinmanap\CashierChip\Events\SubscriptionCanceled($subscription));
        }
    }

    /**
     * Handle subscription.expired webhook.
     */
    protected function handleSubscriptionExpired(array $payload): void
    {
        $subscriptionData = $payload['subscription'] ?? $payload;
        $subscriptionId = $subscriptionData['id'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        $subscription = \Aizuddinmanap\CashierChip\Subscription::where('chip_id', $subscriptionId)->first();

        if ($subscription) {
            $subscription->update([
                'chip_status' => 'expired',
                'ends_at' => now(),
            ]);
        }
    }

    /**
     * Store recurring token from a completed purchase if present.
     * Reads is_recurring_token / recurring_token fields from the Chip payment response.
     */
    protected function maybeStoreRecurringToken(array $payload): void
    {
        $isRecurringToken = $payload['is_recurring_token'] ?? false;
        $recurringToken = $payload['recurring_token'] ?? null;

        if (! $isRecurringToken && empty($recurringToken)) {
            return;
        }

        // Find the billable entity from the purchase metadata or client
        $billable = $this->resolveBillableFromPayload($payload);

        if (! $billable) {
            return;
        }

        // Store the payment method using the PaymentMethod model
        $paymentMethod = $billable->storePaymentMethodFromChip($payload);

        if ($paymentMethod) {
            Log::info('Recurring token stored from webhook', [
                'purchase_id' => $payload['id'],
                'token_id' => $paymentMethod->chip_token_id,
            ]);
        }
    }

    /**
     * Create a subscription record if the completed purchase was for a subscription.
     */
    protected function maybeCreateSubscriptionFromPurchase(array $payload): void
    {
        $metadata = $payload['purchase']['metadata'] ?? $payload['metadata'] ?? [];

        if (empty($metadata['subscription_name']) || empty($metadata['price_id'])) {
            return;
        }

        $billable = $this->resolveBillableFromPayload($payload);

        if (! $billable) {
            return;
        }

        // Check if subscription already exists
        $existing = $billable->subscriptions()
            ->where('name', $metadata['subscription_name'])
            ->where('chip_price_id', $metadata['price_id'])
            ->whereIn('chip_status', ['active', 'trialing'])
            ->first();

        if ($existing) {
            return;
        }

        $billable->subscriptions()->create([
            'name' => $metadata['subscription_name'],
            'chip_id' => $payload['id'],
            'chip_status' => 'active',
            'chip_price_id' => $metadata['price_id'],
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
        ]);
    }

    /**
     * Delete invalid recurring token on charge failure.
     */
    protected function maybeDeleteInvalidToken(array $payload): void
    {
        $errors = $payload['__all__'] ?? [];

        if (! is_array($errors)) {
            return;
        }

        foreach ($errors as $error) {
            if (isset($error['code']) && $error['code'] === 'invalid_recurring_token') {
                $tokenId = $payload['recurring_token'] ?? $payload['id'] ?? null;

                if ($tokenId) {
                    PaymentMethod::where('chip_token_id', $tokenId)->delete();

                    Log::info('Deleted invalid recurring token', ['token_id' => $tokenId]);
                }

                break;
            }
        }
    }

    /**
     * Resolve the billable entity from a webhook payload.
     */
    protected function resolveBillableFromPayload(array $payload): ?object
    {
        // Try metadata first
        $metadata = $payload['purchase']['metadata'] ?? $payload['metadata'] ?? [];

        if (! empty($metadata['billable_id']) && ! empty($metadata['billable_type'])) {
            $model = $metadata['billable_type'];
            if (class_exists($model)) {
                return $model::find($metadata['billable_id']);
            }
        }

        // Try client email
        $email = $payload['client']['email'] ?? null;

        if ($email) {
            $modelClass = Cashier::billableModel();
            return $modelClass::where('email', $email)->first();
        }

        // Try reference as user ID
        $reference = $payload['reference'] ?? null;

        if ($reference && is_numeric($reference)) {
            $modelClass = Cashier::billableModel();
            return $modelClass::find($reference);
        }

        return null;
    }

    /**
     * Log webhook for debugging purposes.
     */
    protected function logWebhook(string $event, array $payload): void
    {
        if (config('cashier.logging.enabled')) {
            Log::channel(config('cashier.logging.channel'))->info('Chip Webhook Received', [
                'event' => $event,
                'payload' => $payload,
            ]);
        }
    }
}
