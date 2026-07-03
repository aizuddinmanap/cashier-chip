<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Console\Commands;

use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\Events\SubscriptionChargeFailed;
use Aizuddinmanap\CashierChip\Events\SubscriptionRenewed;
use Aizuddinmanap\CashierChip\Subscription;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class RenewCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cashier:renew {--limit=200 : Maximum subscriptions to process in one run}';

    /**
     * The console command description.
     */
    protected $description = 'Charge token-based subscriptions whose renewal is due (renews_at has passed)';

    /**
     * Execute the console command.
     *
     * Charges each due subscription against its saved token. On success the
     * schedule advances by one interval; on failure the subscription is marked
     * past_due, retried after the grace period, and a SubscriptionChargeFailed
     * event is dispatched for the app's dunning. Cancelled subscriptions
     * (ends_at set) are excluded by the dueForRenewal scope.
     */
    public function handle(): int
    {
        $now = Carbon::now();
        $grace = max(1, (int) config('cashier.subscription.grace_period', 3));

        $model = Cashier::subscriptionModel();

        $due = $model::query()
            ->dueForRenewal($now)
            ->orderBy('renews_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($due->isEmpty()) {
            $this->info('No subscriptions due for renewal.');

            return self::SUCCESS;
        }

        $renewed = 0;
        $pastDue = 0;
        $skipped = 0;
        $noToken = 0;

        foreach ($due as $subscription) {
            // Apply a scheduled plan change (swap) before charging the new period.
            if ($subscription->pending_plan_id) {
                $subscription->forceFill([
                    'chip_price_id' => $subscription->pending_plan_id,
                    'pending_plan_id' => null,
                ])->save();
            }

            // A renewal charge needs an amount, which comes from the local Plan.
            if ($subscription->amount() <= 0) {
                $skipped++;
                $this->warn("Skipping subscription {$subscription->getKey()}: no billable amount (define a Plan for price '{$subscription->chip_price_id}').");
                continue;
            }

            // A renewal also needs a saved token. Distinguish this from a
            // declined card: a missing token means the customer must re-add a
            // card, not that dunning should retry. Flag it and let the app
            // react (distinct from a hard charge failure).
            if (! $this->hasRenewalToken($subscription)) {
                $noToken++;
                $this->flagMissingToken($subscription);
                continue;
            }

            // Serialize per-subscription so overlapping runs (scheduler overlap
            // or a manual run alongside the scheduled one) can't double-charge.
            // The charge + schedule advance happen inside the lock; we re-check
            // renews_at once held so a run that lost the race no-ops.
            try {
                $outcome = Cache::lock(
                    $this->lockKey($subscription),
                    (int) config('cashier.renewal.lock_ttl', 300)
                )->block(
                    (int) config('cashier.renewal.lock_wait', 30),
                    fn () => $this->chargeIfStillDue($subscription, $now, $grace)
                );
            } catch (LockTimeoutException $e) {
                // Another run holds this subscription's lock and is already
                // charging it. Move on; that run reports the result.
                Log::info('Cashier renewal lock contention; another run is processing this subscription', [
                    'subscription_id' => $subscription->getKey(),
                ]);
                continue;
            }

            match ($outcome['status']) {
                'renewed' => $renewed++,
                'past_due' => $pastDue++,
                'skipped' => $skipped++,
                default => null,
            };
        }

        $this->info("Renewed {$renewed}, past_due {$pastDue}, skipped {$skipped}, no_token {$noToken}.");

        return self::SUCCESS;
    }

    /**
     * Determine whether the subscription's owner has a token to charge against.
     *
     * renew() → chargeWithToken() throws "No recurring payment token available"
     * when no default PaymentMethod exists. We surface that *before* charging
     * so it is distinguishable from a declined card (which becomes past_due).
     */
    protected function hasRenewalToken(Subscription $subscription): bool
    {
        $owner = $subscription->owner()->first();

        if (! $owner || ! method_exists($owner, 'defaultPaymentMethod')) {
            return false;
        }

        return ! is_null($owner->defaultPaymentMethod());
    }

    /**
     * Flag a subscription whose owner has no saved token.
     *
     * Sets a distinct requires_payment_method status (past_due semantics:
     * still due-for-renewal so the next run retries once a card is added) and
     * dispatches the charge-failed event so the app can prompt for a card.
     */
    protected function flagMissingToken(Subscription $subscription): void
    {
        $subscription->update(['chip_status' => 'requires_payment_method']);

        $this->warn("Subscription {$subscription->getKey()} has no saved payment method; flagged requires_payment_method.");

        Event::dispatch(new SubscriptionChargeFailed($subscription, ['reason' => 'no_payment_method']));
    }

    /**
     * Charge the subscription if its renewal is still due. Must run inside the
     * per-subscription lock. Returns the outcome bucket for tallying.
     */
    protected function chargeIfStillDue(Subscription $subscription, Carbon $now, int $grace): array
    {
        // Re-check under the lock: another run may have already advanced
        // renews_at while this one waited.
        $subscription->refresh();

        if (! $subscription->renews_at || $subscription->renews_at->isFuture()) {
            return ['status' => 'skipped'];
        }

        try {
            $transaction = $subscription->renew();
            $charged = $transaction && in_array($transaction->status, ['success', 'pending'], true);
        } catch (\Throwable $e) {
            $transaction = null;
            $charged = false;
            Log::warning('Subscription renewal failed', [
                'subscription_id' => $subscription->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        if ($charged) {
            $subscription->update([
                'chip_status' => 'active',
                'renews_at' => $subscription->nextRenewalFrom($now),
            ]);
            Event::dispatch(new SubscriptionRenewed($subscription, $transaction));

            return ['status' => 'renewed'];
        }

        // Dunning: past_due now, retry after the grace period, notify the app.
        $subscription->update([
            'chip_status' => 'past_due',
            'renews_at' => $now->copy()->addDays($grace),
        ]);

        Event::dispatch(new SubscriptionChargeFailed($subscription, []));

        return ['status' => 'past_due'];
    }

    /**
     * The cache lock key for a subscription's renewal critical section.
     */
    protected function lockKey(Subscription $subscription): string
    {
        return 'cashier_renew_' . $subscription->getKey();
    }
}
