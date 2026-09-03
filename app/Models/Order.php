<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_number',
        'payment_group_id',

        'status',
        'payment_status',
        'is_active',

        'customer_name',
        'customer_phone',
        'shipping_address',

        'zone',
        'district',
        'area',
        'lat',
        'lon',

        'subtotal',
        'shipping_fee',
        'discount',
        'total',
        'platform',
        'user_address_id',

        'note',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'shipping_fee' => 'float',
        'discount' => 'float',
        'total' => 'float',
        'lat' => 'float',
        'lon' => 'float',
    ];

    /**
     * Order belongs to a customer
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function userAddress()
    {
        return $this->belongsTo(DeliveryAddress::class, 'user_address_id');
    }
    
    public function deliveryMan()
    {
        return $this->hasOne(AssignDeliveryMan::class, 'order_id')
            ->where('status', 'assigned');
    }

    /**
     * Order has many order items (the truth)
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
}
