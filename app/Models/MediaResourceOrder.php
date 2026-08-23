<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaResourceOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'store_id',
        'seller_id',
        'status',
        'payment_status',
        'subtotal',
        'discount',
        'total',
        'currency',
        'customer_note',
        'admin_note',
        'assigned_to',
        'paid_at',
        'completed_at',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'seller_id' => 'integer',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'assigned_to' => 'integer',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(Shops::class, 'store_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function assignedDesigner()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function items()
    {
        return $this->hasMany(MediaResourceOrderItem::class, 'order_id');
    }

    public function payments()
    {
        return $this->hasMany(OnlinePayment::class, 'media_resource_order_id');
    }
}
