<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json(['status' => 'failed', 'message' => $message, 'errors' => $errors], $code);
    }

    public function index(Request $request)
    {
        $user = $this->authenticatedUser($request);
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        $paginator = Notification::where('user_id', $user->id)->latest()->paginate($perPage);
        $orders = [];
        $general = [];

        foreach ($paginator->getCollection()->groupBy('order_id') as $orderId => $notifications) {
            if (!$orderId) {
                $general = array_merge($general, NotificationResource::collection($notifications)->resolve());
                continue;
            }

            $latest = $notifications->first();
            $orders[] = [
                'order_id' => (int) $orderId,
                'unread_count' => (int) $notifications->where('is_read', false)->count(),
                'is_unread' => $notifications->contains(fn ($notification) => !$notification->is_read),
                'title' => 'Order #' . ($latest->order?->order_number ?: $orderId),
                'latest' => (new NotificationResource($latest))->resolve(),
                'notifications' => NotificationResource::collection($notifications)->resolve(),
            ];
        }

        return $this->success('Notifications fetched successfully', [
            'total_unread' => (int) Notification::where('user_id', $user->id)->where('is_read', false)->count(),
            'orders' => $orders,
            'general' => $general,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function unreadCount(Request $request)
    {
        $user = $this->authenticatedUser($request);
        return $this->success('Unread notification count fetched successfully', [
            'unread_count' => (int) Notification::where('user_id', $user->id)->where('is_read', false)->count(),
        ]);
    }

    public function orderNotifications(Request $request, int $orderId)
    {
        $user = $this->authenticatedUser($request);
        $query = Notification::where('user_id', $user->id)->where('order_id', $orderId);
        if (!$query->exists()) {
            return $this->failed('Order notifications not found', null, 404);
        }

        return $this->success('Order notifications fetched successfully', [
            'order_id' => $orderId,
            'notifications' => NotificationResource::collection($query->latest()->get()),
        ]);
    }

    public function markRead(Request $request, int $notificationId)
    {
        $user = $this->authenticatedUser($request);
        $notification = Notification::where('user_id', $user->id)->find($notificationId);
        if (!$notification) {
            return $this->failed('Notification not found', null, 404);
        }

        $notification->forceFill(['is_read' => true, 'read_at' => now()])->save();
        return $this->success('Notification marked as read successfully', new NotificationResource($notification));
    }

    public function markOrderRead(Request $request, int $orderId)
    {
        $user = $this->authenticatedUser($request);
        Notification::where('user_id', $user->id)
            ->where('order_id', $orderId)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return $this->success('Order notifications marked as read successfully', ['order_id' => $orderId]);
    }

    public function markAllRead(Request $request)
    {
        $user = $this->authenticatedUser($request);
        Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return $this->success('All notifications marked as read successfully');
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->attributes->get('api_user');
        if (!$user) {
            abort(response()->json(['status' => 'failed', 'message' => 'Unauthorized', 'errors' => null], 401));
        }
        return $user;
    }
}
