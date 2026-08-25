<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_preferences_store', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_user_id')->index();
            $table->unsignedInteger('seller_id')->index();
            $table->unsignedInteger('added_by')->nullable()->index();
            $table->string('added_by_type', 30)->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();

            $table->unique(['customer_user_id', 'seller_id'], 'customer_seller_preference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_preferences_store');
    }
};
