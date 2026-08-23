<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'product_id',
        'price',
        'discount',
        'discount_type',
        'stock',
        'sku',
        'title_override',
        'description_override',
        'is_active',
        'is_featured',
        'todays_deal',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'product_id' => 'integer',
        'price' => 'float',
        'discount' => 'float',
        'stock' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'todays_deal' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Shops::class, 'store_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
