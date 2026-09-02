<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConversationMessage extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_ORDER = 'order';
    public const TYPE_ORDER_STATUS = 'order_status';
    public const TYPE_SYSTEM = 'system';

    public const SENDER_CUSTOMER = 'customer';
    public const SENDER_SHOP = 'shop';
    public const SENDER_SYSTEM = 'system';

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'sender_type',
        'message_type',
        'message',
        'product_id',
        'order_id',
        'reply_to_message_id',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'sender_id' => 'integer',
        'product_id' => 'integer',
        'order_id' => 'integer',
        'reply_to_message_id' => 'integer',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function replyTo()
    {
        return $this->belongsTo(ConversationMessage::class, 'reply_to_message_id');
    }

    public function replies()
    {
        return $this->hasMany(ConversationMessage::class, 'reply_to_message_id');
    }
}
