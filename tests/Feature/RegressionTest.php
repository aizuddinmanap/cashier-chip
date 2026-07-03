<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class RegressionTest extends TestCase
{
    #[Test]
    public function subscribed_with_no_arguments_does_not_throw(): void
    {
        // subscribed(?string $name = null) forwarded null into the non-nullable
        // subscription(string $name = 'default'), so $user->subscribed() threw a
        // TypeError under PHP 8.1+.
        $user = $this->createUser();

        $this->assertFalse($user->subscribed());
    }

    #[Test]
    public function published_transactions_migration_runs_without_duplicate_index(): void
    {
        // morphs('billable') already indexes [billable_type, billable_id]; a second
        // explicit index of the same columns reused the same name and failed the
        // migration with "index already exists". The published migration must run.
        $migration = include dirname(__DIR__, 2)
            . '/database/migrations/2024_01_01_000004_create_transactions_table.php';

        Schema::dropIfExists('transactions');

        $migration->up();

        $this->assertTrue(Schema::hasTable('transactions'));
    }
}
