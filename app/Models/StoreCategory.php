<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'category_id',
        'is_active',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'category_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Shops::class, 'store_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
