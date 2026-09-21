<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerLedger extends Model
{
    use HasFactory;

    protected $table = 'customer_ledgers';

    protected $fillable = [
        'shop_id',
        'seller_id',
        'customer_id',
        'order_id',
        'type',
        'amount',
        'paid_amount',
        'due_amount',
        'running_balance',
        'payment_method',
        'due_date',
        'note',
        'created_by',
    ];

    protected $casts = [
        'shop_id' => 'integer',
        'seller_id' => 'integer',
        'customer_id' => 'integer',
        'order_id' => 'integer',
        'created_by' => 'integer',
        'amount' => 'float',
        'paid_amount' => 'float',
        'due_amount' => 'float',
        'running_balance' => 'float',
        'due_date' => 'date',
    ];

    public function shop()
    {
        return $this->belongsTo(Shops::class, 'shop_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
