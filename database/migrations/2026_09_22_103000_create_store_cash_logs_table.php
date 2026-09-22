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
        if (!Schema::hasTable('store_cash_logs')) {
            Schema::create('store_cash_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shop_id')->index();
                $table->unsignedBigInteger('seller_id')->index();
                $table->enum('type', ['OPENING_CASH', 'QUICK_CASH', 'EXPENSE', 'DRAWER_ADJUSTMENT', 'REFUND']);
                $table->enum('flow', ['IN', 'OUT']); // IN = Credit (+), OUT = Debit (-)
                $table->decimal('amount', 12, 2);
                $table->string('category')->nullable();
                $table->text('note')->nullable();
                $table->date('entry_date')->index();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_cash_logs');
    }
};
