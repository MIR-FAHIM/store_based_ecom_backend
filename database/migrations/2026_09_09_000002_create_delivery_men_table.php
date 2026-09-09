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
        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_men');
    }
};
