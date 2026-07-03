<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use DateTimeInterface;

/**
 * Pure, deterministic proration math — no database, no side effects, no policy.
 *
 * Call it only if you want proration. It computes the time-based difference
 * between an old and new plan amount over the remaining part of a billing
 * period. What you *do* with the result (charge now, credit forward, defer to
 * renewal, apply tax, round differently) is your business decision, not the
 * library's.
 */
class Proration
{
    /**
     * Compute the proration for switching amounts mid-period.
     *
     * Returns cents to settle for the unused remainder of the period:
     *   > 0  extra owed now (upgrade)
     *   < 0  credit owed to the customer (downgrade)
     *   = 0  no time remaining, or amounts equal
     *
     * @param  int  $oldAmount  Current plan amount in cents
     * @param  int  $newAmount  New plan amount in cents
     */
    public static function calculate(
        int $oldAmount,
        int $newAmount,
        DateTimeInterface $periodStart,
        DateTimeInterface $periodEnd,
        ?DateTimeInterface $now = null
    ): int {
        $start = $periodStart->getTimestamp();
        $end = $periodEnd->getTimestamp();
        $at = ($now ?? new \DateTimeImmutable())->getTimestamp();

        $total = $end - $start;

        if ($total <= 0) {
            return 0;
        }

        // Remaining fraction of the period, clamped to [0, 1].
        $remaining = max(0, min($total, $end - $at));

        return (int) round(($newAmount - $oldAmount) * ($remaining / $total));
    }
}
