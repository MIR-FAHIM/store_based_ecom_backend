<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shops;
use App\Models\StoreProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SellerCartController extends Controller
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

    private function loadCartRelations(Cart $cart): Cart
    {
        return $cart->load([
            'items.product.primaryImage',
            'items.storeProduct',
            'items.shop',
            'items.productAttribute.attribute',
            'items.productAttribute.value'
        ]);
    }

    private function recalculateCart(Cart $cart): Cart
    {
        $items = CartItem::where('cart_id', $cart->id)->get();
        $totalItems = (int) $items->sum('qty');
        $subtotal = round((float) $items->sum('line_total'), 2);

        $cart->update([
            'total_items' => $totalItems,
            'subtotal' => $subtotal,
        ]);

        return $this->loadCartRelations($cart);
    }

    private function generateHoldCode(): string
    {
        do {
            $code = 'HOLD-' . strtoupper(Str::random(5));
        } while (Cart::where('hold_code', $code)->exists());

        return $code;
    }

    /**
     * GET /api/seller/stores/{storeId}/pos/cart
     * Get or create active POS counter cart for a seller store
     */
    public function getActiveCart(Request $request, $storeId)
    {
        try {
            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $counterName = $request->input('counter_name', 'Counter 1');
            $staffId = $request->attributes->get('api_user')?->id;

            $cart = Cart::where('shop_id', $storeId)
                ->where('cart_type', 'pos_counter')
                ->where('counter_name', $counterName)
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$cart) {
                $cart = Cart::create([
                    'shop_id' => $storeId,
                    'staff_id' => $staffId,
                    'cart_type' => 'pos_counter',
                    'counter_name' => $counterName,
                    'status' => 'active',
                    'total_items' => 0,
                    'subtotal' => 0,
                ]);
            }

            $cart = $this->loadCartRelations($cart);
            return $this->success('Active POS cart fetched successfully', $cart);
        } catch (\Throwable $e) {
            return $this->failed('Could not fetch active POS cart', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/seller/stores/{storeId}/pos/cart/items/add
     * Add product to active POS counter cart
     */
    public function addItem(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'counter_name' => ['nullable', 'string', 'max:100'],
                'product_id' => ['nullable', 'integer', 'exists:products,id'],
                'store_product_id' => ['nullable', 'integer', 'exists:store_products,id'],
                'qty' => ['required', 'integer', 'min:1'],
                'unit_price' => ['nullable', 'numeric', 'min:0'],
                'attribute_id' => ['nullable', 'integer'],
                'customer_name' => ['nullable', 'string', 'max:255'],
                'customer_phone' => ['nullable', 'string', 'max:50'],
                'note' => ['nullable', 'string'],
            ]);

            $counterName = $validated['counter_name'] ?? 'Counter 1';
            $staffId = $request->attributes->get('api_user')?->id;

            $cart = Cart::where('shop_id', $storeId)
                ->where('cart_type', 'pos_counter')
                ->where('counter_name', $counterName)
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$cart) {
                $cart = Cart::create([
                    'shop_id' => $storeId,
                    'staff_id' => $staffId,
                    'cart_type' => 'pos_counter',
                    'counter_name' => $counterName,
                    'status' => 'active',
                    'total_items' => 0,
                    'subtotal' => 0,
                ]);
            }

            if (!empty($validated['customer_name']) || !empty($validated['customer_phone'])) {
                $cart->update([
                    'customer_name' => $validated['customer_name'] ?? $cart->customer_name,
                    'customer_phone' => $validated['customer_phone'] ?? $cart->customer_phone,
                ]);
            }

            $product = null;
            $unitPrice = 0.0;

            if (!empty($validated['store_product_id'])) {
                $storeProduct = StoreProduct::with('product')->find($validated['store_product_id']);
                if ($storeProduct) {
                    $unitPrice = $validated['unit_price'] ?? ($storeProduct->price ?? $storeProduct->product?->unit_price ?? 0.0);
                    $product = $storeProduct->product;
                }
            } elseif (!empty($validated['product_id'])) {
                $product = Product::find($validated['product_id']);
                if ($product) {
                    $unitPrice = $validated['unit_price'] ?? ($product->unit_price ?? 0.0);
                }
            }

            if (!$product && empty($validated['store_product_id'])) {
                return $this->failed('Invalid product or store_product_id', null, 400);
            }

            $cartItem = CartItem::where('cart_id', $cart->id)
                ->where(function ($query) use ($validated) {
                    if (!empty($validated['store_product_id'])) {
                        $query->where('store_product_id', $validated['store_product_id']);
                    } else {
                        $query->where('product_id', $validated['product_id']);
                    }
                })
                ->where('attribute_id', $validated['attribute_id'] ?? null)
                ->first();

            if ($cartItem) {
                $newQty = $cartItem->qty + $validated['qty'];
                $cartItem->update([
                    'qty' => $newQty,
                    'unit_price' => $unitPrice,
                    'line_total' => round($newQty * $unitPrice, 2),
                    'note' => $validated['note'] ?? $cartItem->note,
                ]);
            } else {
                CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product?->id,
                    'store_product_id' => $validated['store_product_id'] ?? null,
                    'shop_id' => $storeId,
                    'qty' => $validated['qty'],
                    'unit_price' => $unitPrice,
                    'attribute_id' => $validated['attribute_id'] ?? null,
                    'line_total' => round($validated['qty'] * $unitPrice, 2),
                    'status' => 'active',
                    'note' => $validated['note'] ?? null,
                ]);
            }

            $updatedCart = $this->recalculateCart($cart);
            return $this->success('Item added to POS cart', $updatedCart);
        } catch (\Throwable $e) {
            return $this->failed('Could not add item to POS cart', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/seller/stores/{storeId}/pos/cart/items/update/{itemId}
     * Update qty or price of an item in active POS cart
     */
    public function updateItem(Request $request, $storeId, $itemId)
    {
        try {
            $validated = $request->validate([
                'qty' => ['required', 'integer', 'min:1'],
                'unit_price' => ['nullable', 'numeric', 'min:0'],
                'note' => ['nullable', 'string'],
            ]);

            $cartItem = CartItem::where('id', $itemId)
                ->where('shop_id', $storeId)
                ->first();

            if (!$cartItem) {
                return $this->failed('Cart item not found', null, 404);
            }

            $cart = Cart::find($cartItem->cart_id);
            if (!$cart || $cart->status !== 'active') {
                return $this->failed('Cart is not active', null, 400);
            }

            $unitPrice = $validated['unit_price'] ?? $cartItem->unit_price;
            $cartItem->update([
                'qty' => $validated['qty'],
                'unit_price' => $unitPrice,
                'line_total' => round($validated['qty'] * $unitPrice, 2),
                'note' => $validated['note'] ?? $cartItem->note,
            ]);

            $updatedCart = $this->recalculateCart($cart);
            return $this->success('Cart item updated', $updatedCart);
        } catch (\Throwable $e) {
            return $this->failed('Could not update cart item', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/seller/stores/{storeId}/pos/cart/items/remove/{itemId}
     * Remove an item from active POS cart
     */
    public function removeItem(Request $request, $storeId, $itemId)
    {
        try {
            $cartItem = CartItem::where('id', $itemId)
                ->where('shop_id', $storeId)
                ->first();

            if (!$cartItem) {
                return $this->failed('Cart item not found', null, 404);
            }

            $cart = Cart::find($cartItem->cart_id);
            $cartItem->delete();

            $updatedCart = $cart ? $this->recalculateCart($cart) : null;
            return $this->success('Cart item removed', $updatedCart);
        } catch (\Throwable $e) {
            return $this->failed('Could not remove cart item', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/seller/stores/{storeId}/pos/cart/hold
     * Park / Hold current active POS cart and generate a Hold Code (C-104 / Token)
     */
    public function holdCart(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'counter_name' => ['nullable', 'string', 'max:100'],
                'hold_reason' => ['nullable', 'string'],
                'customer_name' => ['nullable', 'string', 'max:255'],
                'customer_phone' => ['nullable', 'string', 'max:50'],
            ]);

            $counterName = $validated['counter_name'] ?? 'Counter 1';
            $cart = Cart::where('shop_id', $storeId)
                ->where('cart_type', 'pos_counter')
                ->where('counter_name', $counterName)
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$cart || $cart->items()->count() === 0) {
                return $this->failed('Active cart is empty or not found', null, 400);
            }

            $holdCode = $this->generateHoldCode();
            $cart->update([
                'status' => 'held',
                'hold_code' => $holdCode,
                'hold_reason' => $validated['hold_reason'] ?? 'Parked by counter',
                'customer_name' => $validated['customer_name'] ?? $cart->customer_name,
                'customer_phone' => $validated['customer_phone'] ?? $cart->customer_phone,
            ]);

            $staffId = $request->attributes->get('api_user')?->id;
            $newActiveCart = Cart::create([
                'shop_id' => $storeId,
                'staff_id' => $staffId,
                'cart_type' => 'pos_counter',
                'counter_name' => $counterName,
                'status' => 'active',
                'total_items' => 0,
                'subtotal' => 0,
            ]);

            return $this->success('Cart parked/held successfully', [
                'held_cart' => $this->loadCartRelations($cart),
                'hold_code' => $holdCode,
                'new_active_cart' => $this->loadCartRelations($newActiveCart),
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Could not hold cart', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/seller/stores/{storeId}/pos/cart/held-list
     * List all parked/held bills for a store
     */
    public function listHeldCarts(Request $request, $storeId)
    {
        try {
            $heldCarts = Cart::with([
                'items.product.primaryImage',
                'items.storeProduct',
                'staff'
            ])
            ->where('shop_id', $storeId)
            ->where('status', 'held')
            ->latest()
            ->get();

            return $this->success('Held carts retrieved', $heldCarts);
        } catch (\Throwable $e) {
            return $this->failed('Could not fetch held carts', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/seller/stores/{storeId}/pos/cart/resume
     * Resume a parked bill by cart_id or hold_code
     */
    public function resumeCart(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'cart_id' => ['nullable', 'integer', 'exists:carts,id'],
                'hold_code' => ['nullable', 'string'],
                'counter_name' => ['nullable', 'string', 'max:100'],
            ]);

            if (empty($validated['cart_id']) && empty($validated['hold_code'])) {
                return $this->failed('cart_id or hold_code is required', null, 422);
            }

            $query = Cart::where('shop_id', $storeId)->where('status', 'held');
            if (!empty($validated['cart_id'])) {
                $query->where('id', $validated['cart_id']);
            } else {
                $query->where('hold_code', $validated['hold_code']);
            }

            $heldCart = $query->first();
            if (!$heldCart) {
                return $this->failed('Held cart not found or already processed', null, 404);
            }

            $counterName = $validated['counter_name'] ?? $heldCart->counter_name ?? 'Counter 1';

            // Mark any current empty active cart as abandoned
            Cart::where('shop_id', $storeId)
                ->where('cart_type', 'pos_counter')
                ->where('counter_name', $counterName)
                ->where('status', 'active')
                ->where('total_items', 0)
                ->delete();

            $heldCart->update([
                'status' => 'active',
                'counter_name' => $counterName,
            ]);

            $resumedCart = $this->loadCartRelations($heldCart);
            return $this->success('Held cart resumed successfully', $resumedCart);
        } catch (\Throwable $e) {
            return $this->failed('Could not resume held cart', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/seller/stores/{storeId}/pos/cart/checkout
     * Convert active POS cart to completed Order & update stock/payment
     */
    public function checkoutCart(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'cart_id' => ['required', 'integer', 'exists:carts,id'],
                'payment_method' => ['required', 'string', 'in:cash,bkash,nagad,bank,baki,card'],
                'paid_amount' => ['nullable', 'numeric', 'min:0'],
                'customer_id' => ['nullable', 'integer', 'exists:users,id'],
                'customer_name' => ['nullable', 'string', 'max:255'],
                'customer_phone' => ['nullable', 'string', 'max:50'],
                'note' => ['nullable', 'string'],
            ]);

            $cart = Cart::with(['items.product', 'items.storeProduct'])
                ->where('id', $validated['cart_id'])
                ->where('shop_id', $storeId)
                ->first();

            if (!$cart || $cart->items->count() === 0) {
                return $this->failed('Cart is empty or not found', null, 400);
            }

            DB::beginTransaction();

            $orderNumber = 'POS-' . strtoupper(Str::random(8));
            $subtotal = $cart->subtotal;
            $total = $subtotal; // In-store pickup = 0 shipping fee

            $order = Order::create([
                'user_id' => $validated['customer_id'] ?? $cart->user_id,
                'order_number' => $orderNumber,
                'status' => 'completed',
                'payment_status' => ($validated['payment_method'] === 'baki') ? 'unpaid' : 'paid',
                'customer_name' => $validated['customer_name'] ?? $cart->customer_name ?? 'Walk-in Customer',
                'customer_phone' => $validated['customer_phone'] ?? $cart->customer_phone,
                'shipping_address' => 'In-Store Counter Sale',
                'subtotal' => $subtotal,
                'shipping_fee' => 0.0,
                'discount' => 0.0,
                'total' => $total,
                'platform' => 'pos',
                'note' => $validated['note'] ?? $cart->hold_reason,
            ]);

            foreach ($cart->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'shop_id' => $storeId,
                    'product_name' => $item->product?->name ?? 'POS Product',
                    'unit_price' => $item->unit_price,
                    'quantity' => $item->qty,
                    'total_price' => $item->line_total,
                ]);

                // Decrement stock in store_products or products
                if ($item->store_product_id) {
                    StoreProduct::where('id', $item->store_product_id)->decrement('stock', $item->qty);
                }
            }

            $cart->update([
                'status' => 'completed',
            ]);

            DB::commit();

            $order->load(['items.product.primaryImage', 'orderItems']);
            return $this->success('POS Order completed successfully', $order, 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Could not checkout POS cart', ['error' => $e->getMessage()], 500);
        }
    }
}

