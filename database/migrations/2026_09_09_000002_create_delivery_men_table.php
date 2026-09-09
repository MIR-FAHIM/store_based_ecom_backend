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
        if (!Schema::hasTable('delivery_men')) {
            Schema::create('delivery_men', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->nullable()->index();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('mobile')->nullable();
                $table->string('emergency_contact')->nullable();
                $table->string('father_name')->nullable();
                $table->string('father_contact')->nullable();
                $table->string('type')->nullable()->default('in_house');
                $table->decimal('earning', 12, 2)->default(0.00);
                $table->string('status')->default('active');
                $table->text('address')->nullable();
                $table->boolean('is_verified')->default(false);
                $table->text('note')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('delivery_men', function (Blueprint $table) {
                if (!Schema::hasColumn('delivery_men', 'store_id')) {
                    $table->foreignId('store_id')->nullable()->index();
                }
                if (!Schema::hasColumn('delivery_men', 'user_id')) {
                    $table->foreignId('user_id')->nullable()->index();
                }
                if (!Schema::hasColumn('delivery_men', 'mobile')) {
                    $table->string('mobile')->nullable();
                }
                if (!Schema::hasColumn('delivery_men', 'emergency_contact')) {
                    $table->string('emergency_contact')->nullable();
                }
                if (!Schema::hasColumn('delivery_men', 'father_name')) {
                    $table->string('father_name')->nullable();
                }
                if (!Schema::hasColumn('delivery_men', 'father_contact')) {
                    $table->string('father_contact')->nullable();
                }
                if (!Schema::hasColumn('delivery_men', 'type')) {
                    $table->string('type')->nullable()->default('in_house');
                }
                if (!Schema::hasColumn('delivery_men', 'earning')) {
                    $table->decimal('earning', 12, 2)->default(0.00);
                }
                if (!Schema::hasColumn('delivery_men', 'status')) {
                    $table->string('status')->default('active');
                }
                if (!Schema::hasColumn('delivery_men', 'address')) {
                    $table->text('address')->nullable();
                }
                if (!Schema::hasColumn('delivery_men', 'is_verified')) {
                    $table->boolean('is_verified')->default(false);
                }
                if (!Schema::hasColumn('delivery_men', 'note')) {
                    $table->text('note')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_men');
    }
};
