<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Console\Commands;

use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\Events\TransactionCompleted;
use Aizuddinmanap\CashierChip\Http\ChipApi;
use Aizuddinmanap\CashierChip\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class ReconcileCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cashier:reconcile';

    /**
     * The console command description.
     */
    protected $description = 'Re-query non-terminal Chip transactions and reconcile their status (recovers missed webhooks)';

    /**
     * Non-terminal statuses that are eligible for reconciliation.
     */
    protected array $pendingStatuses = ['pending', 'preauthorized', 'on_hold', 'pending_charge'];

    /**
     * Maximum number of transactions reconciled in a single run.
     */
    protected int $batchSize = 100;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $api = new ChipApi();

        $transactions = $this->resolveTransactions();

        if ($transactions->isEmpty()) {
            $this->info('No transactions to reconcile.');
            return self::SUCCESS;
        }

        $this->info("Reconciling {$transactions->count()} transaction(s)...");

        $changed = 0;
        $failed = 0;

        foreach ($transactions as $transaction) {
            try {
                $purchase = $api->getPurchase($transaction->chip_id);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Reconcile: failed to fetch Chip purchase', [
                    'transaction_id' => $transaction->id,
                    'chip_id' => $transaction->chip_id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  {$transaction->id}: fetch failed ({$e->getMessage()})");
                continue;
            }

            $chipStatus = is_array($purchase) ? ($purchase['status'] ?? null) : null;

            if (! $chipStatus) {
                continue;
            }

            $mapped = Transaction::mapChipStatus($chipStatus);

            // Transient state (created/sent/viewed/pending_execute) or no change.
            // Dead checkouts terminate naturally: Chip expires them (purchases carry
            // a `due`), which maps to 'failed' here. Rows past max_age are excluded
            // from the query, so we never poll them forever.
            if ($mapped === null || $mapped === $transaction->status) {
                continue;
            }

            // Terminal-state protection: never downgrade a settled transaction.
            if (in_array($transaction->status, ['success', 'refunded'], true)) {
                continue;
            }

            $this->line("  {$transaction->id}: {$transaction->status} -> {$mapped} (chip: {$chipStatus})");

            $this->applyStatus($transaction, $mapped, $purchase);

            $changed++;
        }

        $this->info("Updated {$changed} transaction(s)." . ($failed ? " {$failed} fetch failure(s)." : ''));

        return self::SUCCESS;
    }

    /**
     * Resolve the set of transactions to reconcile.
     */
    protected function resolveTransactions()
    {
        $model = Cashier::transactionModel();

        $olderThan = (int) config('cashier.reconcile.older_than', 5);
        $maxAge = (int) config('cashier.reconcile.max_age', 2880);

        return $model::query()
            ->whereIn('status', $this->pendingStatuses)
            ->whereNotNull('chip_id')
            ->where('created_at', '<=', now()->subMinutes($olderThan))
            ->where('created_at', '>=', now()->subMinutes($maxAge))
            ->orderByDesc('created_at')
            ->limit($this->batchSize)
            ->get();
    }

    /**
     * Apply the reconciled status to a transaction, mirroring webhook side effects.
     */
    protected function applyStatus(Transaction $transaction, string $status, array $purchase): void
    {
        $attributes = ['status' => $status];

        if ($status === 'success') {
            $attributes['payment_method'] = $purchase['transaction_data']['payment_method'] ?? $transaction->payment_method;
            $attributes['processed_at'] = now();
        }

        $transaction->update($attributes);

        // Fire the same completion event the webhook would, so downstream logic runs.
        if ($status === 'success') {
            Event::dispatch(new TransactionCompleted($transaction));
        }
    }
}
