<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Http\Controllers;

use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\Events\WebhookReceived;
use Aizuddinmanap\CashierChip\Http\ChipApi;
use Aizuddinmanap\CashierChip\Http\Middleware\VerifyWebhookSignature;
use Aizuddinmanap\CashierChip\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
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

        // Don't trust the (replayable) callback body for purchase events — re-fetch
        // the authoritative purchase from Chip, mirroring the official WooCommerce
        // plugin's get_payment() re-query. Falls back to the payload on failure.
        [$payload, $requeried] = $this->requeryPurchase($payload);

        $event = $this->resolveEvent($payload, $requeried);

        $this->logWebhook($event ?? ($payload['event_type'] ?? $payload['status']), $payload);

        Event::dispatch(new WebhookReceived($payload));

        $method = $event ? ($this->eventHandlers()[$event] ?? null) : null;

        if ($method && method_exists($this, $method)) {
            try {
                // Serialize concurrent deliveries for the same purchase so a server
                // callback and its retry/redirect can't race on a read-then-write.
                // This is the portable equivalent of the official WooCommerce
                // plugin's per-order GET_LOCK / pg_advisory_lock.
                Cache::lock('chip_webhook_' . $this->lockId($payload), 15)
                    ->block(
                        (int) config('cashier.webhook.lock_wait', 10),
                        fn () => $this->{$method}($payload)
                    );
            } catch (LockTimeoutException $e) {
                // Another delivery holds the lock and is already handling this
                // purchase. Acknowledge so Chip stops retrying.
                Log::info('Chip webhook lock contention; treating as duplicate delivery', [
                    'event' => $event,
                    'id' => $this->lockId($payload),
                ]);
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
     * The identifier used to serialize concurrent deliveries for the same
     * resource — the purchase id, or the subscription id for subscription events.
     */
    protected function lockId(array $payload): string
    {
        return (string) ($payload['id'] ?? $payload['subscription']['id'] ?? 'unknown');
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

            // Billing-template subscription recurring-charge failure. Chip emits
            // this when an automatic subscription charge fails; it also mails the
            // client a payable invoice. Mirrors the subscription to past_due.
            'purchase.subscription_charge_failure' => 'handleSubscriptionChargeFailure',

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
     * When the purchase was re-queried, the authoritative Purchase status is the
     * source of truth and overrides any claimed event_type — this prevents a
     * spoofed/stale "purchase.paid" envelope from completing an unpaid order.
     * Otherwise prefers an explicit event_type, then falls back to the status
     * delivered by a success_callback / failure_callback. Returns null for a
     * status we deliberately do not act on (e.g. created / sent / viewed).
     */
    protected function resolveEvent(array $payload, bool $authoritative = false): ?string
    {
        if ($authoritative && ! empty($payload['status'])) {
            return $this->statusToEvent($payload['status']);
        }

        if (! empty($payload['event_type'])) {
            return $payload['event_type'];
        }

        if (! empty($payload['status'])) {
            return $this->statusToEvent($payload['status']);
        }

        return null;
    }

    /**
     * Re-fetch the authoritative purchase from Chip for purchase notifications.
     *
     * Mirrors the official WooCommerce plugin, which calls get_payment() rather
     * than trusting the callback body. Returns [payload, wasRequeried]. The
     * authoritative API data is merged over the webhook payload (preserving
     * webhook-only keys such as event_type). Subscription events are not
     * purchases and are skipped; any API failure falls back to the raw payload
     * so delivery still succeeds.
     */
    protected function requeryPurchase(array $payload): array
    {
        if (! config('cashier.webhook.requery', true)) {
            return [$payload, false];
        }

        // Subscription notifications are keyed on a subscription id, not a purchase.
        // The subscription-charge-failure event must keep its event_type too — the
        // failed purchase's authoritative status ("error") would otherwise remap it
        // to a generic purchase.payment_failure and skip the subscription handler.
        $eventType = $payload['event_type'] ?? '';

        if (str_starts_with($eventType, 'subscription.') || $eventType === 'purchase.subscription_charge_failure') {
            return [$payload, false];
        }

        $purchaseId = $payload['id'] ?? null;

        if (! $purchaseId) {
            return [$payload, false];
        }

        try {
            $authoritative = (new ChipApi())->getPurchase($purchaseId);
        } catch (\Throwable $e) {
            Log::warning('Chip purchase re-query failed; falling back to webhook payload', [
                'purchase_id' => $purchaseId,
                'error' => $e->getMessage(),
            ]);

            return [$payload, false];
        }

        if (! is_array($authoritative) || empty($authoritative['status'])) {
            return [$payload, false];
        }

        // Authoritative API fields win; keep webhook-only fields (e.g. event_type).
        return [array_merge($payload, $authoritative), true];
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
            $attributes = [
                'status' => 'success',
                'payment_method' => $payload['transaction_data']['payment_method'] ?? null,
                'processed_at' => now(),
            ];

            // Record test-mode payments for support/debugging visibility — the
            // equivalent of the official WooCommerce plugin's test-mode order note.
            if (array_key_exists('is_test', $payload)) {
                $attributes['metadata'] = array_merge(
                    $transaction->metadata ?? [],
                    ['is_test' => (bool) $payload['is_test']]
                );

                if ($payload['is_test']) {
                    Log::info('Chip payment completed in TEST mode (no real funds moved)', [
                        'purchase_id' => $purchaseId,
                        'transaction_id' => $transaction->id,
                    ]);
                }
            }

            $transaction->update($attributes);

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

        // If this is a Chip billing-template (native subscription) cycle charge,
        // keep the mirrored subscription active and record the renewal.
        $this->maybeSyncTemplateSubscription($payload);
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

        // Never downgrade an already-successful payment (stale/duplicate failure callback).
        if ($transaction && $transaction->status !== 'success') {
            $transaction->update(['status' => 'failed']);
        }

        if (class_exists(\Aizuddinmanap\CashierChip\Payment::class)) {
            $payment = \Aizuddinmanap\CashierChip\Payment::where('chip_id', $purchaseId)->first();

            if ($payment && $payment->status !== 'success') {
                $payment->update(['status' => 'failed']);
            }
        }

        // Clean up invalid recurring tokens (independent of transaction state)
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

        // A refund applies to a paid transaction; only skip if already refunded.
        if ($transaction && $transaction->status !== 'refunded') {
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

        $transaction = \Aizuddinmanap\CashierChip\Transaction::where('chip_id', $purchaseId)->first();

        // Don't process a stale preauth against an already-successful payment.
        if ($transaction && $transaction->status === 'success') {
            return;
        }

        // Card verification flow stores the recurring token without charging
        $this->maybeStoreRecurringToken($payload);

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

        // Never downgrade an already-successful payment.
        if ($transaction && $transaction->status !== 'success') {
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

        // Never downgrade an already-successful payment.
        if ($transaction && $transaction->status !== 'success') {
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
     * Handle purchase.subscription_charge_failure (billing-template subscription).
     *
     * Chip fires this when an automatic subscription charge fails (it also emails
     * the client a payable invoice). Mirror the subscription to past_due so the
     * app can react, and clean up an invalid recurring token if one was flagged.
     */
    protected function handleSubscriptionChargeFailure(array $payload): void
    {
        $this->maybeDeleteInvalidToken($payload);

        $subscription = $this->resolveTemplateSubscription($payload);

        if (! $subscription) {
            return;
        }

        if ($subscription->chip_status !== 'past_due') {
            $subscription->update(['chip_status' => 'past_due']);
        }

        Event::dispatch(new \Aizuddinmanap\CashierChip\Events\SubscriptionChargeFailed($subscription, $payload));
    }

    /**
     * On a successful billing-template cycle charge, keep the mirrored
     * subscription active (recovering from trial/past_due) and record the charge.
     */
    protected function maybeSyncTemplateSubscription(array $payload): void
    {
        $subscription = $this->resolveTemplateSubscription($payload);

        if (! $subscription) {
            return;
        }

        if (in_array($subscription->chip_status, ['past_due', 'trialing'], true)) {
            $subscription->update(['chip_status' => 'active']);
        }

        $this->maybeRecordTemplateCharge($payload, $subscription);
    }

    /**
     * Record a transaction for a Chip-initiated subscription cycle charge.
     *
     * Charges we initiate ourselves already have a local transaction that
     * handlePurchasePaid transitions; Chip-initiated recurring charges do not, so
     * create one here (idempotently, keyed on the purchase id).
     */
    protected function maybeRecordTemplateCharge(array $payload, \Aizuddinmanap\CashierChip\Subscription $subscription): void
    {
        $purchaseId = $payload['id'] ?? null;

        if (! $purchaseId) {
            return;
        }

        if (\Aizuddinmanap\CashierChip\Transaction::where('chip_id', $purchaseId)->exists()) {
            return;
        }

        $billable = $subscription->owner;

        if (! $billable) {
            return;
        }

        $total = $payload['purchase']['total'] ?? $payload['payment']['amount'] ?? null;

        if ($total === null) {
            return;
        }

        $currency = strtoupper($payload['purchase']['currency'] ?? config('cashier.currency', 'MYR'));

        $transaction = $billable->transactions()->create([
            'id' => 'txn_' . uniqid(),
            'chip_id' => $purchaseId,
            'total' => (int) $total,
            'currency' => $currency,
            'status' => 'success',
            'type' => 'charge',
            'description' => 'Subscription charge',
            'payment_method' => $payload['transaction_data']['payment_method'] ?? 'recurring_token',
            'processed_at' => now(),
            'metadata' => [
                'billing_template_id' => $subscription->chip_billing_template_id,
            ],
        ]);

        Event::dispatch(new \Aizuddinmanap\CashierChip\Events\TransactionCompleted($transaction));
    }

    /**
     * Resolve the mirrored subscription for a billing-template purchase payload.
     */
    protected function resolveTemplateSubscription(array $payload): ?\Aizuddinmanap\CashierChip\Subscription
    {
        $templateId = $this->resolveTemplateIdFromPayload($payload);

        if (! $templateId) {
            return null;
        }

        $billable = $this->resolveBillableFromPayload($payload);

        if ($billable) {
            return $billable->subscriptions()
                ->where('chip_billing_template_id', $templateId)
                ->latest('id')
                ->first();
        }

        return \Aizuddinmanap\CashierChip\Subscription::where('chip_billing_template_id', $templateId)
            ->latest('id')
            ->first();
    }

    /**
     * Extract a Chip billing template id from a purchase webhook payload.
     */
    protected function resolveTemplateIdFromPayload(array $payload): ?string
    {
        $templateId = $payload['billing_template_id']
            ?? $payload['purchase']['billing_template_id']
            ?? $payload['billing_template']['id']
            ?? $payload['subscription']['billing_template_id']
            ?? null;

        return $templateId !== null ? (string) $templateId : null;
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

        $subscription = $billable->subscriptions()->create([
            'name' => $metadata['subscription_name'],
            'chip_id' => $payload['id'],
            'chip_status' => 'active',
            'chip_price_id' => $metadata['price_id'],
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
        ]);

        // Schedule the first token-based renewal one interval out (cashier:renew).
        $subscription->renews_at = $subscription->nextRenewalFrom();
        $subscription->save();
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
