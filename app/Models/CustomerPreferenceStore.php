<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerPreferenceStore extends Model
{
    use HasFactory;

    protected $table = 'customer_preferences_store';

    protected $fillable = [
        'customer_user_id',
        'seller_id',
        'added_by',
        'added_by_type',
        'status',
    ];

    protected $casts = [
        'customer_user_id' => 'integer',
        'seller_id' => 'integer',
        'added_by' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
