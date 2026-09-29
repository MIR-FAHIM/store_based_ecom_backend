<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Challenge extends Model
{
    use HasFactory;

    protected $table = 'reward_challenges';

    protected $fillable = [
        'shop_id',
        'title',
        'description',
        'start_date',
        'end_date',
        'spend_amount',
        'points_awarded',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'spend_amount' => 'decimal:2',
        'points_awarded' => 'integer',
        'is_active' => 'boolean',
    ];

    public function shop()
    {
        return $this->belongsTo(Shops::class, 'shop_id');
    }

    public function rewards()
    {
        return $this->hasMany(ChallengeReward::class);
    }

    public function participants()
    {
        return $this->hasMany(ChallengeParticipant::class);
    }
}
