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
        if (!Schema::hasTable('customer_ledgers')) {
            Schema::create('customer_ledgers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shop_id')->index();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('customer_id')->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->enum('type', ['DUE', 'PAYMENT', 'RETURN', 'ADJUSTMENT'])->default('DUE');
                $table->decimal('amount', 12, 2);
                $table->decimal('paid_amount', 12, 2)->default(0.00);
                $table->decimal('due_amount', 12, 2)->default(0.00);
                $table->decimal('running_balance', 12, 2)->default(0.00);
                $table->string('payment_method', 50)->nullable();
                $table->date('due_date')->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_ledgers');
    }
};
