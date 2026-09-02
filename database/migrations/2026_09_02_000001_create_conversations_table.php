<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->unsignedInteger('customer_id');
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->unsignedInteger('customer_unread_count')->default(0);
            $table->unsignedInteger('shop_unread_count')->default(0);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['shop_id', 'customer_id']);
            $table->index('shop_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
