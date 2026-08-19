<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shops;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
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

    private function makeUniqueCategorySlug(string $value): string
    {
        $baseSlug = Str::slug($value) ?: 'category';
        $slug = $baseSlug;
        $counter = 1;

        while (Category::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function resolveActiveStoreIdFromSlug(Request $request): ?int
    {
        if (!$request->filled('store_slug')) {
            return null;
        }

        $store = Shops::where('slug', $request->query('store_slug'))
            ->where('status', 'active')
            ->first();

        return $store ? (int) $store->id : 0;
    }

    private function visibleCategoryIdsForStore(int $storeId)
    {
        $productQuery = Product::query()
            ->fromActiveShop()
            ->where('shop_id', $storeId)
            ->where('approved', 1);

        if (Schema::hasColumn('products', 'published')) {
            $productQuery->where('published', 1);
        }

        $categoryIds = $productQuery->pluck('category_id')->filter()->unique()->values();
        $parentIds = Category::whereIn('id', $categoryIds)
            ->pluck('parent_id')
            ->filter(fn ($parentId) => (int) $parentId > 0);

        return $categoryIds->merge($parentIds)->unique()->values();
    }

    /**
     * POST /categories/create
     */
    public function createCategory(Request $request)
    {
        try {
            $request->merge([
                'parent_id' => $request->get('parent_id', 0),
                'order_level' => $request->get('order_level', $request->get('sort_order')),
                'is_active' => $request->get('is_active', $request->get('status')),
                'cover_image' => $request->get('cover_image', $request->get('image')),
            ]);

            $validated = $request->validate([
                'parent_id' => ['nullable', 'integer', function ($attribute, $value, $fail) {
                    if ($value === null || (int) $value === 0) {
                        return;
                    }

                    if (!Category::whereKey($value)->exists()) {
                        $fail('The selected parent category is invalid.');
                    }
                }],
                'name' => ['required', 'string', 'max:50'],
                'order_level' => ['nullable', 'integer'],
                'is_active' => ['nullable', 'integer', 'between:0,1'],
                'commision_rate' => ['nullable', 'numeric', 'between:0,999999.99'],
                'banner' => ['nullable', 'integer', 'exists:uploads,id'],
                'icon' => ['nullable', 'integer', 'exists:uploads,id'],
                'cover_image' => ['nullable', 'integer', 'exists:uploads,id'],
                'featured' => ['nullable', 'integer', 'between:0,1'],
                'top' => ['nullable', 'integer', 'between:0,1'],
                'digital' => ['nullable', 'integer', 'between:0,1'],
                'slug' => ['nullable', 'string', 'max:255'],
                'meta_title' => ['nullable', 'string', 'max:255'],
                'meta_description' => ['nullable', 'string'],
            ]);

            $parentCategory = null;
            $validated['parent_id'] = (int) ($validated['parent_id'] ?? 0);
            if ($validated['parent_id'] > 0) {
                $parentCategory = Category::find((int) $validated['parent_id']);
            }

            $validated['level'] = $parentCategory ? ((int) $parentCategory->level + 1) : 0;
            $validated['slug'] = $this->makeUniqueCategorySlug($validated['slug'] ?? $validated['name']);

            $category = Category::create($validated);

            return $this->success('Category created successfully', $category->load(['parent', 'banner', 'iconImage', 'coverImage']), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /categories/list?parent_id=&status=&per_page=
     */

    public function getCategoryInfo(Request $request)
    {
        try {
            $categoryId = $request->get('category_id');
            if (!$categoryId) {
                return $this->failed('category_id is required', null, 422);
            }

            $category = Category::with('banner')->find($categoryId);

            if (!$category) {
                return $this->failed('Category not found', null, 404);
            }

            return $this->success('Category info fetched successfully', $category);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
    public function listCategories(Request $request)
    {
        try {
            // Only show top-level featured categories (no children)
            $query = Category::query()
            ->with('banner')
                ->where('parent_id', 0)
                ->where('is_active', 1)
                ->where('featured', 1);

            $storeId = $this->resolveActiveStoreIdFromSlug($request);
            if ($storeId !== null) {
                $query->whereIn('id', $this->visibleCategoryIdsForStore($storeId));
            }

            $perPage = (int) $request->get('per_page', 20);

            // If you want all (no pagination): /categories/list?all=1
            if ($request->filled('all') && (int) $request->get('all') === 1) {
                $categories = $query->orderByRaw('COALESCE(order_level, 999999) asc')
                    ->latest()
                    ->get();

                return $this->success('Categories fetched successfully', $categories);
            }

            $categories = $query->orderByRaw('COALESCE(order_level, 999999) asc')
                ->latest()
                ->paginate($perPage);

            return $this->success('Categories fetched successfully', $categories);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /categories/details/{id}
     */
    public function getCategoryDetails($id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->failed('Category not found', null, 404);
            }

            return $this->success('Category fetched successfully', $category);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /categories/update/{id}
     */
    public function updateCategory(Request $request, $id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->failed('Category not found', null, 404);
            }

            $request->merge([
                'order_level' => $request->get('order_level', $request->get('sort_order')),
                'is_active' => $request->get('is_active', $request->get('status')),
                'cover_image' => $request->get('cover_image', $request->get('image')),
            ]);

            $validated = $request->validate([
                'parent_id' => ['nullable', 'integer', function ($attribute, $value, $fail) use ($id) {
                    if ($value === null || (int) $value === 0) {
                        return;
                    }

                    if ((int) $value === (int) $id) {
                        $fail('The category cannot be its own parent.');
                        return;
                    }

                    if (!Category::whereKey($value)->exists()) {
                        $fail('The selected parent category is invalid.');
                    }
                }],
                'name' => ['nullable', 'string', 'max:50'],
                'order_level' => ['nullable', 'integer'],
                'is_active' => ['nullable', 'integer', 'between:0,1'],
                'commision_rate' => ['nullable', 'numeric', 'between:0,999999.99'],
                'banner' => ['nullable', 'integer', 'exists:uploads,id'],
                'icon' => ['nullable', 'integer', 'exists:uploads,id'],
                'cover_image' => ['nullable', 'integer', 'exists:uploads,id'],
                'featured' => ['nullable', 'integer', 'between:0,1'],
                'top' => ['nullable', 'integer', 'between:0,1'],
                'digital' => ['nullable', 'integer', 'between:0,1'],
                'slug' => ['nullable', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($category->id)],
                'meta_title' => ['nullable', 'string', 'max:255'],
                'meta_description' => ['nullable', 'string'],
            ]);

            if (array_key_exists('parent_id', $validated)) {
                $parentCategory = null;
                if (!empty($validated['parent_id']) && (int) $validated['parent_id'] > 0) {
                    $parentCategory = Category::find((int) $validated['parent_id']);
                }

                $validated['level'] = $parentCategory ? ((int) $parentCategory->level + 1) : 0;
            }

            $category->fill($validated);
            $category->save();

            return $this->success('Category updated successfully', $category);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /categories/delete/{id}
     */
    public function deleteCategory($id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->failed('Category not found', null, 404);
            }

            // Prevent deleting category that still has children
            $hasChildren = Category::where('parent_id', $category->id)->exists();
            if ($hasChildren) {
                return $this->failed('Cannot delete: category has sub-categories. Delete sub-categories first.', null, 409);
            }

            $category->delete();

            return $this->success('Category deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /categories/children/{id}
     */
    public function getCategoryChildren($id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->failed('Category not found', null, 404);
            }

            $children = Category::where('parent_id', $id)->where('is_active', 1)
                 ->with('banner', 'coverImage')
                ->orderByRaw('COALESCE(order_level, 999999) asc')
                ->latest()
                ->get();

            return $this->success('Sub-categories fetched successfully', $children);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /categories/with-children
     * List categories grouped by parent_id with all children
     */
public function getCategoryWithAllChildren()
{
    try {
        $categories = Category::with('banner')
            ->where('is_active', 1)
            ->orderByRaw('COALESCE(order_level, 999999) ASC')
            ->get();

        // Group by parent_id, casting to int so null → 0 and "5" === 5
        $byParent = $categories->groupBy(function ($category) {
            return (int) ($category->parent_id ?? 0);
        });

        $buildTree = function (int $parentId) use (&$buildTree, $byParent) {
            $children = $byParent->get($parentId, collect());

            return $children->map(function ($category) use (&$buildTree) {
                $category->setRelation('children', $buildTree((int) $category->id));
                return $category;
            });
        };

        $tree = $buildTree(0);

        return $this->success('Categories with children fetched successfully', $tree);
    } catch (\Throwable $e) {
        return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
    }
}
}
