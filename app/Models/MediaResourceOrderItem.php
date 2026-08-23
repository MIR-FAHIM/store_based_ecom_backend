<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaResourceOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'media_resource_id',
        'price',
        'quantity',
        'total',
        'resource_snapshot',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'media_resource_id' => 'integer',
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'total' => 'decimal:2',
        'resource_snapshot' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(MediaResourceOrder::class, 'order_id');
    }

    public function resource()
    {
        return $this->belongsTo(MediaResource::class, 'media_resource_id');
    }

    public function fieldValues()
    {
        return $this->hasMany(MediaResourceOrderFieldValue::class, 'order_item_id');
    }

    public function files()
    {
        return $this->hasMany(MediaResourceOrderFile::class, 'order_item_id');
    }

    public function deliverables()
    {
        return $this->hasMany(MediaResourceOrderDeliverable::class, 'order_item_id');
    }

    public function revisions()
    {
        return $this->hasMany(MediaResourceOrderRevision::class, 'order_item_id');
    }
}
