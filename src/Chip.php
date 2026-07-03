<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

/**
 * Lightweight Chip client facade.
 *
 * Provides the `$chip->billing->...` entry point. Obtain one via
 * Cashier::client(). Additional sub-managers can hang off this object later.
 */
class Chip
{
    /**
     * The Billing Template manager.
     */
    public Billing $billing;

    public function __construct()
    {
        $this->billing = Cashier::billing();
    }
}
