<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OnlinePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_type',
        'order_id',
        'payment_group_id',
        'order_ids',
        'store_subscription_id',
        'media_resource_order_id',
        'store_id',
        'user_id',
        'gateway',
        'merchant_transaction_id',
        'gateway_transaction_id',
        'amount',
        'currency',
        'status',
        'gateway_fee',
        'gateway_response',
        'initiated_at',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_fee' => 'decimal:2',
        'order_ids' => 'array',
        'store_subscription_id' => 'integer',
        'media_resource_order_id' => 'integer',
        'store_id' => 'integer',
        'gateway_response' => 'array',
        'initiated_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function storeSubscription()
    {
        return $this->belongsTo(StoreSubscription::class);
    }

    public function mediaResourceOrder()
    {
        return $this->belongsTo(MediaResourceOrder::class);
    }

    public function store()
    {
        return $this->belongsTo(Shops::class, 'store_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
