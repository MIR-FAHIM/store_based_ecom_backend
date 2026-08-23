<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaResourceOrderDeliverable extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'upload_id',
        'file_type',
        'file_path',
        'version',
        'note',
        'uploaded_by',
    ];

    protected $casts = [
        'order_item_id' => 'integer',
        'upload_id' => 'integer',
        'version' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function upload()
    {
        return $this->belongsTo(Upload::class, 'upload_id');
    }
}
