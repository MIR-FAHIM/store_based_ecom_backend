<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaResourceOrderRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'revision_number',
        'requested_by',
        'request_note',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'order_item_id' => 'integer',
        'revision_number' => 'integer',
        'requested_by' => 'integer',
        'resolved_at' => 'datetime',
    ];
}
