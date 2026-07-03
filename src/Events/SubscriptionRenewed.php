<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Events;

use Aizuddinmanap\CashierChip\Subscription;
use Aizuddinmanap\CashierChip\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionRenewed
{
    use Dispatchable, SerializesModels;

    /**
     * The subscription that was renewed.
     */
    public Subscription $subscription;

    /**
     * The renewal charge transaction.
     */
    public Transaction $transaction;

    /**
     * Create a new event instance.
     */
    public function __construct(Subscription $subscription, Transaction $transaction)
    {
        $this->subscription = $subscription;
        $this->transaction = $transaction;
    }
}
