<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->unsignedInteger('sender_id');
            $table->string('sender_type', 30);
            $table->string('message_type', 30);
            $table->text('message')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('reply_to_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('sender_id')->references('id')->on('users')->restrictOnDelete();
            $table->index('conversation_id');
            $table->index('sender_id');
            $table->index('created_at');
            $table->index(['conversation_id', 'created_at']);
            $table->index(['conversation_id', 'is_read']);
            $table->index('message_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
