<?php

namespace App\Http\Controllers;

use App\Models\MediaResource;
use App\Models\MediaResourceCategory;
use App\Models\MediaResourceField;
use App\Models\MediaResourceOrder;
use App\Models\MediaResourceOrderDeliverable;
use App\Models\MediaResourceOrderItem;
use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminMediaMarketplaceController extends Controller
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

    private function ensureAdmin(Request $request)
    {
        $user = $request->attributes->get('api_user');

        if (!$this->isAdminUser($user)) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Admin access required',
                'errors' => null,
            ], 403));
        }

        return $user;
    }

    public function categories(Request $request)
    {
        $this->ensureAdmin($request);

        $query = MediaResourceCategory::with('children')->orderBy('sort_order')->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $this->success('Media resource categories fetched successfully', $query->get());
    }

    public function createCategory(Request $request)
    {
        try {
            $this->ensureAdmin($request);
            $validated = $this->categoryValidation($request);
            $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);

            $category = MediaResourceCategory::create($validated);

            return $this->success('Media resource category created successfully', $category, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        }
    }

    public function updateCategory(Request $request, int $id)
    {
        try {
            $this->ensureAdmin($request);
            $category = MediaResourceCategory::find($id);
            if (!$category) {
                return $this->failed('Media resource category not found', null, 404);
            }

            $validated = $this->categoryValidation($request, $category->id);
            if (!array_key_exists('slug', $validated) && array_key_exists('name', $validated)) {
                $validated['slug'] = Str::slug($validated['name']);
            }

            $category->update($validated);

            return $this->success('Media resource category updated successfully', $category->fresh('children'));
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        }
    }

    public function deleteCategory(Request $request, int $id)
    {
        $this->ensureAdmin($request);
        $category = MediaResourceCategory::find($id);
        if (!$category) {
            return $this->failed('Media resource category not found', null, 404);
        }

        $category->delete();

        return $this->success('Media resource category deleted successfully');
    }

    public function resources(Request $request)
    {
        $this->ensureAdmin($request);

        $query = MediaResource::with(['category', 'previewImage', 'fields'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->category_id);
        }

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->resource_type);
        }

        return $this->success('Media resources fetched successfully', $query->paginate((int) $request->get('per_page', 20)));
    }

    public function resourceDetails(Request $request, int $id)
    {
        $this->ensureAdmin($request);
        $resource = MediaResource::with(['category', 'previewImage', 'fields'])->find($id);

        if (!$resource) {
            return $this->failed('Media resource not found', null, 404);
        }

        return $this->success('Media resource fetched successfully', $resource);
    }

    public function createResource(Request $request)
    {
        try {
            $user = $this->ensureAdmin($request);
            $validated = $this->resourceValidation($request);
            $fields = $validated['fields'] ?? [];
            unset($validated['fields']);

            $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
            $validated['created_by'] = $user->id;
            $validated['updated_by'] = $user->id;

            $resource = DB::transaction(function () use ($validated, $fields) {
                $resource = MediaResource::create($validated);
                $this->syncFields($resource, $fields);
                return $resource->load(['category', 'previewImage', 'fields']);
            });

            return $this->success('Media resource created successfully', $resource, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        }
    }

    public function updateResource(Request $request, int $id)
    {
        try {
            $user = $this->ensureAdmin($request);
            $resource = MediaResource::find($id);
            if (!$resource) {
                return $this->failed('Media resource not found', null, 404);
            }

            $validated = $this->resourceValidation($request, $resource->id);
            $fields = $validated['fields'] ?? null;
            unset($validated['fields']);

            if (!array_key_exists('slug', $validated) && array_key_exists('name', $validated)) {
                $validated['slug'] = Str::slug($validated['name']);
            }
            $validated['updated_by'] = $user->id;

            $resource = DB::transaction(function () use ($resource, $validated, $fields) {
                $resource->update($validated);
                if (is_array($fields)) {
                    $this->syncFields($resource, $fields);
                }
                return $resource->fresh(['category', 'previewImage', 'fields']);
            });

            return $this->success('Media resource updated successfully', $resource);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        }
    }

    public function deleteResource(Request $request, int $id)
    {
        $this->ensureAdmin($request);
        $resource = MediaResource::find($id);
        if (!$resource) {
            return $this->failed('Media resource not found', null, 404);
        }

        $resource->delete();

        return $this->success('Media resource deleted successfully');
    }

    public function orders(Request $request)
    {
        $this->ensureAdmin($request);

        $query = MediaResourceOrder::with(['store', 'seller', 'items.resource.previewImage'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->store_id);
        }

        return $this->success('Media orders fetched successfully', $query->paginate((int) $request->get('per_page', 20)));
    }

    public function orderDetails(Request $request, int $id)
    {
        $this->ensureAdmin($request);

        $order = MediaResourceOrder::with($this->orderRelations())->find($id);
        if (!$order) {
            return $this->failed('Media order not found', null, 404);
        }

        return $this->success('Media order fetched successfully', $order);
    }

    public function updateOrderStatus(Request $request, int $id)
    {
        try {
            $this->ensureAdmin($request);
            $order = MediaResourceOrder::find($id);
            if (!$order) {
                return $this->failed('Media order not found', null, 404);
            }

            $validated = $request->validate([
                'status' => ['required', 'string', Rule::in([
                    'pending_payment',
                    'paid',
                    'pending_design',
                    'in_progress',
                    'draft_delivered',
                    'revision_requested',
                    'final_delivered',
                    'completed',
                    'cancelled',
                    'refunded',
                ])],
                'admin_note' => ['nullable', 'string'],
                'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            ]);

            $payload = $validated;
            if ($validated['status'] === 'completed') {
                $payload['completed_at'] = now();
            }

            $order->update($payload);

            return $this->success('Media order status updated successfully', $order->fresh($this->orderRelations()));
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        }
    }

    public function addDeliverable(Request $request, int $id)
    {
        try {
            $user = $this->ensureAdmin($request);
            $order = MediaResourceOrder::find($id);
            if (!$order) {
                return $this->failed('Media order not found', null, 404);
            }

            $validated = $request->validate([
                'order_item_id' => ['required', 'integer', 'exists:media_resource_order_items,id'],
                'upload_id' => ['required', 'integer', 'exists:uploads,id'],
                'file_type' => ['required', 'string', Rule::in(['draft', 'final', 'source', 'other'])],
                'note' => ['nullable', 'string'],
            ]);

            $item = MediaResourceOrderItem::where('order_id', $order->id)->find($validated['order_item_id']);
            if (!$item) {
                return $this->failed('Media order item not found', null, 404);
            }

            $upload = Upload::find($validated['upload_id']);
            $version = ((int) $item->deliverables()
                ->where('file_type', $validated['file_type'])
                ->max('version')) + 1;

            $deliverable = MediaResourceOrderDeliverable::create([
                'order_item_id' => $item->id,
                'upload_id' => $upload->id,
                'file_type' => $validated['file_type'],
                'file_path' => $upload->file_name,
                'version' => $version,
                'note' => $validated['note'] ?? null,
                'uploaded_by' => $user->id,
            ]);

            $order->update([
                'status' => $validated['file_type'] === 'final' ? 'final_delivered' : 'draft_delivered',
            ]);

            return $this->success('Deliverable uploaded successfully', $deliverable->fresh('upload'), 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        }
    }

    private function categoryValidation(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:media_resource_categories,id'],
            'name' => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('media_resource_categories', 'slug')->ignore($ignoreId)],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:30'],
            'sort_order' => ['nullable', 'integer'],
        ]);
    }

    private function resourceValidation(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:media_resource_categories,id'],
            'name' => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('media_resources', 'slug')->ignore($ignoreId)],
            'description' => ['nullable', 'string'],
            'preview_image_id' => ['nullable', 'integer', 'exists:uploads,id'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'resource_type' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'string', 'max:30'],
            'sort_order' => ['nullable', 'integer'],
            'instructions' => ['nullable', 'string'],
            'fields' => ['nullable', 'array'],
            'fields.*.id' => ['nullable', 'integer', 'exists:media_resource_fields,id'],
            'fields.*.field_name' => ['required_with:fields', 'string', 'max:255'],
            'fields.*.field_type' => ['required_with:fields', 'string', Rule::in(['text', 'textarea', 'image', 'multiple_images', 'file', 'number', 'color', 'url'])],
            'fields.*.label' => ['required_with:fields', 'string', 'max:255'],
            'fields.*.is_required' => ['nullable', 'boolean'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.help_text' => ['nullable', 'string'],
            'fields.*.sort_order' => ['nullable', 'integer'],
        ]);
    }

    private function syncFields(MediaResource $resource, array $fields): void
    {
        $keptIds = [];

        foreach ($fields as $field) {
            $payload = [
                'field_name' => $field['field_name'],
                'field_type' => $field['field_type'],
                'label' => $field['label'],
                'is_required' => (bool) ($field['is_required'] ?? false),
                'options' => $field['options'] ?? null,
                'placeholder' => $field['placeholder'] ?? null,
                'help_text' => $field['help_text'] ?? null,
                'sort_order' => (int) ($field['sort_order'] ?? 0),
            ];

            if (!empty($field['id'])) {
                $model = MediaResourceField::where('media_resource_id', $resource->id)->find($field['id']);
                if ($model) {
                    $model->update($payload);
                    $keptIds[] = $model->id;
                    continue;
                }
            }

            $created = $resource->fields()->create($payload);
            $keptIds[] = $created->id;
        }

        $resource->fields()
            ->when(!empty($keptIds), fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();
    }

    private function orderRelations(): array
    {
        return [
            'store',
            'seller',
            'assignedDesigner',
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
