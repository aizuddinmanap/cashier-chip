<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Set for subscriptions backed by a Chip billing template
            // (native recurring). Null for legacy checkout/token subscriptions.
            $table->string('chip_billing_template_id')->nullable()->after('chip_price_id');

            $table->index('chip_billing_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['chip_billing_template_id']);
            $table->dropColumn('chip_billing_template_id');
        });
    }
};
