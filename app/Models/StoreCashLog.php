<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreCashLog extends Model
{
    use HasFactory;

    protected $table = 'store_cash_logs';

    protected $fillable = [
        'shop_id',
        'seller_id',
        'type',
        'flow',
        'amount',
        'category',
        'note',
        'entry_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'entry_date' => 'date',
    ];

    public function shop()
    {
        return $this->belongsTo(Shops::class, 'shop_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
