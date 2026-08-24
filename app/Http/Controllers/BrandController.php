<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Shops;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandController extends Controller
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

        return Shops::where('slug', $request->query('store_slug'))
            ->where('status', 'active')
            ->first();
    }

    /**
     * POST /brands/create
     */
    public function createBrand(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => ['nullable', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255', 'unique:brands,slug'],
                'logo' => ['nullable', 'integer', 'exists:uploads,id'],
                'status' => ['nullable', 'string', 'max:50'],
            ]);

            $brand = Brand::create($validated);

            return $this->success('Brand created successfully', $brand->load('logo'), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /brands/list?status=&per_page=&all=1&store_slug=
     */
    public function listBrands(Request $request)
    {
        try {
            if ($request->filled('store_slug')) {
                $store = $this->resolveActiveStoreFromSlug($request);

                if (!$store) {
                    return $this->failed('Store not found or inactive', null, 404);
                }

                $storeProductQuery = StoreProduct::where('store_id', $store->id)
                    ->where('is_active', true)
                    ->whereHas('product', function ($productQuery) {
                        $productQuery->where('approved', 1)
                            ->whereNotNull('brand_id')
                            ->whereHas('brand', fn ($brandQuery) => $brandQuery->where('status', 'active'));
                    });

                $activeCategoryIds = StoreCategory::where('store_id', $store->id)
                    ->where('is_active', true)
                    ->pluck('category_id');

                if ($activeCategoryIds->isNotEmpty()) {
                    $storeProductQuery->whereHas('product', fn ($productQuery) => $productQuery->whereIn('category_id', $activeCategoryIds));
                }

                $brandIds = $storeProductQuery
                    ->with('product:id,brand_id')
                    ->get()
                    ->pluck('product.brand_id')
                    ->filter()
                    ->unique()
                    ->values();

                $query = Brand::with('logo')->whereIn('id', $brandIds);

                if ($request->filled('status')) {
                    $query->where('status', $request->status);
                } else {
                    $query->where('status', 'active');
                }

                $query->orderBy('name');

                if ($request->filled('all') && (int) $request->get('all') === 1) {
                    return $this->success('Store brands fetched successfully', $query->get());
                }

                return $this->success('Store brands fetched successfully', $query->paginate((int) $request->get('per_page', 20)));
            }

            $query = Brand::query();

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $query->with(['logo'])->latest();

            // /brands/list?all=1 (no pagination)
            if ($request->filled('all') && (int) $request->get('all') === 1) {
                $brands = $query->get();
                return $this->success('Brands fetched successfully', $brands);
            }

            $perPage = (int) $request->get('per_page', 20);
            $brands = $query->paginate($perPage);

            return $this->success('Brands fetched successfully', $brands);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /brands/details/{id}
     */
    public function getBrandDetails($id)
    {
        try {
            $brand = Brand::find($id);

            if (!$brand) {
                return $this->failed('Brand not found', null, 404);
            }

            $brand->load('logo');

            return $this->success('Brand fetched successfully', $brand);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /brands/update/{id}
     */
    public function updateBrand(Request $request, $id)
    {
        try {
            $brand = Brand::find($id);

            if (!$brand) {
                return $this->failed('Brand not found', null, 404);
            }

            $validated = $request->validate([
                'name' => ['nullable', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255', Rule::unique('brands', 'slug')->ignore($brand->id)],
                'logo' => ['nullable', 'integer', 'exists:uploads,id'],
                'status' => ['nullable', 'string', 'max:50'],
            ]);

            $brand->fill($validated);
            $brand->save();

            return $this->success('Brand updated successfully', $brand->load('logo'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /brands/delete/{id}
     */
    public function deleteBrand($id)
    {
        try {
            $brand = Brand::find($id);

            if (!$brand) {
                return $this->failed('Brand not found', null, 404);
            }

            $brand->delete();

            return $this->success('Brand deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
