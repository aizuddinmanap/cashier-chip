<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Authoritative current billing period; null on legacy rows.
            $table->timestamp('current_period_start')->nullable()->after('renews_at');
            $table->timestamp('current_period_end')->nullable()->after('current_period_start');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['current_period_start', 'current_period_end']);
        });
    }
};
