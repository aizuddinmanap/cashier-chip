<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Events;

use Aizuddinmanap\CashierChip\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * The subscription instance.
     */
    public Subscription $subscription;

    /**
     * Create a new event instance.
     */
    public function __construct(Subscription $subscription)
    {
        $this->subscription = $subscription;
    }
} 