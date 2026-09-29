<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointTransaction extends Model
{
    use HasFactory;

    protected $table = 'reward_point_transactions';

    protected $fillable = [
        'challenge_participant_id',
        'order_id',
        'points',
        'type', // earned, reversed, adjusted, redeemed
        'description',
    ];

    public function participant()
    {
        return $this->belongsTo(ChallengeParticipant::class, 'challenge_participant_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
