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
        if (Schema::hasTable('customer_preferences_store') && !Schema::hasColumn('customer_preferences_store', 'total_baki')) {
            Schema::table('customer_preferences_store', function (Blueprint $table) {
                $table->decimal('total_baki', 12, 2)->default(0.00)->after('status');
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'paid_amount')) {
                    $table->decimal('paid_amount', 12, 2)->default(0.00)->after('total');
                }
                if (!Schema::hasColumn('orders', 'due_amount')) {
                    $table->decimal('due_amount', 12, 2)->default(0.00)->after('paid_amount');
                }
                if (!Schema::hasColumn('orders', 'due_date')) {
                    $table->date('due_date')->nullable()->after('due_amount');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('customer_preferences_store') && Schema::hasColumn('customer_preferences_store', 'total_baki')) {
            Schema::table('customer_preferences_store', function (Blueprint $table) {
                $table->dropColumn(['total_baki']);
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn(['paid_amount', 'due_amount', 'due_date']);
            });
        }
    }
};
