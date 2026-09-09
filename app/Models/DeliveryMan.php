<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryMan extends Model
{
    use HasFactory;

    protected $table = 'delivery_men';

    protected $fillable = [
        'store_id',
        'user_id',
        'mobile',
        'emergency_contact',
        'father_name',
        'father_contact',
        'type',
        'earning',
        'status',
        'address',
        'is_verified',
        'note',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'user_id' => 'integer',
        'earning' => 'float',
        'is_verified' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function store()
    {
        return $this->belongsTo(Shops::class, 'store_id');
    }
}
