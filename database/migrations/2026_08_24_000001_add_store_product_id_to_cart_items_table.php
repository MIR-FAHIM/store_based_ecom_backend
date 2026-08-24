<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('cart_items', 'store_product_id')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->unsignedBigInteger('store_product_id')->nullable()->after('product_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cart_items', 'store_product_id')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->dropColumn('store_product_id');
            });
        }
    }
};

