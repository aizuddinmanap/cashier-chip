<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Events;

use Aizuddinmanap\CashierChip\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * The transaction instance.
     */
    public Transaction $transaction;

    /**
     * Create a new event instance.
     */
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }
} 