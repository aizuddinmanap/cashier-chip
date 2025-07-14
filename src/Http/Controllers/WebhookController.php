<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Http\Controllers;

use Aizuddinmanap\CashierChip\Events\WebhookReceived;
use Aizuddinmanap\CashierChip\Http\Middleware\VerifyWebhookSignature;
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
     */
    public function handleWebhook(Request $request): Response
    {
        $payload = $request->all();
        $event = $payload['event_type'] ?? null;

        if (! $event) {
            return response('Webhook missing event type', 400);
        }

        // Log the webhook for debugging
        $this->logWebhook($event, $payload);

        // Fire the webhook received event
        Event::dispatch(new WebhookReceived($payload));

        // Handle specific webhook events
        $method = 'handle' . str_replace('_', '', ucwords(str_replace('.', '_', $event), '_'));

        if (method_exists($this, $method)) {
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
     * Handle purchase.completed webhook.
     */
    protected function handlePurchaseCompleted(array $payload): void
    {
        $purchaseId = $payload['id'] ?? null;
        
        if (! $purchaseId) {
            return;
        }

        // Find the transaction and update its status
        $transaction = \Aizuddinmanap\CashierChip\Transaction::where('chip_id', $purchaseId)->first();
        
        if ($transaction) {
            $transaction->update([
                'status' => 'success',
                'processed_at' => now(),
            ]);

            // Dispatch transaction completed event
            Event::dispatch(new \Aizuddinmanap\CashierChip\Events\TransactionCompleted($transaction));
        }

        // Also update payment if exists
        $payment = \Aizuddinmanap\CashierChip\Payment::where('chip_id', $purchaseId)->first();
        
        if ($payment) {
            $payment->update(['status' => 'success']);
        }
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

        // Find the transaction and update its status
        $transaction = \Aizuddinmanap\CashierChip\Transaction::where('chip_id', $purchaseId)->first();
        
        if ($transaction) {
            $transaction->update(['status' => 'failed']);
        }

        // Also update payment if exists
        $payment = \Aizuddinmanap\CashierChip\Payment::where('chip_id', $purchaseId)->first();
        
        if ($payment) {
            $payment->update(['status' => 'failed']);
        }
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

        // Find the transaction and update its status
        $transaction = \Aizuddinmanap\CashierChip\Transaction::where('chip_id', $purchaseId)->first();
        
        if ($transaction) {
            $transaction->update(['status' => 'refunded']);
        }

        // Also update payment if exists
        $payment = \Aizuddinmanap\CashierChip\Payment::where('chip_id', $purchaseId)->first();
        
        if ($payment) {
            $payment->update(['status' => 'refunded']);
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

        // Find the subscription and update its status
        $subscription = \Aizuddinmanap\CashierChip\Subscription::where('chip_id', $subscriptionId)->first();
        
        if ($subscription) {
            $subscription->update([
                'chip_status' => $subscriptionData['status'] ?? 'active',
            ]);

            // Dispatch subscription created event
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

        // Find the subscription and update its status
        $subscription = \Aizuddinmanap\CashierChip\Subscription::where('chip_id', $subscriptionId)->first();
        
        if ($subscription) {
            $subscription->update([
                'chip_status' => $subscriptionData['status'] ?? $subscription->chip_status,
                'quantity' => $subscriptionData['quantity'] ?? $subscription->quantity,
            ]);

            // Dispatch subscription updated event
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

        // Find the subscription and cancel it
        $subscription = \Aizuddinmanap\CashierChip\Subscription::where('chip_id', $subscriptionId)->first();
        
        if ($subscription) {
            // Determine if this is immediate or scheduled cancellation
            $cancelledAt = isset($subscriptionData['cancelled_at']) 
                ? Carbon::parse($subscriptionData['cancelled_at'])
                : now();
            
            // Set ends_at based on cancellation type
            $endsAt = $cancelledAt;
            
            // If there's a next_billing_date and it's in the future, use grace period
            if (isset($subscriptionData['next_billing_date']) && 
                Carbon::parse($subscriptionData['next_billing_date'])->isFuture()) {
                $endsAt = Carbon::parse($subscriptionData['next_billing_date']);
            }

            $subscription->update([
                'chip_status' => 'cancelled',
                'ends_at' => $endsAt,
            ]);

            // Dispatch subscription cancelled event
            Event::dispatch(new \Aizuddinmanap\CashierChip\Events\SubscriptionCanceled($subscription));
        }
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