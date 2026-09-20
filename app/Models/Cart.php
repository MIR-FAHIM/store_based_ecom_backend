<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'shop_id',
        'staff_id',
        'cart_type',
        'counter_name',
        'hold_code',
        'customer_name',
        'customer_phone',
        'hold_reason',
        'status',
        'total_items',
        'subtotal',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'shop_id' => 'integer',
        'staff_id' => 'integer',
        'total_items' => 'integer',
        'subtotal' => 'float',
    ];

    /**
     * Cart belongs to a user (customer)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Cart belongs to a shop
     */
    public function shop()
    {
        return $this->belongsTo(Shops::class, 'shop_id');
    }

    /**
     * Cart belongs to a staff/cashier
     */
    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /**
     * Cart has many cart items
     */
    public function items()
    {
        return $this->hasMany(CartItem::class, 'cart_id');
    }
}
