<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RewardClaim extends Model
{
    use HasFactory;

    protected $table = 'reward_claims';

    protected $fillable = [
        'challenge_participant_id',
        'challenge_reward_id',
        'status', // unlocked, claimed, redeemed, revoked
        'redeemed_at',
        'order_id',
    ];

    protected $casts = [
        'redeemed_at' => 'datetime',
    ];

    public function participant()
    {
        return $this->belongsTo(ChallengeParticipant::class, 'challenge_participant_id');
    }

    public function reward()
    {
        return $this->belongsTo(ChallengeReward::class, 'challenge_reward_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
