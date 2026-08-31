<?php

namespace App\Http\Controllers;

use App\Models\CustomerPreferenceStore;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerPreferenceStoreController extends Controller
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

    private function isSellerUser($user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        $userType = strtolower((string) ($user->user_type ?? ''));

        return in_array('seller', [$role, $userType], true);
    }

    private function isCustomerUser($user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        $userType = strtolower((string) ($user->user_type ?? ''));

        return in_array($role, ['customer', 'user'], true)
            || in_array($userType, ['customer', 'user'], true);
    }

    private function assertUserTypes(int $customerUserId, int $sellerId): ?array
    {
        $customer = User::find($customerUserId);
        $seller = User::find($sellerId);

        if (!$customer) {
            return ['message' => 'Customer not found', 'code' => 404];
        }

        if (!$seller) {
            return ['message' => 'Seller not found', 'code' => 404];
        }

        if (!$this->isCustomerUser($customer)) {
            return ['message' => 'Selected customer user is not a customer', 'code' => 422];
        }

        if (!$this->isSellerUser($seller)) {
            return ['message' => 'Selected seller user is not a seller', 'code' => 422];
        }

        return null;
    }

    /**
     * Activate one customer/seller preference and deactivate the customer's
     * other preferences atomically.
     */
    private function activatePreference(int $customerUserId, int $sellerId, ?int $addedBy, string $addedByType): CustomerPreferenceStore
    {
        return DB::transaction(function () use ($customerUserId, $sellerId, $addedBy, $addedByType) {
            CustomerPreferenceStore::where('customer_user_id', $customerUserId)
                ->lockForUpdate()
                ->get();

            CustomerPreferenceStore::where('customer_user_id', $customerUserId)
                ->where('seller_id', '!=', $sellerId)
                ->update(['status' => 'inactive']);

            return CustomerPreferenceStore::updateOrCreate(
                [
                    'customer_user_id' => $customerUserId,
                    'seller_id' => $sellerId,
                ],
                [
                    'added_by' => $addedBy,
                    'added_by_type' => $addedByType,
                    'status' => 'active',
                ]
            );
        });
    }

    public function addSellerPreference(Request $request)
    {
        try {
            $authUser = $request->attributes->get('api_user');

            $validated = $request->validate([
                'customer_user_id' => ['nullable', 'integer', 'exists:users,id'],
                'seller_id' => ['required', 'integer', 'exists:users,id'],
            ]);

            $customerUserId = (int) ($validated['customer_user_id'] ?? $authUser?->id);

            if (!$customerUserId) {
                return $this->failed('customer_user_id is required', null, 422);
            }

            if (!$this->isAdminUser($authUser) && (int) $authUser?->id !== $customerUserId) {
                return $this->failed('You cannot add seller preference for another customer', null, 403);
            }

            $typeError = $this->assertUserTypes($customerUserId, (int) $validated['seller_id']);
            if ($typeError) {
                return $this->failed($typeError['message'], null, $typeError['code']);
            }

            $preference = $this->activatePreference(
                $customerUserId,
                (int) $validated['seller_id'],
                $authUser?->id,
                $this->isAdminUser($authUser) ? 'admin' : 'customer'
            )->load(['customer', 'seller']);

            return $this->success('Seller preference added successfully', $preference, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function addCustomerPreference(Request $request)
    {
        try {
            $authUser = $request->attributes->get('api_user');

            $validated = $request->validate([
                'customer_user_id' => ['nullable', 'integer', 'exists:users,id'],
                'seller_id' => ['nullable', 'integer', 'exists:users,id'],
                'name' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string', 'max:1000'],
                'password' => ['nullable', 'string', 'min:6'],
            ]);

            $sellerId = (int) ($validated['seller_id'] ?? $authUser?->id);

            if (!$sellerId) {
                return $this->failed('seller_id is required', null, 422);
            }

            if (!$this->isAdminUser($authUser) && (int) $authUser?->id !== $sellerId) {
                return $this->failed('You cannot add customer preference for another seller', null, 403);
            }

            $customer = $this->resolveOrCreateCustomer($validated);
            if (!$customer) {
                return $this->failed('customer_user_id or customer information is required', [
                    'customer_user_id' => ['Provide customer_user_id, or provide at least name, phone, or email to create a customer.'],
                ], 422);
            }

            $typeError = $this->assertUserTypes((int) $customer->id, $sellerId);
            if ($typeError) {
                return $this->failed($typeError['message'], null, $typeError['code']);
            }

            $preference = $this->activatePreference(
                (int) $customer->id,
                $sellerId,
                $authUser?->id,
                $this->isAdminUser($authUser) ? 'admin' : 'seller'
            )->load(['customer', 'seller']);

            return $this->success('Customer preference added successfully', [
                'customer' => $customer->fresh(),
                'preference' => $preference,
            ], 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    private function resolveOrCreateCustomer(array $validated): ?User
    {
        if (!empty($validated['customer_user_id'])) {
            return User::find((int) $validated['customer_user_id']);
        }

        $query = User::query();
        $hasLookup = false;

        if (!empty($validated['email'])) {
            $query->where('email', $validated['email']);
            $hasLookup = true;
        }

        if (!empty($validated['phone'])) {
            $method = $hasLookup ? 'orWhere' : 'where';
            $query->{$method}('phone', $validated['phone']);
            $hasLookup = true;
        }

        if ($hasLookup) {
            $existing = $query->first();
            if ($existing) {
                return $existing;
            }
        }

        if (empty($validated['name']) && empty($validated['phone']) && empty($validated['email'])) {
            return null;
        }

        return User::create([
            'name' => $validated['name'] ?? $validated['phone'] ?? $validated['email'] ?? 'Customer',
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'password' => Hash::make($validated['password'] ?? Str::random(12)),
            'user_type' => 'customer',
        ]);
    }

    public function getCustomerBySeller(Request $request, $sellerId = null)
    {
        try {
            $authUser = $request->attributes->get('api_user');
            $sellerId = (int) ($sellerId ?: $request->query('seller_id') ?: $authUser?->id);

            if (!$sellerId) {
                return $this->failed('seller_id is required', null, 422);
            }

            if (!$this->isAdminUser($authUser) && (int) $authUser?->id !== $sellerId) {
                return $this->failed('You cannot view another seller customers', null, 403);
            }

            $perPage = (int) $request->get('per_page', 20);
            $preferences = CustomerPreferenceStore::with(['customer'])
                ->where('seller_id', $sellerId)
                ->where('status', 'active')
                ->latest()
                ->paginate($perPage);

            return $this->success('Seller preferred customers fetched successfully', $preferences);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function getSellerByCustomer(Request $request, $customerUserId = null)
    {
        try {
            $authUser = $request->attributes->get('api_user');
            $customerUserId = (int) ($customerUserId ?: $request->query('customer_user_id') ?: $authUser?->id);

            if (!$customerUserId) {
                return $this->failed('customer_user_id is required', null, 422);
            }

            if (!$this->isAdminUser($authUser) && (int) $authUser?->id !== $customerUserId) {
                return $this->failed('You cannot view another customer sellers', null, 403);
            }

            $perPage = (int) $request->get('per_page', 20);
            $preferences = CustomerPreferenceStore::with(['seller.shops.logo', 'seller.shops.banner'])
                ->where('customer_user_id', $customerUserId)
                ->where('status', 'active')
                ->latest()
                ->paginate($perPage);

            foreach ($preferences as $preference) {
                if ($preference->seller) {
                    $firstShop = $preference->seller->shops->first();
                    $preference->seller->setRelation('shop', $firstShop);
                    $preference->seller->unsetRelation('shops');
                }
            }

            return $this->success('Customer preferred sellers fetched successfully', $preferences);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function setActivePreference(Request $request)
    {
        try {
            $authUser = $request->attributes->get('api_user');

            $validated = $request->validate([
                'customer_user_id' => ['nullable', 'integer', 'exists:users,id'],
                'seller_id' => ['required', 'integer', 'exists:users,id'],
            ]);

            $customerUserId = (int) ($validated['customer_user_id'] ?? $authUser?->id);
            $sellerId = (int) $validated['seller_id'];

            if (!$customerUserId) {
                return $this->failed('customer_user_id is required', null, 422);
            }

            if (!$this->isAdminUser($authUser) && (int) $authUser?->id !== $customerUserId) {
                return $this->failed('You cannot change another customer active store', null, 403);
            }

            $typeError = $this->assertUserTypes($customerUserId, $sellerId);
            if ($typeError) {
                return $this->failed($typeError['message'], null, $typeError['code']);
            }

            $preference = CustomerPreferenceStore::where('customer_user_id', $customerUserId)
                ->where('seller_id', $sellerId)
                ->first();

            if (!$preference) {
                return $this->failed('Store preference not found', null, 404);
            }

            $preference = $this->activatePreference(
                $customerUserId,
                $sellerId,
                $authUser?->id,
                $this->isAdminUser($authUser) ? 'admin' : 'customer'
            )->load(['customer', 'seller']);

            return $this->success('Active store updated successfully', $preference);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function removePreference(Request $request)
    {
        try {
            $authUser = $request->attributes->get('api_user');

            $validated = $request->validate([
                'customer_user_id' => ['required', 'integer', 'exists:users,id'],
                'seller_id' => ['required', 'integer', 'exists:users,id'],
            ]);

            $isOwner = (int) $authUser?->id === (int) $validated['customer_user_id']
                || (int) $authUser?->id === (int) $validated['seller_id'];

            if (!$this->isAdminUser($authUser) && !$isOwner) {
                return $this->failed('You cannot remove this preference', null, 403);
            }

            $preference = CustomerPreferenceStore::where('customer_user_id', (int) $validated['customer_user_id'])
                ->where('seller_id', (int) $validated['seller_id'])
                ->first();

            if (!$preference) {
                return $this->failed('Preference not found', null, 404);
            }

            $preference->update(['status' => 'inactive']);

            return $this->success('Preference removed successfully', $preference);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
