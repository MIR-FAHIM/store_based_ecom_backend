<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaResourceField extends Model
{
    use HasFactory;

    protected $fillable = [
        'media_resource_id',
        'field_name',
        'field_type',
        'label',
        'is_required',
        'options',
        'placeholder',
        'help_text',
        'sort_order',
    ];

    protected $casts = [
        'media_resource_id' => 'integer',
        'is_required' => 'boolean',
        'options' => 'array',
        'sort_order' => 'integer',
    ];

    public function resource()
    {
        return $this->belongsTo(MediaResource::class, 'media_resource_id');
    }
}
