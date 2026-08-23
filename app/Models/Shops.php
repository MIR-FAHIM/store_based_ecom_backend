<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shops extends Model
{
    use HasFactory;

    protected $table = 'shops';

    protected $fillable = [
        'user_id',
        'name',
        'shop_name',
        'slug',
        'description',
        'logo',
        'banner',
        'phone',
        'email',
        'address',
        'zone',
        'district',
        'area',
        'lat',
        'lon',
        'status',
    ];

    protected $casts = [
        'lat' => 'float',
        'lon' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function logo()
    {
        return $this->belongsTo(Upload::class , 'logo' ,);
    }
    public function banner()
    {
        return $this->belongsTo(Upload::class , 'banner' ,);
    }

    public function subscriptions()
    {
        return $this->hasMany(StoreSubscription::class, 'store_id');
    }

    public function storeCategories()
    {
        return $this->hasMany(StoreCategory::class, 'store_id');
    }

    public function storeProducts()
    {
        return $this->hasMany(StoreProduct::class, 'store_id');
    }

    public function currentSubscription()
    {
        return $this->hasOne(StoreSubscription::class, 'store_id')
            ->whereIn('status', ['active', 'pending'])
            ->latestOfMany();
    }
}
