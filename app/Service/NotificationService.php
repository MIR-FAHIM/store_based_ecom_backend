<?php

namespace App\Service;

use App\Exceptions\FirebaseNotificationException;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Shops;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    public function __construct(
        private FirebaseNotificationService $firebaseNotificationService
    ) {}

    public function create(User $recipient, array $attributes): Notification
    {
        $notification = Notification::create([
            'user_id' => $recipient->id,
            'shop_id' => $attributes['shop_id'] ?? null,
            'order_id' => $attributes['order_id'] ?? null,
            'type' => $attributes['type'] ?? 'general',
            'title' => $attributes['title'],
            'message' => $attributes['message'],
            'data' => $attributes['data'] ?? null,
        ]);

        try {
            $this->firebaseNotificationService->sendToUser(
                $recipient,
                $notification->title,
                $notification->message,
                [
                    'type' => 'notification',
                    'notification_id' => (string) $notification->id,
                    'notification_type' => $notification->type,
                    'order_id' => $notification->order_id ? (string) $notification->order_id : '',
                ]
            );
        } catch (FirebaseNotificationException $e) {
            Log::warning('Notification push failed', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
                'errors' => $e->errors(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Notification push error', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $notification;
    }

    public function createOrderCreated(Order $order, Shops $shop): ?Notification
    {
        $recipient = $shop->user;
        if (!$recipient) {
            return null;
        }

        return $this->create($recipient, [
            'shop_id' => $shop->id,
            'order_id' => $order->id,
            'type' => 'order_created',
            'title' => 'New Order Received',
            'message' => 'You received a new order #' . $order->order_number . '.',
            'data' => ['order_id' => $order->id, 'shop_id' => $shop->id],
        ]);
    }

    public function createOrderStatusChanged(Order $order, string $oldStatus, string $newStatus, ?Shops $shop = null): ?Notification
    {
        if ($oldStatus === $newStatus || !$order->user) {
            return null;
        }

        $status = strtolower($newStatus);
        $label = ucfirst(str_replace('_', ' ', $status));
        $type = $status === 'cancelled' ? 'order_cancelled' : 'order_status_changed';

        return $this->create($order->user, [
            'shop_id' => $shop?->id,
            'order_id' => $order->id,
            'type' => $type,
            'title' => 'Order ' . $label,
            'message' => 'Your order #' . $order->order_number . ' has been ' . strtolower($label) . '.',
            'data' => [
                'order_id' => $order->id,
                'status' => $status,
                'previous_status' => $oldStatus,
                'shop_id' => $shop?->id,
            ],
        ]);
    }
}
