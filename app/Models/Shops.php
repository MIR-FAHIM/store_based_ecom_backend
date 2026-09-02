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
        'code',
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

    protected static function booted(): void
    {
        static::creating(function (Shops $shop) {
            if (empty($shop->code)) {
                $shop->code = static::generateUniqueCode();
            }
        });
    }

    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = $value ? strtoupper(trim($value)) : null;
    }

    private static function generateUniqueCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (static::where('code', $code)->exists());

        return $code;
    }
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

    public function mediaResourceOrders()
    {
        return $this->hasMany(MediaResourceOrder::class, 'store_id');
    }

    public function currentSubscription()
    {
        return $this->hasOne(StoreSubscription::class, 'store_id')
            ->whereIn('status', ['active', 'pending'])
            ->latestOfMany();
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class, 'shop_id');
    }
}
