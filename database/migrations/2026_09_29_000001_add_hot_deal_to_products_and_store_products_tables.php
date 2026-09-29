<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'hot_deal')) {
            Schema::table('products', function (Blueprint $table) {
                $table->tinyInteger('hot_deal')->default(0)->after('todays_deal');
            });
        }

        if (Schema::hasTable('store_products') && !Schema::hasColumn('store_products', 'hot_deal')) {
            Schema::table('store_products', function (Blueprint $table) {
                $table->boolean('hot_deal')->default(false)->after('todays_deal');
                $table->index(['store_id', 'hot_deal']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'hot_deal')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('hot_deal');
            });
        }

        if (Schema::hasTable('store_products') && Schema::hasColumn('store_products', 'hot_deal')) {
            Schema::table('store_products', function (Blueprint $table) {
                $table->dropColumn('hot_deal');
            });
        }
    }
};
