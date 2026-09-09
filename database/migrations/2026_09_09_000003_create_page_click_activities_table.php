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
        if (!Schema::hasTable('page_click_activities')) {
            Schema::create('page_click_activities', function (Blueprint $table) {
                $table->id();
                $table->string('activity_name')->default('clickedOnProductDetail');
                $table->foreignId('shop_id')->nullable()->index();
                $table->foreignId('product_id')->nullable()->index();
                $table->foreignId('user_id')->nullable()->index();
                $table->text('note')->nullable();
                $table->string('status')->default('active');
                $table->string('platform')->nullable()->default('web');
                $table->timestamps();
            });
        } else {
            Schema::table('page_click_activities', function (Blueprint $table) {
                if (!Schema::hasColumn('page_click_activities', 'activity_name')) {
                    $table->string('activity_name')->default('clickedOnProductDetail');
                }
                if (!Schema::hasColumn('page_click_activities', 'shop_id')) {
                    $table->foreignId('shop_id')->nullable()->index();
                }
                if (!Schema::hasColumn('page_click_activities', 'product_id')) {
                    $table->foreignId('product_id')->nullable()->index();
                }
                if (!Schema::hasColumn('page_click_activities', 'user_id')) {
                    $table->foreignId('user_id')->nullable()->index();
                }
                if (!Schema::hasColumn('page_click_activities', 'note')) {
                    $table->text('note')->nullable();
                }
                if (!Schema::hasColumn('page_click_activities', 'status')) {
                    $table->string('status')->default('active');
                }
                if (!Schema::hasColumn('page_click_activities', 'platform')) {
                    $table->string('platform')->nullable()->default('web');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_click_activities');
    }
};
