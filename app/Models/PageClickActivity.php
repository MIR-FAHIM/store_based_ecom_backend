<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PageClickActivity extends Model
{
    use HasFactory;

    protected $table = 'page_click_activities';

    protected $fillable = [
        'activity_name',
        'shop_id',
        'product_id',
        'user_id',
        'note',
        'status',
        'platform',
    ];

    protected $casts = [
        'shop_id' => 'integer',
        'product_id' => 'integer',
        'user_id' => 'integer',
    ];

    // Predefined activity name constants
    public const ACTIVITY_CLICKED_ON_PRODUCT_DETAIL = 'clickedOnProductDetail';
    public const ACTIVITY_CLICKED_ON_CART = 'clickedOnCart';
    public const ACTIVITY_CLICKED_ON_SHOP = 'clickedOnShop';
    public const ACTIVITY_CLICKED_ON_BANNER = 'clickedOnBanner';
    public const ACTIVITY_CLICKED_ON_CATEGORY = 'clickedOnCategory';
    public const ACTIVITY_CLICKED_ON_SEARCH = 'clickedOnSearch';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shops::class, 'shop_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
