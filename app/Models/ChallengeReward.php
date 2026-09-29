<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChallengeReward extends Model
{
    use HasFactory;

    protected $table = 'reward_challenge_rewards';

    protected $fillable = [
        'challenge_id',
        'points_required',
        'reward_type',
        'reward_value',
        'name',
    ];

    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }

    public function claims()
    {
        return $this->hasMany(RewardClaim::class);
    }
}
