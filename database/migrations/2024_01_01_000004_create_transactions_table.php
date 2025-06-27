<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('billable_id');
            $table->string('billable_type');
            $table->string('chip_id')->unique();
            $table->string('status');
            $table->string('currency', 3);
            $table->unsignedBigInteger('total');
            $table->timestamps();

            $table->index(['billable_id', 'billable_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
}; 