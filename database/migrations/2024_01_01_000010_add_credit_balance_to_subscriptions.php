<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Proration credit banked from downgrades; spent by cashier:renew
            // before charging the token. Cents. Default 0 for legacy rows.
            $table->integer('credit_balance')->default(0)->after('current_period_end');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('credit_balance');
        });
    }
};
