<?php

namespace App\Service;

use App\Exceptions\FirebaseNotificationException;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shops;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChatService
{
    public function __construct(
        private FirebaseNotificationService $firebaseNotificationService
    ) {}

    public function openConversation(User $user, int $shopId): Conversation
    {
        if (!$this->isCustomerUser($user)) {
            throw ValidationException::withMessages([
                'shop_id' => ['Only customers can open a new shop conversation.'],
            ]);
        }

        $shop = Shops::find($shopId);
        if (!$shop) {
            throw ValidationException::withMessages([
                'shop_id' => ['Shop not found.'],
            ]);
        }

        return Conversation::firstOrCreate(
            [
                'shop_id' => $shop->id,
                'customer_id' => $user->id,
            ],
            [
                'status' => 'active',
            ]
        )->load($this->conversationRelations());
    }

    public function conversationsFor(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $query = Conversation::query()
            ->with($this->conversationRelations())
            ->orderByRaw('last_message_at IS NULL ASC')
            ->orderByDesc('last_message_at')
            ->latest('id');

        $this->scopeVisibleConversations($query, $user);

        $conversations = $query->paginate($this->boundedPerPage($perPage, 50));
        $viewerType = $this->defaultViewerType($user);

        $conversations->getCollection()->each(function (Conversation $conversation) use ($viewerType) {
            $conversation->viewer_type = $viewerType;
        });

        return $conversations;
    }

    public function getConversationFor(User $user, int $conversationId): Conversation
    {
        $conversation = Conversation::with($this->conversationRelations())->find($conversationId);
        if (!$conversation || !$this->canAccessConversation($user, $conversation)) {
            abort(response()->json([
                'status' => 'failed',
                'message' => 'Conversation not found or access denied',
                'errors' => null,
            ], 404));
        }

        $conversation->viewer_type = $this->viewerTypeForConversation($user, $conversation);

        return $conversation;
    }

    public function messagesFor(User $user, Conversation $conversation, int $perPage = 30): LengthAwarePaginator
    {
        $this->assertCanAccessConversation($user, $conversation);

        return $conversation->messages()
            ->with($this->messageRelations())
            ->latest('id')
            ->paginate($this->boundedPerPage($perPage, 100));
    }

    public function sendMessage(User $user, Conversation $conversation, array $payload): ConversationMessage
    {
        $senderType = $this->viewerTypeForConversation($user, $conversation);

        if (!in_array($senderType, [ConversationMessage::SENDER_CUSTOMER, ConversationMessage::SENDER_SHOP], true)) {
            abort(response()->json([
                'status' => 'failed',
                'message' => 'You cannot send messages in this conversation',
                'errors' => null,
            ], 403));
        }

        $this->validateMessagePayload($conversation, $user, $senderType, $payload);

        $message = DB::transaction(function () use ($conversation, $payload, $user, $senderType) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            $message = ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
                'sender_type' => $senderType,
                'message_type' => $payload['message_type'],
                'message' => $payload['message'] ?? null,
                'product_id' => $payload['product_id'] ?? null,
                'order_id' => $payload['order_id'] ?? null,
                'reply_to_message_id' => $payload['reply_to_message_id'] ?? null,
            ]);

            $conversation->forceFill([
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
            ])->save();

            if ($senderType === ConversationMessage::SENDER_CUSTOMER) {
                Conversation::whereKey($conversation->id)->increment('shop_unread_count');
            } else {
                Conversation::whereKey($conversation->id)->increment('customer_unread_count');
            }

            return $message;
        });

        $message->load($this->messageRelations());
        $this->notifyForMessage($message);

        return $message;
    }

    public function markMessageRead(User $user, ConversationMessage $message): ConversationMessage
    {
        $message->loadMissing('conversation.shop');
        $viewerType = $this->viewerTypeForConversation($user, $message->conversation);

        if (!$viewerType) {
            abort(response()->json([
                'status' => 'failed',
                'message' => 'Message not found or access denied',
                'errors' => null,
            ], 404));
        }

        if (!$this->messageIsReadableBy($message, $viewerType)) {
            return $message->load($this->messageRelations());
        }

        if (!$message->is_read) {
            $message->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        $this->recalculateUnreadCount($message->conversation, $viewerType);

        return $message->fresh()->load($this->messageRelations());
    }

    public function markConversationRead(User $user, Conversation $conversation): Conversation
    {
        $viewerType = $this->viewerTypeForConversation($user, $conversation);

        if (!$viewerType) {
            abort(response()->json([
                'status' => 'failed',
                'message' => 'Conversation not found or access denied',
                'errors' => null,
            ], 404));
        }

        $readQuery = ConversationMessage::where('conversation_id', $conversation->id)
            ->where('is_read', false);

        $this->scopeMessagesReadableBy($readQuery, $viewerType);

        $readQuery->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        $this->recalculateUnreadCount($conversation, $viewerType);

        return $conversation->fresh()->load($this->conversationRelations());
    }

    public function createOrderStatusMessage(Order $order, string $status, ?int $shopId = null): void
    {
        $order->loadMissing('items');

        $shopIds = $shopId
            ? collect([(int) $shopId])
            : $order->items->pluck('shop_id')->filter()->unique()->values();

        foreach ($shopIds as $targetShopId) {
            $shop = Shops::find((int) $targetShopId);
            if (!$shop || !$order->user_id) {
                continue;
            }

            $conversation = Conversation::firstOrCreate(
                [
                    'shop_id' => $shop->id,
                    'customer_id' => $order->user_id,
                ],
                [
                    'status' => 'active',
                ]
            );

            $sender = $shop->user_id ? User::find($shop->user_id) : User::find($order->user_id);
            if (!$sender) {
                continue;
            }

            $message = DB::transaction(function () use ($conversation, $sender, $order, $status) {
                $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();

                $message = ConversationMessage::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $sender->id,
                    'sender_type' => ConversationMessage::SENDER_SYSTEM,
                    'message_type' => ConversationMessage::TYPE_ORDER_STATUS,
                    'message' => 'Order ' . $status,
                    'order_id' => $order->id,
                ]);

                $conversation->forceFill([
                    'last_message_id' => $message->id,
                    'last_message_at' => $message->created_at,
                ])->save();

                Conversation::whereKey($conversation->id)->increment('customer_unread_count');

                return $message;
            });

            $message->load($this->messageRelations());
            $this->notifyForMessage($message);
        }
    }

    public function canAccessConversation(User $user, Conversation $conversation): bool
    {
        return $this->viewerTypeForConversation($user, $conversation) !== null;
    }

    private function assertCanAccessConversation(User $user, Conversation $conversation): void
    {
        if (!$this->canAccessConversation($user, $conversation)) {
            abort(response()->json([
                'status' => 'failed',
                'message' => 'Conversation not found or access denied',
                'errors' => null,
            ], 404));
        }
    }

    private function viewerTypeForConversation(User $user, Conversation $conversation): ?string
    {
        if ((int) $conversation->customer_id === (int) $user->id) {
            return ConversationMessage::SENDER_CUSTOMER;
        }

        $conversation->loadMissing('shop');
        if ($conversation->shop && (int) $conversation->shop->user_id === (int) $user->id) {
            return ConversationMessage::SENDER_SHOP;
        }

        return $this->isAdminUser($user) ? 'admin' : null;
    }

    private function defaultViewerType(User $user): string
    {
        if ($this->isSellerUser($user)) {
            return ConversationMessage::SENDER_SHOP;
        }

        return ConversationMessage::SENDER_CUSTOMER;
    }

    private function scopeVisibleConversations(Builder $query, User $user): void
    {
        if ($this->isAdminUser($user)) {
            return;
        }

        if ($this->isSellerUser($user)) {
            $query->whereHas('shop', fn ($shopQuery) => $shopQuery->where('user_id', $user->id));
            return;
        }

        $query->where('customer_id', $user->id);
    }

    private function validateMessagePayload(Conversation $conversation, User $user, string $senderType, array $payload): void
    {
        if (!empty($payload['reply_to_message_id'])) {
            $replyExists = ConversationMessage::where('conversation_id', $conversation->id)
                ->whereKey((int) $payload['reply_to_message_id'])
                ->exists();

            if (!$replyExists) {
                throw ValidationException::withMessages([
                    'reply_to_message_id' => ['Reply message does not belong to this conversation.'],
                ]);
            }
        }

        if (($payload['message_type'] ?? null) === ConversationMessage::TYPE_PRODUCT) {
            $this->assertProductCanBeShared($conversation, (int) ($payload['product_id'] ?? 0));
        }

        if (($payload['message_type'] ?? null) === ConversationMessage::TYPE_ORDER) {
            $this->assertOrderCanBeShared($conversation, $user, $senderType, (int) ($payload['order_id'] ?? 0));
        }
    }

    private function assertProductCanBeShared(Conversation $conversation, int $productId): void
    {
        $product = Product::find($productId);
        if (!$product) {
            throw ValidationException::withMessages([
                'product_id' => ['Product not found.'],
            ]);
        }

        $belongsToShop = (int) $product->shop_id === (int) $conversation->shop_id;
        $belongsToStore = StoreProduct::where('store_id', $conversation->shop_id)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->exists();

        if (!$belongsToShop && !$belongsToStore) {
            throw ValidationException::withMessages([
                'product_id' => ['This product does not belong to the conversation shop.'],
            ]);
        }
    }

    private function assertOrderCanBeShared(Conversation $conversation, User $user, string $senderType, int $orderId): void
    {
        $order = Order::whereKey($orderId)
            ->where('user_id', $conversation->customer_id)
            ->whereHas('items', fn ($query) => $query->where('shop_id', $conversation->shop_id))
            ->first();

        if (!$order) {
            throw ValidationException::withMessages([
                'order_id' => ['Order not found for this conversation.'],
            ]);
        }

        if ($senderType === ConversationMessage::SENDER_CUSTOMER && (int) $order->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'order_id' => ['You cannot share another customer order.'],
            ]);
        }
    }

    private function recalculateUnreadCount(Conversation $conversation, string $viewerType): void
    {
        if ($viewerType === ConversationMessage::SENDER_SHOP) {
            $conversation->update([
                'shop_unread_count' => ConversationMessage::where('conversation_id', $conversation->id)
                    ->where('sender_type', ConversationMessage::SENDER_CUSTOMER)
                    ->where('is_read', false)
                    ->count(),
            ]);
        }

        if ($viewerType === ConversationMessage::SENDER_CUSTOMER) {
            $conversation->update([
                'customer_unread_count' => ConversationMessage::where('conversation_id', $conversation->id)
                    ->where('sender_type', '!=', ConversationMessage::SENDER_CUSTOMER)
                    ->where('is_read', false)
                    ->count(),
            ]);
        }
    }

    private function messageIsReadableBy(ConversationMessage $message, string $viewerType): bool
    {
        if ($viewerType === ConversationMessage::SENDER_SHOP) {
            return $message->sender_type === ConversationMessage::SENDER_CUSTOMER;
        }

        if ($viewerType === ConversationMessage::SENDER_CUSTOMER) {
            return $message->sender_type !== ConversationMessage::SENDER_CUSTOMER;
        }

        return false;
    }

    private function scopeMessagesReadableBy(Builder $query, string $viewerType): void
    {
        if ($viewerType === ConversationMessage::SENDER_SHOP) {
            $query->where('sender_type', ConversationMessage::SENDER_CUSTOMER);
            return;
        }

        if ($viewerType === ConversationMessage::SENDER_CUSTOMER) {
            $query->where('sender_type', '!=', ConversationMessage::SENDER_CUSTOMER);
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function notifyForMessage(ConversationMessage $message): void
    {
        try {
            $message->loadMissing('conversation.shop', 'conversation.customer', 'product', 'order', 'sender');
            $conversation = $message->conversation;

            $recipient = $message->sender_type === ConversationMessage::SENDER_CUSTOMER
                ? ($conversation->shop?->user_id ? User::find($conversation->shop->user_id) : null)
                : $conversation->customer;

            if (!$recipient || (int) $recipient->id === (int) $message->sender_id) {
                return;
            }

            $this->firebaseNotificationService->sendToUser(
                $recipient,
                $this->notificationTitle($message),
                $this->notificationBody($message),
                $this->notificationData($message)
            );
        } catch (FirebaseNotificationException $e) {
            Log::warning('Chat push notification failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
                'errors' => $e->errors(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Chat push notification error', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notificationTitle(ConversationMessage $message): string
    {
        if ($message->sender_type === ConversationMessage::SENDER_CUSTOMER) {
            return $message->sender?->name ?: 'Customer';
        }

        return $message->conversation?->shop?->shop_name
            ?: $message->conversation?->shop?->name
            ?: 'MyZoo';
    }

    private function notificationBody(ConversationMessage $message): string
    {
        return match ($message->message_type) {
            ConversationMessage::TYPE_PRODUCT => 'Product shared with you',
            ConversationMessage::TYPE_ORDER => 'Order ' . ($message->order?->order_number ?: ('#' . $message->order_id)),
            ConversationMessage::TYPE_ORDER_STATUS => trim(($message->order?->order_number ?: ('Order #' . $message->order_id)) . ' - ' . $message->message),
            ConversationMessage::TYPE_SYSTEM => $message->message ?: 'New chat update',
            default => $message->message ?: 'New message',
        };
    }

    private function notificationData(ConversationMessage $message): array
    {
        return [
            'type' => 'chat',
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'shop_id' => $message->conversation?->shop_id,
            'message_type' => $message->message_type,
            'product_id' => $message->product_id,
            'order_id' => $message->order_id,
        ];
    }

    private function conversationRelations(): array
    {
        return [
            'shop',
            'customer',
            'lastMessage.sender',
            'lastMessage.product.primaryImage',
            'lastMessage.order',
        ];
    }

    private function messageRelations(): array
    {
        return [
            'sender',
            'product.primaryImage',
            'order',
            'replyTo',
        ];
    }

    private function boundedPerPage(int $perPage, int $max): int
    {
        return min(max($perPage, 1), $max);
    }

    private function isAdminUser(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        $userType = strtolower((string) ($user->user_type ?? ''));

        return in_array('admin', [$role, $userType], true);
    }

    private function isSellerUser(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        $userType = strtolower((string) ($user->user_type ?? ''));

        return in_array('seller', [$role, $userType], true);
    }

    private function isCustomerUser(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        $userType = strtolower((string) ($user->user_type ?? ''));

        return in_array($role, ['customer', 'user'], true)
            || in_array($userType, ['customer', 'user'], true);
    }
}
