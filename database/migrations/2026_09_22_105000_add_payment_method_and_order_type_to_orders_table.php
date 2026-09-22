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
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'payment_method')) {
                    $table->string('payment_method', 50)->nullable()->default('cash')->after('payment_status');
                }
                if (!Schema::hasColumn('orders', 'order_type')) {
                    $table->string('order_type', 30)->nullable()->default('online')->after('payment_method');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (Schema::hasColumn('orders', 'payment_method')) {
                    $table->dropColumn('payment_method');
                }
                if (Schema::hasColumn('orders', 'order_type')) {
                    $table->dropColumn('order_type');
                }
            });
        }
    }
};
