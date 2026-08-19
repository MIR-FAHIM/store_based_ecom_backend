<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Shops;
use App\Models\StoreCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerStoreCategoryController extends Controller
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
            'status' => 'failed',
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

    private function marketplaceTree($categories, $activeCategoryIds, int $parentId = 0)
    {
        return $categories
            ->where('parent_id', $parentId)
            ->values()
            ->map(function ($category) use ($categories, $activeCategoryIds) {
                return [
                    'id' => (int) $category->id,
                    'parent_id' => (int) ($category->parent_id ?? 0),
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'icon' => $category->icon,
                    'cover_image' => $category->cover_image,
                    'banner' => $category->banner,
                    'is_active_for_store' => $activeCategoryIds->contains((int) $category->id),
                    'children' => $this->marketplaceTree($categories, $activeCategoryIds, (int) $category->id),
                ];
            });
    }

    public function marketplace(Request $request, int $storeId)
    {
        try {
            $store = $this->resolveOwnedStore($request, $storeId);

            if (!$store) {
                return $this->failed('Store not found or access denied', null, 404);
            }

            $activeCategoryIds = StoreCategory::where('store_id', $store->id)
                ->where('is_active', true)
                ->pluck('category_id')
                ->map(fn ($categoryId) => (int) $categoryId);

            $categories = Category::where('is_active', 1)
                ->orderByRaw('COALESCE(order_level, 999999) asc')
                ->latest()
                ->get();

            return $this->success('Marketplace categories fetched successfully', $this->marketplaceTree($categories, $activeCategoryIds));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function toggle(Request $request, int $storeId)
    {
        try {
            $store = $this->resolveOwnedStore($request, $storeId);

            if (!$store) {
                return $this->failed('Store not found or access denied', null, 404);
            }

            $validated = $request->validate([
                'category_id' => ['required', 'integer', 'exists:categories,id'],
                'is_active' => ['required', 'boolean'],
            ]);

            $storeCategory = StoreCategory::updateOrCreate(
                [
                    'store_id' => $store->id,
                    'category_id' => $validated['category_id'],
                ],
                [
                    'is_active' => (bool) $validated['is_active'],
                ]
            );

            return $this->success(
                $storeCategory->is_active ? 'Category activated' : 'Category deactivated',
                [
                    'store_id' => (int) $storeCategory->store_id,
                    'category_id' => (int) $storeCategory->category_id,
                    'is_active' => (bool) $storeCategory->is_active,
                ]
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function sync(Request $request, int $storeId)
    {
        try {
            $store = $this->resolveOwnedStore($request, $storeId);

            if (!$store) {
                return $this->failed('Store not found or access denied', null, 404);
            }

            $validated = $request->validate([
                'category_ids' => ['required', 'array'],
                'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
            ]);

            $categoryIds = collect($validated['category_ids'])
                ->map(fn ($categoryId) => (int) $categoryId)
                ->unique()
                ->values();

            DB::transaction(function () use ($store, $categoryIds) {
                StoreCategory::where('store_id', $store->id)->update(['is_active' => false]);

                foreach ($categoryIds as $categoryId) {
                    StoreCategory::updateOrCreate(
                        [
                            'store_id' => $store->id,
                            'category_id' => $categoryId,
                        ],
                        [
                            'is_active' => true,
                        ]
                    );
                }
            });

            $activeCategories = StoreCategory::where('store_id', $store->id)
                ->where('is_active', true)
                ->orderBy('category_id')
                ->get(['store_id', 'category_id', 'is_active']);

            return $this->success('Store categories synced successfully', $activeCategories);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
