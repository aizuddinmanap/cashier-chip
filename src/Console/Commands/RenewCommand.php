<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Console\Commands;

use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\Events\SubscriptionChargeFailed;
use Aizuddinmanap\CashierChip\Events\SubscriptionRenewed;
use Carbon\Carbon;
use Illuminate\Console\Command;
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
                $renewed++;
                continue;
            }

            // Dunning: past_due now, retry after the grace period, notify the app.
            $subscription->update([
                'chip_status' => 'past_due',
                'renews_at' => $now->copy()->addDays($grace),
            ]);

            Event::dispatch(new SubscriptionChargeFailed($subscription, []));
            $pastDue++;
        }

        $this->info("Renewed {$renewed}, past_due {$pastDue}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
