<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Events;

use Aizuddinmanap\CashierChip\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionChargeFailed
{
    use Dispatchable, SerializesModels;

    /**
     * The subscription whose recurring charge failed.
     */
    public Subscription $subscription;

    /**
     * The raw Chip webhook payload for the failed charge.
     */
    public array $payload;

    /**
     * Create a new event instance.
     */
    public function __construct(Subscription $subscription, array $payload = [])
    {
        $this->subscription = $subscription;
        $this->payload = $payload;
    }
}
