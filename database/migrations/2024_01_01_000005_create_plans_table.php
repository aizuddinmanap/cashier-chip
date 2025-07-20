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
        Schema::create('plans', function (Blueprint $table) {
            $table->string('id')->primary(); // e.g., 'basic_monthly', 'pro_yearly'
            $table->string('chip_price_id')->unique(); // Chip's price ID from API
            $table->string('name'); // "Basic Plan", "Pro Plan"
            $table->text('description')->nullable(); // Plan description
            $table->decimal('price', 10, 2); // 29.99
            $table->string('currency', 3)->default('MYR'); // MYR, USD, SGD
            $table->string('interval'); // month, year, week, day
            $table->integer('interval_count')->default(1); // every X intervals
            $table->json('features')->nullable(); // ["Feature 1", "Feature 2"]
            $table->boolean('active')->default(true); // is plan available
            $table->integer('sort_order')->default(0); // display order
            $table->string('stripe_price_id')->nullable(); // future multi-gateway support
            $table->timestamps();

            // Indexes for performance
            $table->index(['active', 'sort_order']);
            $table->index(['currency', 'active']);
            $table->index('interval');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};