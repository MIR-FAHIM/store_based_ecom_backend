<?php

namespace App\Http\Controllers;

use App\Models\MediaResource;
use App\Models\MediaResourceOrder;
use App\Models\MediaResourceOrderFieldValue;
use App\Models\MediaResourceOrderFile;
use App\Models\MediaResourceOrderItem;
use App\Models\MediaResourceOrderRevision;
use App\Models\Shops;
use App\Models\Upload;
use App\Service\AmarPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SellerMediaMarketplaceController extends Controller
{
    public function __construct(
        protected AmarPayService $aamarPayService
    ) {}

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

    private function resourceQuery()
    {
        return MediaResource::with(['category', 'previewImage', 'fields'])
            ->where('status', 'active');
    }

    public function categories()
    {
        $categories = \App\Models\MediaResourceCategory::where('status', 'active')
            ->with('children')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success('Media resource categories fetched successfully', $categories);
    }

    public function resources(Request $request)
    {
        $query = $this->resourceQuery();

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->category_id);
        }

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->resource_type);
        }

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->search) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('slug', 'like', $search)
                    ->orWhere('description', 'like', $search);
            });
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->max_price);
        }

        $sort = $request->get('sort', 'default');
        if ($sort === 'newest') {
            $query->latest();
        } elseif ($sort === 'price_low') {
            $query->orderBy('price');
        } elseif ($sort === 'price_high') {
            $query->orderByDesc('price');
        } else {
            $query->orderBy('sort_order')->orderByDesc('id');
        }

        $resources = $query->paginate((int) $request->get('per_page', 24));

        return $this->success('Media resources fetched successfully', $resources);
    }

    public function resourceDetails($id)
    {
        $resource = $this->resourceQuery()
            ->where(function ($query) use ($id) {
                $query->where('id', $id)->orWhere('slug', $id);
            })
            ->first();

        if (!$resource) {
            return $this->failed('Media resource not found', null, 404);
        }

        return $this->success('Media resource fetched successfully', $resource);
    }

    public function createOrder(Request $request, int $storeId)
    {
        try {
            $store = $this->resolveOwnedStore($request, $storeId);
            $user = $request->attributes->get('api_user');

            if (!$store) {
                return $this->failed('Store not found or access denied', null, 404);
            }

            $validated = $request->validate([
                'media_resource_id' => ['required', 'integer', 'exists:media_resources,id'],
                'quantity' => ['nullable', 'integer', 'min:1'],
                'customer_note' => ['nullable', 'string'],
                'field_values' => ['nullable', 'array'],
                'files' => ['nullable', 'array'],
                'files.*.field_id' => ['nullable', 'integer', 'exists:media_resource_fields,id'],
                'files.*.upload_id' => ['required_with:files', 'integer', 'exists:uploads,id'],
                'files.*.file_type' => ['nullable', 'string', 'max:60'],
                'files.*.note' => ['nullable', 'string'],
            ]);

            $resource = MediaResource::with(['fields', 'previewImage', 'category'])
                ->where('status', 'active')
                ->find($validated['media_resource_id']);

            if (!$resource) {
                return $this->failed('Media resource not found or inactive', null, 404);
            }

            $fieldValues = $this->normalizeFieldValues($validated['field_values'] ?? []);
            $files = $validated['files'] ?? [];

            $this->validateRequiredFields($resource, $fieldValues, $files);

            $order = DB::transaction(function () use ($validated, $resource, $store, $user, $fieldValues, $files) {
                $quantity = (int) ($validated['quantity'] ?? 1);
                $price = (float) $resource->price;
                $subtotal = round($price * $quantity, 2);
                $discount = 0;
                $total = max(0, $subtotal - $discount);

                $order = MediaResourceOrder::create([
                    'order_number' => $this->generateOrderNumber(),
                    'store_id' => $store->id,
                    'seller_id' => $user->id,
                    'status' => $total > 0 ? 'pending_payment' : 'paid',
                    'payment_status' => $total > 0 ? 'unpaid' : 'paid',
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'total' => $total,
                    'currency' => $resource->currency ?: 'BDT',
                    'customer_note' => $validated['customer_note'] ?? null,
                    'paid_at' => $total > 0 ? null : now(),
                ]);

                $item = MediaResourceOrderItem::create([
                    'order_id' => $order->id,
                    'media_resource_id' => $resource->id,
                    'price' => $price,
                    'quantity' => $quantity,
                    'total' => $subtotal,
                    'resource_snapshot' => [
                        'id' => $resource->id,
                        'name' => $resource->name,
                        'slug' => $resource->slug,
                        'price' => $resource->price,
                        'width' => $resource->width,
                        'height' => $resource->height,
                        'resource_type' => $resource->resource_type,
                    ],
                ]);

                $this->storeFieldValues($item, $resource, $fieldValues);
                $this->storeFiles($item, $files);

                return $order->load($this->orderRelations());
            });

            return $this->success('Media order created successfully', $order, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function pay(Request $request, int $storeId, int $orderId)
    {
        $store = $this->resolveOwnedStore($request, $storeId);
        $user = $request->attributes->get('api_user');

        if (!$store) {
            return $this->failed('Store not found or access denied', null, 404);
        }

        $order = MediaResourceOrder::where('store_id', $store->id)->find($orderId);
        if (!$order) {
            return $this->failed('Media order not found', null, 404);
        }

        return $this->aamarPayService->initiateMediaResourceOrderPayment($order, $user);
    }

    public function orders(Request $request, int $storeId)
    {
        $store = $this->resolveOwnedStore($request, $storeId);

        if (!$store) {
            return $this->failed('Store not found or access denied', null, 404);
        }

        $query = MediaResourceOrder::with(['items.resource.previewImage', 'items.deliverables.upload'])
            ->where('store_id', $store->id)
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $this->success('Media orders fetched successfully', $query->paginate((int) $request->get('per_page', 20)));
    }

    public function orderDetails(Request $request, int $storeId, int $orderId)
    {
        $store = $this->resolveOwnedStore($request, $storeId);

        if (!$store) {
            return $this->failed('Store not found or access denied', null, 404);
        }

        $order = MediaResourceOrder::with($this->orderRelations())
            ->where('store_id', $store->id)
            ->find($orderId);

        if (!$order) {
            return $this->failed('Media order not found', null, 404);
        }

        return $this->success('Media order fetched successfully', $order);
    }

    public function requestRevision(Request $request, int $storeId, int $orderId)
    {
        try {
            $store = $this->resolveOwnedStore($request, $storeId);
            $user = $request->attributes->get('api_user');

            if (!$store) {
                return $this->failed('Store not found or access denied', null, 404);
            }

            $validated = $request->validate([
                'order_item_id' => ['required', 'integer', 'exists:media_resource_order_items,id'],
                'request_note' => ['required', 'string'],
            ]);

            $order = MediaResourceOrder::where('store_id', $store->id)->find($orderId);
            if (!$order) {
                return $this->failed('Media order not found', null, 404);
            }

            $item = MediaResourceOrderItem::where('order_id', $order->id)->find($validated['order_item_id']);
            if (!$item) {
                return $this->failed('Media order item not found', null, 404);
            }

            $revisionNumber = ((int) $item->revisions()->max('revision_number')) + 1;
            $revision = MediaResourceOrderRevision::create([
                'order_item_id' => $item->id,
                'revision_number' => $revisionNumber,
                'requested_by' => $user->id,
                'request_note' => $validated['request_note'],
                'status' => 'requested',
            ]);

            $order->update(['status' => 'revision_requested']);

            return $this->success('Revision requested successfully', $revision->fresh(), 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        }
    }

    public function approve(Request $request, int $storeId, int $orderId)
    {
        $store = $this->resolveOwnedStore($request, $storeId);

        if (!$store) {
            return $this->failed('Store not found or access denied', null, 404);
        }

        $order = MediaResourceOrder::where('store_id', $store->id)->find($orderId);
        if (!$order) {
            return $this->failed('Media order not found', null, 404);
        }

        $order->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return $this->success('Media order approved successfully', $order->fresh($this->orderRelations()));
    }

    private function validateRequiredFields(MediaResource $resource, array $fieldValues, array $files): void
    {
        $fileFieldIds = collect($files)->pluck('field_id')->filter()->map(fn ($id) => (int) $id)->all();

        foreach ($resource->fields as $field) {
            if (!$field->is_required) {
                continue;
            }

            if (in_array($field->field_type, ['image', 'multiple_images', 'file'], true)) {
                if (!in_array((int) $field->id, $fileFieldIds, true)) {
                    throw ValidationException::withMessages([
                        'files' => ["{$field->label} is required."],
                    ]);
                }
                continue;
            }

            $value = $fieldValues[$field->field_name] ?? $fieldValues[$field->id] ?? null;
            if ($value === null || $value === '') {
                throw ValidationException::withMessages([
                    'field_values.' . $field->field_name => ["{$field->label} is required."],
                ]);
            }
        }
    }

    private function normalizeFieldValues(array $fieldValues): array
    {
        if (array_is_list($fieldValues)) {
            $normalized = [];

            foreach ($fieldValues as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $key = $row['field_name'] ?? $row['field_id'] ?? $row['id'] ?? null;
                if ($key === null) {
                    continue;
                }

                $normalized[$key] = $row['value'] ?? $row['json_value'] ?? null;
            }

            return $normalized;
        }

        return $fieldValues;
    }

    private function storeFieldValues(MediaResourceOrderItem $item, MediaResource $resource, array $fieldValues): void
    {
        foreach ($resource->fields as $field) {
            if (in_array($field->field_type, ['image', 'multiple_images', 'file'], true)) {
                continue;
            }

            $value = $fieldValues[$field->field_name] ?? $fieldValues[$field->id] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            MediaResourceOrderFieldValue::create([
                'order_item_id' => $item->id,
                'field_id' => $field->id,
                'field_name' => $field->field_name,
                'field_type' => $field->field_type,
                'value' => is_scalar($value) ? (string) $value : null,
                'json_value' => is_array($value) ? $value : null,
            ]);
        }
    }

    private function storeFiles(MediaResourceOrderItem $item, array $files): void
    {
        foreach ($files as $file) {
            $upload = Upload::find($file['upload_id'] ?? null);

            MediaResourceOrderFile::create([
                'order_item_id' => $item->id,
                'field_id' => $file['field_id'] ?? null,
                'upload_id' => $upload?->id,
                'file_type' => $file['file_type'] ?? null,
                'file_path' => $upload?->file_name,
                'original_name' => $upload?->file_original_name,
                'note' => $file['note'] ?? null,
            ]);
        }
    }

    private function generateOrderNumber(): string
    {
        do {
            $number = 'MYZ-MEDIA-' . now()->format('ymdHis') . random_int(100, 999);
        } while (MediaResourceOrder::where('order_number', $number)->exists());

        return $number;
    }

    private function orderRelations(): array
    {
        return [
            'store',
            'seller',
            'items.resource.previewImage',
            'items.resource.category',
            'items.fieldValues',
            'items.files.upload',
            'items.deliverables.upload',
            'items.revisions',
            'payments',
        ];
    }
}
