<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'conversation_id' => (int) $this->conversation_id,
            'sender_type' => $this->sender_type,
            'sender' => $this->whenLoaded('sender', fn () => [
                'id' => (int) $this->sender->id,
                'name' => $this->sender->name,
                'user_type' => $this->sender->user_type,
                'avatar' => $this->sender->avatar,
            ]),
            'message_type' => $this->message_type,
            'message' => $this->message,
            'product_id' => $this->product_id ? (int) $this->product_id : null,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => (int) $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'unit_price' => $this->product->unit_price !== null ? (float) $this->product->unit_price : null,
                'thumbnail_img' => $this->product->thumbnail_img,
                'thumbnail_url' => $this->product->thumbnail_url ?? null,
                'shop_id' => $this->product->shop_id ? (int) $this->product->shop_id : null,
            ] : null),
            'order_id' => $this->order_id ? (int) $this->order_id : null,
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => (int) $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => $this->order->status,
                'payment_status' => $this->order->payment_status,
                'total' => $this->order->total !== null ? (float) $this->order->total : null,
                'created_at' => optional($this->order->created_at)->toISOString(),
            ] : null),
            'reply_to_message_id' => $this->reply_to_message_id ? (int) $this->reply_to_message_id : null,
            'reply_to' => $this->whenLoaded('replyTo', fn () => $this->replyTo ? [
                'id' => (int) $this->replyTo->id,
                'sender_type' => $this->replyTo->sender_type,
                'message_type' => $this->replyTo->message_type,
                'message' => $this->replyTo->message,
                'product_id' => $this->replyTo->product_id ? (int) $this->replyTo->product_id : null,
                'order_id' => $this->replyTo->order_id ? (int) $this->replyTo->order_id : null,
                'created_at' => optional($this->replyTo->created_at)->toISOString(),
            ] : null),
            'is_read' => (bool) $this->is_read,
            'read_at' => optional($this->read_at)->toISOString(),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
