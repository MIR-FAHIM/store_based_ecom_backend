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
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable()->after('user_id')->index();
            $table->unsignedBigInteger('staff_id')->nullable()->after('shop_id')->index();
            $table->string('cart_type', 50)->default('customer_online')->after('staff_id');
            $table->string('counter_name', 100)->nullable()->after('cart_type');
            $table->string('hold_code', 50)->nullable()->after('counter_name')->index();
            $table->string('customer_name', 255)->nullable()->after('hold_code');
            $table->string('customer_phone', 50)->nullable()->after('customer_name');
            $table->text('hold_reason')->nullable()->after('customer_phone');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->text('note')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn([
                'shop_id',
                'staff_id',
                'cart_type',
                'counter_name',
                'hold_code',
                'customer_name',
                'customer_phone',
                'hold_reason',
            ]);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn(['note']);
        });
    }
};
