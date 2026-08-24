<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'store_product_id',
        'shop_id',
        'qty',
        'unit_price',
        'attribute_id',
        'line_total',
        'status',
    ];

    protected $casts = [
        'store_product_id' => 'integer',
        'qty' => 'integer',
        'unit_price' => 'float',
        'line_total' => 'float',
    ];

    public function cart()
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function storeProduct()
    {
        return $this->belongsTo(StoreProduct::class, 'store_product_id');
    }

    public function productAttribute()
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shops::class, 'shop_id');
    }
}

