<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('reward_claims');
        Schema::dropIfExists('reward_point_transactions');
        Schema::dropIfExists('reward_challenge_participants');
        Schema::dropIfExists('reward_challenge_rewards');
        Schema::dropIfExists('reward_challenges');

        Schema::create('reward_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->decimal('spend_amount', 10, 2);
            $table->integer('points_awarded');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('reward_challenge_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained('reward_challenges')->cascadeOnDelete();
            $table->integer('points_required');
            $table->enum('reward_type', ['PRODUCT', 'DISCOUNT', 'VOUCHER', 'FREE_DELIVERY', 'CUSTOM']);
            $table->string('reward_value')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('reward_challenge_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained('reward_challenges')->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->integer('current_points')->default(0);
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('reward_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_participant_id')->constrained('reward_challenge_participants')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->integer('points');
            $table->enum('type', ['earned', 'reversed', 'adjusted', 'redeemed']);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('reward_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_participant_id')->constrained('reward_challenge_participants')->cascadeOnDelete();
            $table->foreignId('challenge_reward_id')->constrained('reward_challenge_rewards')->cascadeOnDelete();
            $table->enum('status', ['unlocked', 'claimed', 'redeemed', 'revoked'])->default('unlocked');
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_claims');
        Schema::dropIfExists('reward_point_transactions');
        Schema::dropIfExists('reward_challenge_participants');
        Schema::dropIfExists('reward_challenge_rewards');
        Schema::dropIfExists('reward_challenges');
    }
};
