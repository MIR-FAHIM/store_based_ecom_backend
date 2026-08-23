<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Shops;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SellerStoreProductController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    private function isAdminUser($user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        $userType = strtolower((string) ($user->user_type ?? ''));

        return in_array('admin', [$role, $userType], true);
    }

    private function resolveOwnedStore(Request $request, int $storeId): ?Shops
    {
        $user = $request->attributes->get('api_user');

        if (!$user) {
            return null;
        }

        $query = Shops::whereKey($storeId);

        if (!$this->isAdminUser($user)) {
            $query->where('user_id', $user->id);
        }

        return $query->first();
    }

    private function getFinalSalePrice(float $price, ?float $discount, ?string $discountType): float
    {
        if (!$discount || !$discountType) {
            return round($price, 2);
        }

        if ($discountType === 'percent') {
            return round(max(0, $price - ($price * ($discount / 100))), 2);
        }

        return round(max(0, $price - $discount), 2);
    }

    private function activeCategoryIds(int $storeId)
    {
        return StoreCategory::where('store_id', $storeId)
            ->where('is_active', true)
            ->pluck('category_id');
    }

    private function isProductCategoryActiveForStore(int $storeId, Product $product): bool
    {
        $activeCategoryIds = $this->activeCategoryIds($storeId);

        if ($activeCategoryIds->isEmpty()) {
            return true;
        }

        return $activeCategoryIds->contains((int) $product->category_id);
    }

    private function productCatalogQuery()
    {
        $query = Product::query()->with([
            'primaryImage',
            'images.upload',
            'category',
            'brand',
            'productAttributes.attribute',
            'productAttributes.value',
        ])->where('approved', 1);

        if (Schema::hasColumn('products', 'published')) {
            $query->where('published', 1);
        }

        return $query;
    }

    private function formatStoreProduct(StoreProduct $storeProduct): array
    {
        $product = $storeProduct->product;
        $price = (float) ($storeProduct->price ?? $product->unit_price ?? 0);
        $discount = $storeProduct->discount !== null ? (float) $storeProduct->discount : ($product->discount !== null ? (float) $product->discount : null);
        $discountType = $storeProduct->discount_type ?? $product->discount_type ?? null;

        return [
            'store_product_id' => (int) $storeProduct->id,
            'id' => (int) $product->id,
            'product_id' => (int) $product->id,
            'name' => $storeProduct->title_override ?: $product->name,
            'master_name' => $product->name,
            'slug' => $product->slug,
            'description' => $storeProduct->description_override ?: $product->description,
            'master_description' => $product->description,
            'price' => $price,
            'unit_price' => $price,
            'sale_price' => $this->getFinalSalePrice($price, $discount, $discountType),
            'final_sale_price' => $this->getFinalSalePrice($price, $discount, $discountType),
            'discount' => $discount,
            'discount_type' => $discountType,
            'primary_image' => $product->primaryImage,
            'category_id' => $product->category_id ? (int) $product->category_id : null,
            'brand_id' => $product->brand_id ? (int) $product->brand_id : null,
            'stock' => $storeProduct->stock ?? $product->current_stock,
            'current_stock' => $storeProduct->stock ?? $product->current_stock,
            'sku' => $storeProduct->sku,
            'shop_id' => (int) $storeProduct->store_id,
            'store_id' => (int) $storeProduct->store_id,
            'is_active' => (bool) $storeProduct->is_active,
            'featured' => (bool) $storeProduct->is_featured,
            'is_featured' => (bool) $storeProduct->is_featured,
            'todays_deal' => (bool) $storeProduct->todays_deal,
            'category' => $product->category,
            'brand' => $product->brand,
            'product' => $product,
        ];
    }

    public function catalog(Request $request, int $storeId)
    {
        try {
            $store = $this->resolveOwnedStore($request, $storeId);

            if (!$store) {
                return $this->failed('Store not found or access denied', null, 404);
            }

            $query = $this->productCatalogQuery();

            if ($request->filled('category_id')) {
                $query->where('category_id', (int) $request->category_id);
            }

            if ($request->filled('brand_id')) {
                $query->where('brand_id', (int) $request->brand_id);
            }

            if ($request->filled('search')) {
                $tokens = preg_split('/\s+/', trim((string) $request->search));

                $query->where(function ($q) use ($tokens) {
                    foreach ($tokens as $token) {
                        $like = '%' . $token . '%';
                        $q->where(function ($qq) use ($like) {
                            $qq->where('name', 'like', $like)
                                ->orWhere('slug', 'like', $like)
                                ->orWhere('tags', 'like', $like)
                                ->orWhereHas('brand', fn ($brandQuery) => $brandQuery->where('name', 'like', $like))
                                ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', $like));
                        });
                    }
                });
            }

            $perPage = (int) $request->get('per_page', 24);
            $products = $query->latest()->paginate($perPage);
            $storeProductByProductId = StoreProduct::where('store_id', $store->id)
                ->whereIn('product_id', $products->getCollection()->pluck('id'))
                ->get()
                ->keyBy('product_id');

            $products->setCollection($products->getCollection()->map(function ($product) use ($storeProductByProductId) {
                $storeProduct = $storeProductByProductId->get($product->id);
                $product->already_added_to_store = (bool) $storeProduct;
                $product->store_product = $storeProduct;

                return $product;
            }));

            return $this->success('Product catalog fetched successfully', $products);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function addFromCatalog(Request $request, int $storeId)
    {
        try {
            $store = $this->resolveOwnedStore($request, $storeId);

            if (!$store) {
                return $this->failed('Store not found or access denied', null, 404);
            }

            $validated = $request->validate([
                'product_id' => ['required', 'integer', 'exists:products,id'],
            ]);

            $product = $this->productCatalogQuery()->find($validated['product_id']);

            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }

            if (!$this->isProductCategoryActiveForStore($store->id, $product)) {
                return $this->failed('This category is not active for your store.', null, 422);
            }

            $storeProduct = StoreProduct::where('store_id', $store->id)
                ->where('product_id', $product->id)
                ->first();

            if ($storeProduct) {
                $storeProduct->is_active = true;
                $storeProduct->save();
            } else {
                $storeProduct = StoreProduct::create([
                    'store_id' => $store->id,
                    'product_id' => $product->id,
                    'price' => $product->unit_price,
                    'discount' => $product->discount,
                    'discount_type' => $product->discount_type,
                    'stock' => $product->current_stock,
                    'is_active' => true,
                    'is_featured' => (bool) $product->featured,
                    'todays_deal' => (bool) $product->todays_deal,
                ]);
            }

            return $this->success('Product added to store successfully', $this->formatStoreProduct($storeProduct->load('product.primaryImage', 'product.category', 'product.brand')), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request, int $storeId)
    {
        try {
            $store = $this->resolveOwnedStore($request, $storeId);

            if (!$store) {
                return $this->failed('Store not found or access denied', null, 404);
            }

            $query = StoreProduct::with(['product.primaryImage', 'product.images.upload', 'product.category', 'product.brand'])
                ->where('store_id', $store->id);

            if ($request->filled('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->filled('category_id')) {
                $query->whereHas('product', fn ($productQuery) => $productQuery->where('category_id', (int) $request->category_id));
            }

            if ($request->filled('search')) {
                $tokens = preg_split('/\s+/', trim((string) $request->search));

                $query->whereHas('product', function ($productQuery) use ($tokens) {
                    foreach ($tokens as $token) {
                        $like = '%' . $token . '%';
                        $productQuery->where(function ($qq) use ($like) {
                            $qq->where('name', 'like', $like)
                                ->orWhere('slug', 'like', $like);
                        });
                    }
                });
            }

            $perPage = (int) $request->get('per_page', 24);
            $storeProducts = $query->latest()->paginate($perPage);
            $storeProducts->setCollection($storeProducts->getCollection()->map(fn ($storeProduct) => $this->formatStoreProduct($storeProduct)));

            return $this->success('Store products fetched successfully', $storeProducts);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, int $storeId, int $storeProductId)
    {
        try {
            $store = $this->resolveOwnedStore($request, $storeId);

            if (!$store) {
                return $this->failed('Store not found or access denied', null, 404);
            }

            $storeProduct = StoreProduct::where('store_id', $store->id)->find($storeProductId);

            if (!$storeProduct) {
                return $this->failed('Store product not found', null, 404);
            }

            $validated = $request->validate([
                'price' => ['nullable', 'numeric', 'min:0'],
                'discount' => ['nullable', 'numeric', 'min:0'],
                'discount_type' => ['nullable', 'string', 'max:20'],
                'stock' => ['nullable', 'integer', 'min:0'],
                'sku' => ['nullable', 'string', 'max:255'],
                'title_override' => ['nullable', 'string', 'max:255'],
                'description_override' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'is_featured' => ['nullable', 'boolean'],
                'todays_deal' => ['nullable', 'boolean'],
            ]);

            foreach (['is_active', 'is_featured', 'todays_deal'] as $flag) {
                if (array_key_exists($flag, $validated)) {
                    $validated[$flag] = (bool) $validated[$flag];
                }
            }

            $storeProduct->fill($validated);
            $storeProduct->save();

            return $this->success('Store product updated successfully', $this->formatStoreProduct($storeProduct->load('product.primaryImage', 'product.category', 'product.brand')));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function remove(Request $request, int $storeId, int $storeProductId)
    {
        try {
            $store = $this->resolveOwnedStore($request, $storeId);

            if (!$store) {
                return $this->failed('Store not found or access denied', null, 404);
            }

            $storeProduct = StoreProduct::where('store_id', $store->id)->find($storeProductId);

            if (!$storeProduct) {
                return $this->failed('Store product not found', null, 404);
            }

            $storeProduct->is_active = false;
            $storeProduct->save();

            return $this->success('Store product removed successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
