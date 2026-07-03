<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // When the next token-based renewal is due. cashier:renew charges
            // subscriptions whose renews_at has passed. Null = not a token-based
            // recurring subscription (e.g. a Billing Template sub, renewed by Chip).
            $table->timestamp('renews_at')->nullable()->after('ends_at');

            // A plan change scheduled for the next renewal. swap() sets this;
            // cashier:renew applies it (chip_price_id = pending_plan_id) at rollover.
            $table->string('pending_plan_id')->nullable()->after('chip_price_id');

            $table->index(['chip_status', 'renews_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['chip_status', 'renews_at']);
            $table->dropColumn(['renews_at', 'pending_plan_id']);
        });
    }
};
