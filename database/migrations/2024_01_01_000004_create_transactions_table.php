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
        Schema::create('transactions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('chip_id')->unique()->nullable();
            $table->string('customer_id')->nullable();
            $table->morphs('billable');
            $table->string('type')->default('charge'); // charge, refund, etc.
            $table->string('status'); // pending, success, failed, refunded
            $table->string('currency', 3)->default('MYR');
            $table->integer('amount'); // Amount in cents
            $table->string('payment_method')->nullable(); // fpx, card, ewallet, etc.
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('refunded_from')->nullable(); // Reference to original transaction for refunds
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id']);
            $table->index(['customer_id']);
            $table->index(['status']);
            $table->index(['type']);
            $table->index(['chip_id']);
            
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
}; 