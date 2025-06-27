<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('subscription_id');
            $table->string('chip_id')->unique();
            $table->string('chip_product_id');
            $table->string('chip_price_id');
            $table->integer('quantity')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'chip_price_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
    }
}; 