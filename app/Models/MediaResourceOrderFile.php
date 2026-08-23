<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaResourceOrderFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'field_id',
        'upload_id',
        'file_type',
        'file_path',
        'original_name',
        'note',
    ];

    protected $casts = [
        'order_item_id' => 'integer',
        'field_id' => 'integer',
        'upload_id' => 'integer',
    ];

    public function upload()
    {
        return $this->belongsTo(Upload::class, 'upload_id');
    }
}
