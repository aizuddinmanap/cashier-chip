<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('chip_id')->nullable()->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['chip_id']);
            $table->dropColumn(['chip_id', 'trial_ends_at', 'pm_type', 'pm_last_four']);
        });
    }
}; 