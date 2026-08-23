<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaResourceOrderFieldValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'field_id',
        'field_name',
        'field_type',
        'value',
        'json_value',
    ];

    protected $casts = [
        'order_item_id' => 'integer',
        'field_id' => 'integer',
        'json_value' => 'array',
    ];
}
