<?php

namespace App\Http\Resources;

use App\Models\ConversationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerType = (string) ($this->viewer_type ?? $request->attributes->get('chat_viewer_type', ''));

        return [
            'id' => (int) $this->id,
            'shop' => $this->whenLoaded('shop', fn () => [
                'id' => (int) $this->shop->id,
                'user_id' => $this->shop->user_id ? (int) $this->shop->user_id : null,
                'name' => $this->shop->name,
                'shop_name' => $this->shop->shop_name,
                'slug' => $this->shop->slug,
                'logo' => $this->shop->logo,
                'status' => $this->shop->status,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => (int) $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'phone' => $this->customer->phone,
                'avatar' => $this->customer->avatar,
            ]),
            'last_message' => $this->whenLoaded('lastMessage', fn () => new ConversationMessageResource($this->lastMessage)),
            'last_message_preview' => $this->lastMessage ? $this->preview($this->lastMessage) : null,
            'unread_count' => $viewerType === ConversationMessage::SENDER_SHOP
                ? (int) $this->shop_unread_count
                : (int) $this->customer_unread_count,
            'customer_unread_count' => (int) $this->customer_unread_count,
            'shop_unread_count' => (int) $this->shop_unread_count,
            'status' => $this->status,
            'last_message_at' => optional($this->last_message_at)->toISOString(),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }

    private function preview(ConversationMessage $message): string
    {
        return match ($message->message_type) {
            ConversationMessage::TYPE_PRODUCT => 'Product: ' . ($message->product?->name ?: 'Product shared with you'),
            ConversationMessage::TYPE_ORDER,
            ConversationMessage::TYPE_ORDER_STATUS => 'Order ' . ($message->order?->order_number ?: ('#' . $message->order_id)),
            ConversationMessage::TYPE_SYSTEM => $message->message ?: 'System message',
            default => (string) $message->message,
        };
    }
}
