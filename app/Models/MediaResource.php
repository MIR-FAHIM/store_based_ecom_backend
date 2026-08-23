<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaResource extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'preview_image_id',
        'width',
        'height',
        'price',
        'currency',
        'resource_type',
        'status',
        'sort_order',
        'instructions',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'preview_image_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'price' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(MediaResourceCategory::class, 'category_id');
    }

    public function previewImage()
    {
        return $this->belongsTo(Upload::class, 'preview_image_id');
    }

    public function fields()
    {
        return $this->hasMany(MediaResourceField::class)->orderBy('sort_order')->orderBy('id');
    }

    public function orderItems()
    {
        return $this->hasMany(MediaResourceOrderItem::class);
    }
}
