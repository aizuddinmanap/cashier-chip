<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('chip_token_id')->unique()->comment('Chip purchase ID used as recurring token');
            $table->string('card_brand')->nullable()->comment('visa, mastercard, maestro');
            $table->string('card_last_four', 4)->nullable();
            $table->string('card_expiry_month', 2)->nullable();
            $table->string('card_expiry_year', 4)->nullable();
            $table->string('cardholder_name')->nullable();
            $table->string('card_issuer_country', 2)->nullable();
            $table->string('masked_pan')->nullable();
            $table->string('card_type')->nullable()->comment('debit, credit');
            $table->boolean('is_default')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
