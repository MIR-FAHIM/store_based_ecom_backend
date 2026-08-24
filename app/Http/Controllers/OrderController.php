<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\OrderItem;
use App\Models\Shops;
use App\Models\ShippingCost;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors
        ], $code);
    }

    /**
     * POST /orders/checkout
     * Body: user_id, customer_name, customer_phone, shipping_address, zone, district, area, lat, lon, note
     *
     * Converts ACTIVE cart -> order + order_items in ONE DB transaction
     */
    public function checkout(Request $request)
    {
        return $this->checkOutWithDeliveryCharge($request);
    }

    /**
     * POST /orders/checkout
        * Body: user_id, is_outside_dhaka, customer_name, customer_phone, shipping_address, zone, district, area, lat, lon, note
     *
     * Converts ACTIVE cart -> order + order_items in ONE DB transaction
     * and splits the delivery charge across all shop orders.
     */
    public function checkOutWithDeliveryCharge(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => ['required', 'integer', 'exists:users,id'],
                'is_outside_dhaka' => ['nullable', 'integer', 'in:0,1'],
                'user_address_id' => ['nullable', 'integer',],

                'customer_name' => ['nullable', 'string', 'max:255'],
                'customer_phone' => ['nullable', 'string', 'max:50'],
                'shipping_address' => ['nullable', 'string', 'max:1000'],

                'zone' => ['nullable', 'string', 'max:5000'],
                'district' => ['nullable', 'string', 'max:5000'],
                'area' => ['nullable', 'string', 'max:100'],
                'lat' => ['nullable', 'numeric'],
                'lon' => ['nullable', 'numeric'],

                'note' => ['nullable', 'string'],
                'platform' => ['nullable', 'string'],
            ]);

            $cart = Cart::where('user_id', $validated['user_id'])
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$cart) {
                return $this->failed('Active cart not found', null, 404);
            }

            $cartItems = CartItem::with(['product'])
                ->where('cart_id', $cart->id)
                ->get();

            if ($cartItems->count() === 0) {
                return $this->failed('Cart is empty', null, 409);
            }

            DB::beginTransaction();

            $shippingFee = $this->resolveBaseShippingFee($validated);
            $discount = 0;

            // Group cart items by the resolved shop key so one shop always becomes one order.
            $groupedItems = $cartItems->groupBy(function ($cartItem) {
                return $this->resolveCartItemShopId($cartItem) ?? 'unknown';
            });
            $shippingShares = $this->splitAmountAcrossOrders($shippingFee, $groupedItems->count());

            $orders = [];
            $orderIndex = 0;
            $paymentGroupId = 'PAYGRP-' . date('Ymd') . '-' . strtoupper(Str::random(8));
            $totalPayable = 0;

            foreach ($groupedItems as $shopId => $shopItems) {
                // Recalculate subtotal for this shop's items
                $subtotal = 0;
                foreach ($shopItems as $ci) {
                    $subtotal += (float) ($ci->line_total ?? 0);
                }
                $shippingShare = $shippingShares[$orderIndex] ?? 0;
                $total = round(($subtotal + $shippingShare) - $discount, 2);

                // Generate a unique order_number per order
                $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(Str::random(6));

                $order = Order::create([
                    'user_id' => $validated['user_id'],
                    'order_number' => $orderNumber,
                    'payment_group_id' => $paymentGroupId,

                    'status' => 'pending',
                    'payment_status' => 'unpaid',

                    'customer_name' => $validated['customer_name'] ?? null,
                    'customer_phone' => $validated['customer_phone'] ?? null,
                    'shipping_address' => $validated['shipping_address'] ?? null,

                    'zone' => $validated['zone'] ?? null,
                    'district' => $validated['district'] ?? null,
                    'user_address_id' => $validated['user_address_id'] ?? null,
                    'area' => $validated['area'] ?? null,
                    'lat' => $validated['lat'] ?? null,
                    'lon' => $validated['lon'] ?? null,

                    'subtotal' => round($subtotal, 2),
                    'shipping_fee' => $shippingShare,
                    'discount' => $discount,
                    'total' => $total,

                    'note' => $validated['note'] ?? null,
                    'platform' => $validated['platform'] ?? null,
                ]);

                foreach ($shopItems as $ci) {
                    $product = $ci->product;
                    $resolvedShopId = $this->resolveCartItemShopId($ci);

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $ci->product_id,
                        'shop_id' => $resolvedShopId,

                        // Snapshot important product fields
                        'product_name' => $product ? ($product->name ?? null) : null,
                        'sku' => $product ? ($product->sku ?? null) : null,

                        // Snapshot cart-time pricing
                        'unit_price' => $ci->unit_price,
                        'qty' => $ci->qty,
                        'line_total' => $ci->line_total,

                        'status' => 'pending',
                    ]);

                    if ($product) {
                        $product->increment('num_of_sale', max(1, (int) ($ci->qty ?? 1)));
                    }
                }

                $order->load(['items']);
                $orders[] = $order;
                $totalPayable += $total;
                $orderIndex++;
            }

            // Mark cart as checked_out and clear items
            $cart->status = 'checked_out';
            $cart->save();

            CartItem::where('cart_id', $cart->id)->delete();

            DB::commit();

            $smsResult = $this->sendCheckoutConfirmationSms($validated, $orders, $paymentGroupId, $totalPayable);

            return $this->success('Checkout successful. Orders created.', [
                'payment_group_id' => $paymentGroupId,
                'order_ids' => collect($orders)->pluck('id')->values(),
                'total_orders' => count($orders),
                'subtotal' => round(collect($orders)->sum('subtotal'), 2),
                'shipping_fee' => round(collect($orders)->sum('shipping_fee'), 2),
                'total_payable' => round($totalPayable, 2),
                'sms' => $smsResult,
                'orders' => $orders,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    private function sendCheckoutConfirmationSms(array $validated, array $orders, string $paymentGroupId, float $totalPayable): array
    {
        $receiver = $validated['customer_phone'] ?? null;

        if (!$receiver && !empty($validated['user_id'])) {
            $receiver = optional(User::find($validated['user_id']))->phone;
        }

        if (!$receiver) {
            return [
                'status' => 'skipped',
                'message' => 'Customer phone number not found',
            ];
        }

        $apiKey = config('services.muthobarta.api_key');
        $baseUrl = rtrim(config('services.muthobarta.base_url'), '/');

        if (!$apiKey || !$baseUrl) {
            return [
                'status' => 'skipped',
                'message' => 'SMS provider is not configured',
            ];
        }

        $orderNumbers = collect($orders)->pluck('order_number')->filter()->values();
        $reference = $orderNumbers->count() === 1
            ? $orderNumbers->first()
            : $paymentGroupId;

        $message = 'MyZoo: Your order ' . $reference . ' has been placed successfully. Total BDT ' . number_format($totalPayable, 2, '.', '') . '. Thank you.';

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => $apiKey,
                    'Accept' => 'application/json',
                ])
                ->post($baseUrl . '/send-sms', [
                    'receiver' => $receiver,
                    'message' => $message,
                    'sender_id' => 'MyZoo',
                    'remove_duplicate' => true,
                    'type' => 'order_confirmation',
                ]);

            if ($response->failed()) {
                Log::warning('Checkout confirmation SMS failed', [
                    'receiver' => $receiver,
                    'payment_group_id' => $paymentGroupId,
                    'status' => $response->status(),
                    'response' => $response->json() ?? $response->body(),
                ]);

                return [
                    'status' => 'failed',
                    'message' => 'SMS provider request failed',
                    'provider_status' => $response->status(),
                ];
            }

            return [
                'status' => 'success',
                'message' => 'Order confirmation SMS sent successfully',
                'receiver' => $receiver,
            ];
        } catch (\Throwable $e) {
            Log::warning('Checkout confirmation SMS exception', [
                'receiver' => $receiver,
                'payment_group_id' => $paymentGroupId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'message' => 'Could not send order confirmation SMS',
            ];
        }
    }

    private function resolveBaseShippingFee(array $validated): float
    {
        return ((int) ($validated['is_outside_dhaka'] ?? 0) === 1) ? 120.0 : 60.0;
    }

    private function resolveCartItemShopId(CartItem $cartItem): ?int
    {
        if (!empty($cartItem->shop_id)) {
            return (int) $cartItem->shop_id;
        }

        return $cartItem->product ? ($cartItem->product->shop_id ? (int) $cartItem->product->shop_id : null) : null;
    }

    private function splitAmountAcrossOrders(float $amount, int $orderCount): array
    {
        if ($orderCount <= 0) {
            return [];
        }

        $totalCents = (int) round($amount * 100);
        $baseShare = intdiv($totalCents, $orderCount);
        $remainder = $totalCents % $orderCount;

        $shares = [];

        for ($index = 0; $index < $orderCount; $index++) {
            $shareCents = $baseShare + ($index < $remainder ? 1 : 0);
            $shares[] = round($shareCents / 100, 2);
        }

        return $shares;
    }

    /**
     * GET /orders/list/{userId}?per_page=20
     * List orders for a customer
     */
    public function listOrdersByUser($userId, Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $query = Order::where('user_id', $userId)
                ->with(['items.shop', 'userAddress.district', 'userAddress.division']);

            if ($request->filled('store_slug')) {
                $store = Shops::where('slug', $request->query('store_slug'))
                    ->where('status', 'active')
                    ->first();

                if (!$store) {
                    return $this->failed('Store not found or inactive', null, 404);
                }

                $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('shop_id', $store->id));
            }

            $orders = $query->latest()->paginate($perPage);

            return $this->success('Orders fetched successfully', $orders);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
    public function allOrders(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $orders = Order::with(['items.shop', 'userAddress.district', 'userAddress.division'])->where('is_active', 1)
                ->latest()
                ->paginate($perPage);

            // Each order belongs to one shop — append shop_name directly on the order
            foreach ($orders as $order) {
                $firstItem = $order->items->first();
                $order->shop_name = $firstItem ? optional($firstItem->shop)->name : null;
                $order->shop_id   = $firstItem ? $firstItem->shop_id : null;
            }

            return $this->success('Orders fetched successfully', $orders);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /orders/completed
     * List all completed orders (admin)
     */
    public function completedOrders(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $orders = Order::where('status', 'completed')
                ->with(['items', 'user', 'userAddress.district', 'userAddress.division'])
                ->latest()
                ->paginate($perPage);

            return $this->success('Completed orders fetched successfully', $orders);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /orders/completed/{userId}
     * List completed orders for a specific user
     */
    public function completedOrdersByUser($userId, Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $orders = Order::where('user_id', $userId)
                ->where('status', 'completed')
                ->with(['items', 'userAddress.district', 'userAddress.division'])
                ->latest()
                ->paginate($perPage);

            return $this->success('User completed orders fetched successfully', $orders);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /orders/shop/{userId}?per_page=20
     * List orders for a shop owned by a user (via shops.user_id -> order_items.shop_id)
     */
    public function listOrdersByShop($userId, Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $shop = Shops::where('user_id', $userId)->first();
            if (!$shop) {
                return $this->failed('Shop not found for this user', null, 404);
            }

            $items = OrderItem::where('shop_id', $shop->id)
                ->with(['order.user'])
                ->latest()
                ->paginate($perPage);

            return $this->success('Shop order items fetched successfully', $items);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /orders/shop/{shopId}/check/{orderId}
     * Check a specific order for a shop (via order_items.shop_id)
     */
    public function checkShopOrder($shopId, $orderId)
    {
        try {
            $order = Order::where('id', $orderId)
                ->whereHas('items', function ($query) use ($shopId) {
                    $query->where('shop_id', $shopId);
                })
                ->with([
                    'items' => function ($query) use ($shopId) {
                        $query->where('shop_id', $shopId);
                    },
                    'user',
                ])
                ->first();

            if (!$order) {
                return $this->failed('Order not found for this shop', null, 404);
            }

            return $this->success('Order fetched successfully', $order);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /orders/details/{id}
     */
    public function getOrderDetails($id)
    {
        try {
            $order = Order::with(['items.shop', 'deliveryMan.deliveryMan', 'userAddress.district', 'userAddress.division'])->find($id);

            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            return $this->success('Order fetched successfully', $order);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /orders/inactive/{id}
     */
    public function inactiveOrder($id)
    {
        try {
            $order = Order::find($id);

            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            $order->is_active = 0;
            $order->save();

            return $this->success('Order marked inactive successfully', $order);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /orders/status/{id}
     * Body: status
     */
    public function updateOrderStatus(Request $request, $id)
    {
        try {
            $order = Order::find($id);
            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            if ($order->status === 'completed') {
                return $this->failed('Order is already completed and cannot be updated', null);
            }

            $validated = $request->validate([
                'status' => ['required', 'string', 'max:50'],
            ]);

            $order->status = $validated['status'];
            $order->save();

            if ($validated['status'] === 'completed') {
                // Also update all order items to completed
                $order->payment_status = 'paid';
                $order->save();
                OrderItem::where('order_id', $order->id)
                    ->update(['status' => 'completed']);

                Transaction::create([
                    'amount' => $order->total,
                    'trx_type' => 'credit',
                    'status' => 'completed',
                    'source' => 'cod',
                    'order_id' => $order->id,
                    'type' => 'order_payment',
                    'note' => 'Payment received for order #' . $order->order_number,
                ]);
            }

            return $this->success('Order status updated successfully', $order);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /orders/item/status/{id}
     * Body: status
     */
    public function updateOrderItemStatus(Request $request, $id)
    {
        try {
            $item = OrderItem::find($id);
            if (!$item) {
                return $this->failed('Order item not found', null, 404);
            }

            $validated = $request->validate([
                'status' => ['required', 'string', 'max:50'],
            ]);

            $item->status = $validated['status'];
            $item->save();

            return $this->success('Order item status updated successfully', $item);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
