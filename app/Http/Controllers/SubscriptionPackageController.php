<?php

namespace App\Http\Controllers;

use App\Models\Shops;
use App\Models\StoreSubscription;
use App\Models\SubscriptionPackage;
use App\Service\AmarPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubscriptionPackageController extends Controller
{
    public function __construct(
        private AmarPayService $amarPayService
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

    private function makeUniquePackageSlug(string $value, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($value) ?: 'package';
        $slug = $baseSlug;
        $counter = 1;

        while (
            SubscriptionPackage::where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function validatePackage(Request $request, ?SubscriptionPackage $package = null): array
    {
        $validated = $request->validate([
            'name' => [$package ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('subscription_packages', 'slug')->ignore($package?->id)],
            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['nullable', 'string', 'max:10'],
            'billing_cycle' => ['nullable', Rule::in(['monthly', 'yearly', 'lifetime'])],
            'trial_days' => ['nullable', 'integer', 'min:0'],
            'max_products' => ['nullable', 'integer', 'min:0'],
            'max_orders_per_month' => ['nullable', 'integer', 'min:0'],
            'max_staff' => ['nullable', 'integer', 'min:0'],
            'max_branches' => ['nullable', 'integer', 'min:0'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_featured' => ['nullable', 'boolean'],
            'is_popular' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort_order' => ['nullable', 'integer'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        if (!$package && empty($validated['slug'])) {
            $validated['slug'] = $this->makeUniquePackageSlug($validated['name'] ?? 'package');
        } elseif (array_key_exists('slug', $validated)) {
            $validated['slug'] = $this->makeUniquePackageSlug($validated['slug'] ?: ($validated['name'] ?? $package?->name ?? 'package'), $package?->id);
        }

        return $validated;
    }

    public function index(Request $request)
    {
        try {
            $user = $request->attributes->get('api_user');
            $isAdmin = $this->isAdminUser($user);

            $query = SubscriptionPackage::query();

            if ($request->filled('billing_cycle')) {
                $query->where('billing_cycle', $request->query('billing_cycle'));
            }

            if (!$isAdmin) {
                $query->where('status', 'active');
            } elseif ($request->filled('status')) {
                $query->where('status', $request->query('status'));
            }

            $query->orderBy('sort_order')->latest();

            if ($request->filled('all') && (int) $request->query('all') === 1) {
                return $this->success('Subscription packages fetched successfully', $query->get());
            }

            $perPage = (int) $request->query('per_page', 20);

            return $this->success('Subscription packages fetched successfully', $query->paginate($perPage));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function details(Request $request, $id)
    {
        try {
            $package = SubscriptionPackage::find($id);

            if (!$package) {
                return $this->failed('Subscription package not found', null, 404);
            }

            if ($package->status !== 'active' && !$this->isAdminUser($request->attributes->get('api_user'))) {
                return $this->failed('Subscription package not found', null, 404);
            }

            return $this->success('Subscription package fetched successfully', $package);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function detailsBySlug(Request $request, $slug)
    {
        try {
            $package = SubscriptionPackage::where('slug', $slug)->first();

            if (!$package) {
                return $this->failed('Subscription package not found', null, 404);
            }

            if ($package->status !== 'active' && !$this->isAdminUser($request->attributes->get('api_user'))) {
                return $this->failed('Subscription package not found', null, 404);
            }

            return $this->success('Subscription package fetched successfully', $package);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function create(Request $request)
    {
        try {
            if (!$this->isAdminUser($request->attributes->get('api_user'))) {
                return $this->failed('Only admin can create subscription packages', null, 403);
            }

            $package = SubscriptionPackage::create($this->validatePackage($request));

            return $this->success('Subscription package created successfully', $package, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            if (!$this->isAdminUser($request->attributes->get('api_user'))) {
                return $this->failed('Only admin can update subscription packages', null, 403);
            }

            $package = SubscriptionPackage::find($id);

            if (!$package) {
                return $this->failed('Subscription package not found', null, 404);
            }

            $package->fill($this->validatePackage($request, $package));
            $package->save();

            return $this->success('Subscription package updated successfully', $package);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function delete(Request $request, $id)
    {
        try {
            if (!$this->isAdminUser($request->attributes->get('api_user'))) {
                return $this->failed('Only admin can delete subscription packages', null, 403);
            }

            $package = SubscriptionPackage::find($id);

            if (!$package) {
                return $this->failed('Subscription package not found', null, 404);
            }

            if ($package->subscriptions()->exists()) {
                $package->update(['status' => 'inactive']);

                return $this->success('Subscription package marked inactive successfully', $package);
            }

            $package->delete();

            return $this->success('Subscription package deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function inactive(Request $request, $id)
    {
        try {
            if (!$this->isAdminUser($request->attributes->get('api_user'))) {
                return $this->failed('Only admin can inactive subscription packages', null, 403);
            }

            $package = SubscriptionPackage::find($id);

            if (!$package) {
                return $this->failed('Subscription package not found', null, 404);
            }

            $package->update(['status' => 'inactive']);

            return $this->success('Subscription package marked inactive successfully', $package);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function storeCurrentSubscription(Request $request, $storeId)
    {
        try {
            $store = Shops::find($storeId);

            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            if (!$this->canManageStore($request, $store)) {
                return $this->failed('You are not allowed to access this store subscription', null, 403);
            }

            $subscription = StoreSubscription::with('package')
                ->where('store_id', $store->id)
                ->whereIn('status', ['active', 'pending'])
                ->latest()
                ->first();

            return $this->success('Store subscription fetched successfully', [
                'subscription' => $subscription,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function subscribe(Request $request, $storeId)
    {
        try {
            $store = Shops::find($storeId);

            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            if (!$this->canManageStore($request, $store)) {
                return $this->failed('You are not allowed to subscribe this store', null, 403);
            }

            $validated = $request->validate([
                'subscription_package_id' => ['required', 'integer', 'exists:subscription_packages,id'],
                'billing_cycle' => ['nullable', Rule::in(['monthly', 'yearly', 'lifetime'])],
            ]);

            $package = SubscriptionPackage::where('status', 'active')->find($validated['subscription_package_id']);

            if (!$package) {
                return $this->failed('Active subscription package not found', null, 404);
            }

            $billingCycle = $validated['billing_cycle'] ?? $package->billing_cycle;
            $startsAt = Carbon::now();

            StoreSubscription::where('store_id', $store->id)
                ->whereIn('status', ['pending', 'active'])
                ->update(['status' => 'cancelled']);

            $subscription = StoreSubscription::create([
                'store_id' => $store->id,
                'subscription_package_id' => $package->id,
                'status' => ((float) $package->price > 0) ? 'pending' : 'active',
                'starts_at' => $startsAt,
                'ends_at' => $this->calculateEndsAt($startsAt, $billingCycle),
                'trial_ends_at' => $package->trial_days ? $startsAt->copy()->addDays((int) $package->trial_days) : null,
                'price' => $package->price,
                'currency' => 'BDT',
                'billing_cycle' => $billingCycle,
                'payment_status' => ((float) $package->price > 0) ? 'unpaid' : 'paid',
                'payment_reference' => null,
            ]);

            $paymentRequired = (float) $subscription->price > 0;

            if ($paymentRequired) {
                return $this->amarPayService->initiateStoreSubscriptionPayment(
                    $subscription,
                    $request->attributes->get('api_user')
                );
            }

            return $this->success('Subscription initiated successfully', [
                'subscription' => $subscription->load('package'),
                'payment_required' => $paymentRequired,
                'payment_url' => null,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    private function canManageStore(Request $request, Shops $store): bool
    {
        $user = $request->attributes->get('api_user');

        if ($this->isAdminUser($user)) {
            return true;
        }

        return $user && (int) $store->user_id === (int) $user->id;
    }

    private function calculateEndsAt(Carbon $startsAt, string $billingCycle): ?Carbon
    {
        return match ($billingCycle) {
            'monthly' => $startsAt->copy()->addMonth(),
            'yearly' => $startsAt->copy()->addYear(),
            'lifetime' => null,
            default => null,
        };
    }
}
