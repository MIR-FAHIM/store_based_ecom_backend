<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'shop_id',
        'comment',
        'star_count',
        'status',
        'priority',
        'type',
    ];

    protected $casts = [
        'status' => 'boolean',
        'star_count' => 'integer',
        'priority' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shops::class, 'shop_id');
    }
}
