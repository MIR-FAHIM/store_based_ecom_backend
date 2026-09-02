<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'customer_id',
        'last_message_id',
        'last_message_at',
        'customer_unread_count',
        'shop_unread_count',
        'status',
    ];

    protected $casts = [
        'shop_id' => 'integer',
        'customer_id' => 'integer',
        'last_message_id' => 'integer',
        'last_message_at' => 'datetime',
        'customer_unread_count' => 'integer',
        'shop_unread_count' => 'integer',
    ];

    public function shop()
    {
        return $this->belongsTo(Shops::class, 'shop_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function messages()
    {
        return $this->hasMany(ConversationMessage::class, 'conversation_id');
    }

    public function lastMessage()
    {
        return $this->belongsTo(ConversationMessage::class, 'last_message_id');
    }
}
