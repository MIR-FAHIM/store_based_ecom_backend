<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebsiteSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'logo_id',
        'banner_id',
        'website_name',
        'slogan',
        'description',
        'short_details',
        'photo_id',
        'type',
        'company_name',
        'management_details',
        'registered_address',
        'trade_license_number',
        'company_phone',
        'company_email',
    ];

    protected $casts = [
        'logo_id' => 'integer',
        'banner_id' => 'integer',
        'photo_id' => 'integer',
    ];

    public function logo()
    {
        return $this->belongsTo(Upload::class, 'logo_id');
    }

    public function banner()
    {
        return $this->belongsTo(Upload::class, 'banner_id');
    }

    public function photo()
    {
        return $this->belongsTo(Upload::class, 'photo_id');
    }
}
