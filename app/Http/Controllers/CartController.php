<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Shops;
use App\Models\StoreProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CartController extends Controller
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

    private function resolveActiveStoreFromSlug(Request $request): ?Shops
    {
        if (!$request->filled('store_slug')) {
            return null;
        }

        return Shops::where('slug', $request->input('store_slug'))
            ->where('status', 'active')
            ->first();
    }

    private function getFinalSalePriceFromValues(float $price, ?float $discount, ?string $discountType): float
    {
        if (!$discount || !$discountType) {
            return round($price, 2);
        }

        if ($discountType === 'percent') {
            return round(max(0, $price - ($price * ($discount / 100))), 2);
        }

        return round(max(0, $price - $discount), 2);
    }

    private function loadCartRelations(Cart $cart): Cart
    {
        return $cart->load([
            'items.product.primaryImage',
            'items.storeProduct',
            'items.shop',
            'items.product.productDiscount',
            'items.productAttribute.attribute',
            'items.productAttribute.value'
        ]);
    }

    private function scopeCartToStore(Cart $cart, Shops $store): Cart
    {
        $this->loadCartRelations($cart);

        $items = $cart->items->filter(fn ($item) => (int) $item->shop_id === (int) $store->id)->values();
        $cart->setRelation('items', $items);
        $cart->total_items = $items->sum(fn ($item) => (int) ($item->qty ?? 0));
        $cart->subtotal = round($items->sum(fn ($item) => (float) ($item->line_total ?? 0)), 2);
        $cart->store_id = (int) $store->id;
        $cart->store_slug = $store->slug;

        return $cart;
    }

    private function itemBelongsToStore(CartItem $item, Shops $store): bool
    {
        return (int) $item->shop_id === (int) $store->id;
    }

    /**
     * Get active cart for a user, or create one
     * GET /carts/active/{userId}?store_slug=
     */
    public function getActiveCart(Request $request, $userId)
    {
        try {
            $store = $this->resolveActiveStoreFromSlug($request);

            if ($request->filled('store_slug') && !$store) {
                return $this->failed('Store not found or inactive', null, 404);
            }

            $cart = Cart::where('user_id', $userId)
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$cart) {
                $cart = Cart::create([
                    'user_id' => $userId,
                    'status' => 'active',
                    'total_items' => 0,
                    'subtotal' => 0,
                ]);
            }

            $cart = $store ? $this->scopeCartToStore($cart, $store) : $this->loadCartRelations($cart);

            return $this->success('Active cart fetched successfully', $cart);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Add item to cart (merge qty if product already exists in cart)
     * POST /carts/items/add
     * Body: user_id, product_id, qty
     */
    public function addItemToCart(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => ['required', 'integer', 'exists:users,id'],
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'store_product_id' => ['nullable', 'integer'],
                'qty' => ['required', 'integer', 'min:1'],
            ]);

            $store = $this->resolveActiveStoreFromSlug($request);

            if ($request->filled('store_slug') && !$store) {
                return $this->failed('Store not found or inactive', null, 404);
            }

            $product = Product::find($validated['product_id']);
            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }

            $storeProduct = null;
            $storeProductId = null;
            $shopId = $product->shop_id ?? null;
            $unitPrice = !is_null($product->unit_price) ? (float) $product->unit_price : null;

            if ($store) {
                $storeProductQuery = StoreProduct::where('store_id', $store->id)
                    ->where('is_active', true);

                if (!empty($validated['store_product_id'])) {
                    $storeProductQuery->whereKey((int) $validated['store_product_id']);
                } else {
                    $storeProductQuery->where('product_id', $product->id);
                }

                $storeProduct = $storeProductQuery->first();

                if (!$storeProduct) {
                    return $this->failed('Product does not belong to this store', null, 404);
                }

                if ((int) $storeProduct->product_id !== (int) $product->id) {
                    return $this->failed('Product does not match the selected store product', null, 422);
                }

                $storeProductId = (int) $storeProduct->id;
                $shopId = $store->id;
                $price = (float) ($storeProduct->price ?? $product->unit_price ?? 0);
                $discount = $storeProduct->discount !== null ? (float) $storeProduct->discount : ($product->discount !== null ? (float) $product->discount : null);
                $discountType = $storeProduct->discount_type ?? $product->discount_type ?? null;
                $unitPrice = $this->getFinalSalePriceFromValues($price, $discount, $discountType);
            } elseif (!is_null($unitPrice) && !is_null($product->discount) && $product->discount > 0) {
                if ($product->discount_type === 'percent') {
                    $unitPrice = $unitPrice - ($unitPrice * ($product->discount / 100));
                } elseif ($product->discount_type === 'amount') {
                    $unitPrice = $unitPrice - $product->discount;
                }
                if ($unitPrice < 0) $unitPrice = 0;
            }

            DB::beginTransaction();

            $cart = Cart::where('user_id', $validated['user_id'])
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$cart) {
                $cart = Cart::create([
                    'user_id' => $validated['user_id'],
                    'status' => 'active',
                    'total_items' => 0,
                    'subtotal' => 0,
                ]);
            }

            $itemQuery = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->where('attribute_id', $request->input('attribute_id'));

            if ($store) {
                $itemQuery->where('shop_id', $store->id);

                if ($storeProductId && Schema::hasColumn('cart_items', 'store_product_id')) {
                    $itemQuery->where('store_product_id', $storeProductId);
                }
            }

            $item = $itemQuery->first();

            if ($item) {
                $newQty = ((int) $item->qty) + (int) $validated['qty'];
                $item->qty = $newQty;
                if ($storeProductId && Schema::hasColumn('cart_items', 'store_product_id')) {
                    $item->store_product_id = $storeProductId;
                }
                $item->unit_price = $unitPrice;
                $item->line_total = ($unitPrice !== null) ? round($newQty * $unitPrice, 2) : null;
                $item->status = $item->status ?? 'active';
                $item->save();
            } else {
                $payload = [
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'attribute_id' => $request->input('attribute_id'),
                    'shop_id' => $shopId,
                    'qty' => (int) $validated['qty'],
                    'unit_price' => $unitPrice,
                    'line_total' => ($unitPrice !== null) ? round(((int) $validated['qty']) * $unitPrice, 2) : null,
                    'status' => 'active',
                ];

                if ($storeProductId && Schema::hasColumn('cart_items', 'store_product_id')) {
                    $payload['store_product_id'] = $storeProductId;
                }

                $item = CartItem::create($payload);
            }

            $this->recalculateCart($cart->id);

            DB::commit();

            $cart = Cart::find($cart->id);
            $cart = $store ? $this->scopeCartToStore($cart, $store) : $cart->load(['items.product', 'items.shop']);

            return $this->success('Item added to cart successfully', [
                'cart' => $cart,
                'item' => $item
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update cart item qty
     * PUT /carts/items/update/{itemId}?qty=&store_slug=
     */
    public function updateCartItemQty(Request $request, $itemId)
    {
        try {
            $validated = $request->validate([
                'qty' => ['required', 'integer', 'min:0'],
            ]);

            $store = $this->resolveActiveStoreFromSlug($request);

            if ($request->filled('store_slug') && !$store) {
                return $this->failed('Store not found or inactive', null, 404);
            }

            $item = CartItem::find($itemId);
            if (!$item) {
                return $this->failed('Cart item not found', null, 404);
            }

            if ($store && !$this->itemBelongsToStore($item, $store)) {
                return $this->failed('Cart item does not belong to this store', null, 404);
            }

            DB::beginTransaction();

            if ((int) $validated['qty'] === 0) {
                $cartId = $item->cart_id;
                $item->delete();
                $this->recalculateCart($cartId);

                DB::commit();

                $cart = Cart::find($cartId);
                $cart = $store ? $this->scopeCartToStore($cart, $store) : $cart->load(['items.product', 'items.shop']);
                return $this->success('Item removed (qty=0) and cart updated', $cart);
            }

            $item->qty = (int) $validated['qty'];
            $item->line_total = ($item->unit_price !== null)
                ? round(((int) $validated['qty']) * (float) $item->unit_price, 2)
                : null;
            $item->save();

            $this->recalculateCart($item->cart_id);

            DB::commit();

            $cart = Cart::find($item->cart_id);
            $cart = $store ? $this->scopeCartToStore($cart, $store) : $cart->load(['items.product', 'items.shop']);
            return $this->success('Cart item updated successfully', $cart);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove cart item
     * DELETE /carts/items/delete/{itemId}
     */
    public function removeCartItem($itemId)
    {
        try {
            $item = CartItem::find($itemId);
            if (!$item) {
                return $this->failed('Cart item not found', null, 404);
            }

            DB::beginTransaction();

            $cartId = $item->cart_id;
            $item->delete();

            $this->recalculateCart($cartId);

            DB::commit();

            $cart = Cart::with(['items.product', 'items.shop'])->find($cartId);
            return $this->success('Cart item removed successfully', $cart);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear cart (delete all items)
     * DELETE /carts/clear/{userId}
     */
    public function clearCart($userId)
    {
        try {
            $cart = Cart::where('user_id', $userId)
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$cart) {
                return $this->failed('Active cart not found', null, 404);
            }

            DB::beginTransaction();

            CartItem::where('cart_id', $cart->id)->delete();
            $cart->total_items = 0;
            $cart->subtotal = 0;
            $cart->save();

            DB::commit();

            $cart->load(['items.product', 'items.shop']);

            return $this->success('Cart cleared successfully', $cart);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Internal helper: recalculate cart totals from cart_items
     */
    private function recalculateCart($cartId)
    {
        $items = CartItem::where('cart_id', $cartId)->get();

        $totalItems = 0;
        $subtotal = 0;

        foreach ($items as $item) {
            $qty = (int) ($item->qty ?? 0);
            $line = (float) ($item->line_total ?? 0);

            $totalItems += $qty;
            $subtotal += $line;
        }

        $cart = Cart::find($cartId);
        if ($cart) {
            $cart->total_items = $totalItems;
            $cart->subtotal = round($subtotal, 2);
            $cart->save();
        }
    }
}
