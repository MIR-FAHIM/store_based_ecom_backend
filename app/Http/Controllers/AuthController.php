<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\ApiToken;
use App\Models\LoginSuccessLog;
use App\Models\OTPSms;
use App\Service\ApiTokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
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

    private function logLoginSuccess(Request $request, User $user, ApiToken $apiToken, string $loginType, ?string $identifier = null, ?string $tokenName = null, ?string $platform = null): void
    {
        try {
            LoginSuccessLog::create([
                'user_id' => $user->id,
                'api_token_id' => $apiToken->id,
                'login_type' => $loginType,
                'identifier' => $identifier,
                'token_name' => $tokenName,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'user_type' => $user->user_type,
                'platform' => $platform,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'logged_in_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write login success log', [
                'user_id' => $user->id ?? null,
                'login_type' => $loginType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function prepareUserForAuthResponse(User $user): User
    {
        if ($user->user_type !== 'seller') {
            return $user;
        }

        $seller = User::with(['shops.logo', 'shops.banner'])->find($user->id);

        if (!$seller) {
            return $user;
        }

        $firstShop = $seller->shops->first();
        $seller->setRelation('shop', $firstShop);
        $seller->unsetRelation('shops');

        return $seller;
    }

    /**
     * POST /auth/login
     */
    public function login(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => ['nullable', 'email', 'required_without:phone'],
                'phone' => ['nullable', 'string', 'required_without:email'],
                'password' => ['required', 'string', 'min:6'],
                'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'name' => ['nullable', 'string', 'max:255'],
                'platform' => ['nullable', 'string', 'max:50'],
                'fcm_token' => ['nullable', 'string', 'max:300'],
            ]);

            $user = null;

            if (!empty($validated['email'])) {
                $user = User::where('email', $validated['email'])->first();
            } elseif (!empty($validated['phone'])) {
                $rawPhone = trim($validated['phone']);
                $digits = preg_replace('/\D+/', '', $rawPhone);

                $local = preg_replace('/^88/', '', $digits);
                $local = preg_replace('/^0/', '', $local);

                $variants = array_filter(array_unique([
                    $rawPhone,
                    $digits,
                    '+88' . '0' . $local,
                    '+88' . $local,
                    '88' . '0' . $local,
                    '88' . $local,
                    '0' . $local,
                    $local,
                ]));

                $user = User::whereIn('phone', $variants)->first();
            }

            if (!$user ) {
                return $this->failed('Invalid credentials', null, 401);
            }

            if (!Hash::check($validated['password'], $user->password)) {
                return $this->failed('Invalid credentials', null, 401);
            }

            if (!empty($validated['fcm_token'])) {
                $user->forceFill(['device_token' => $validated['fcm_token']])->save();
            }

            $scopes = ['basic'];
            if ($user->role === 'admin') {
                $scopes[] = 'admin';
            }

            $days = $validated['expires_in_days'] ?? 30;
            $name = $validated['name'] ?? 'login-token';

            $created = ApiTokenService::create($user, $scopes, $days, $name);
            $this->logLoginSuccess(
                $request,
                $user,
                $created['token'],
                'password',
                $validated['email'] ?? $validated['phone'] ?? null,
                $name,
                $validated['platform'] ?? null
            );

            $userForResponse = $this->prepareUserForAuthResponse($user);

            return $this->success('Login successful', [
                'token' => $created['plain'],
                'token_type' => 'Bearer',
                'expires_at' => $created['token']->expires_at,
                'token_id' => $created['token']->id,
                'user' => $userForResponse,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /auth/login-seller
     */
    public function loginSeller(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => ['nullable', 'email', 'required_without:phone'],
                'phone' => ['nullable', 'string', 'required_without:email'],
                'password' => ['required', 'string', 'min:6'],
                'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'name' => ['nullable', 'string', 'max:255'],
                'platform' => ['nullable', 'string', 'max:50'],
                'fcm_token' => ['nullable', 'string', 'max:300'],
            ]);

            $user = null;

            if (!empty($validated['email'])) {
                $user = User::where('email', $validated['email'])->first();
            } elseif (!empty($validated['phone'])) {
                $rawPhone = trim($validated['phone']);
                $digits = preg_replace('/\D+/', '', $rawPhone);

                $local = preg_replace('/^88/', '', $digits);
                $local = preg_replace('/^0/', '', $local);

                $variants = array_filter(array_unique([
                    $rawPhone,
                    $digits,
                    '+88' . '0' . $local,
                    '+88' . $local,
                    '88' . '0' . $local,
                    '88' . $local,
                    '0' . $local,
                    $local,
                ]));

                $user = User::whereIn('phone', $variants)->first();
            }

            if (!$user) {
                return $this->failed('Seller not found', null, 404);
            }

            if ($user->user_type !== 'seller') {
                return $this->failed('This account is not a seller account', null, 403);
            }

            if (!Hash::check($validated['password'], $user->password)) {
                return $this->failed('Invalid credentials', null, 401);
            }

            if (!empty($validated['fcm_token'])) {
                $user->forceFill(['device_token' => $validated['fcm_token']])->save();
            }

            $scopes = ['basic'];
            if ($user->role === 'admin') {
                $scopes[] = 'admin';
            }

            $days = $validated['expires_in_days'] ?? 30;
            $name = $validated['name'] ?? 'seller-login-token';

            $created = ApiTokenService::create($user, $scopes, $days, $name);
            $this->logLoginSuccess(
                $request,
                $user,
                $created['token'],
                'seller_password',
                $validated['email'] ?? $validated['phone'] ?? null,
                $name,
                $validated['platform'] ?? null
            );

            $userForResponse = $this->prepareUserForAuthResponse($user);

            return $this->success('Seller login successful', [
                'token' => $created['plain'],
                'token_type' => 'Bearer',
                'expires_at' => $created['token']->expires_at,
                'token_id' => $created['token']->id,
                'user' => $userForResponse,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /auth/login-otp
     */
    public function loginWithOtp(Request $request)
    {
        try {
            $validated = $request->validate([
                'mobile_number' => ['required', 'string', 'max:20'],
                'otp' => ['required', 'string', 'max:10'],
                'type' => ['nullable', 'string', 'max:50'],
                'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'name' => ['nullable', 'string', 'max:255'],
                'platform' => ['nullable', 'string', 'max:50'],
            ]);

            $query = OTPSms::where('mobile_number', $validated['mobile_number'])
                ->where('otp', $validated['otp'])
                ->where('is_expired', false)
                ->where('status', true);

            if (!empty($validated['type'])) {
                $query->where('type', $validated['type']);
            }

            $otpRecord = $query->latest()->first();

            if (!$otpRecord) {
                return $this->failed('Invalid OTP', null, 400);
            }

            $expiresAt = $otpRecord->created_at
                ? Carbon::parse($otpRecord->created_at)->addMinutes($otpRecord->validity_time)
                : Carbon::now()->subMinute();

            if (Carbon::now()->greaterThan($expiresAt)) {
                $otpRecord->update([
                    'is_expired' => true,
                    'status' => false,
                ]);

                return $this->failed('OTP expired', null, 400);
            }

            $rawPhone = trim($validated['mobile_number']);
            $digits = preg_replace('/\D+/', '', $rawPhone);
        
            $local = preg_replace('/^88/', '', $digits);
            $local = preg_replace('/^0/', '', $local);

            $variants = array_filter(array_unique([
                $rawPhone,
                $digits,
                '+88' . '0' . $local,
                '+88' . $local,
                '88' . '0' . $local,
                '88' . $local,
                '0' . $local,
                $local,
            ]));

            $user = User::whereIn('phone', $variants)->first();

            if (!$user) {
                return $this->failed('User not found',);
            }

            $otpRecord->update([
                'is_expired' => true,
                'status' => false,
            ]);

            $scopes = ['basic'];
            if ($user->role === 'admin') {
                $scopes[] = 'admin';
            }

            $days = $validated['expires_in_days'] ?? 30;
            $name = $validated['name'] ?? 'otp-login-token';

            $created = ApiTokenService::create($user, $scopes, $days, $name);
            $this->logLoginSuccess(
                $request,
                $user,
                $created['token'],
                'otp',
                $validated['mobile_number'],
                $name,
                $validated['platform'] ?? null
            );

            $userForResponse = $this->prepareUserForAuthResponse($user);

            return $this->success('Login successful', [
                'token' => $created['plain'],
                'token_type' => 'Bearer',
                'expires_at' => $created['token']->expires_at,
                'token_id' => $created['token']->id,
                'user' => $userForResponse,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    

    /**
     * POST /auth/logout
     */
    public function logout(Request $request)
    {
        try {
            $apiToken = $request->attributes->get('api_token');

            if (!$apiToken) {
                return $this->failed('API token missing', null, 401);
            }

            $apiToken->update(['is_revoked' => true]);

            return $this->success('Logged out successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /auth/tokens
     * List all tokens for the authenticated user
     */
    public function listTokens(Request $request)
    {
        try {
            $user = $request->attributes->get('api_user');

            if (!$user) {
                return $this->failed('Not authenticated', null, 401);
            }

            $tokens = ApiToken::where('user_id', $user->id)->get();

            return $this->success('Tokens fetched', $tokens);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /auth/tokens/{id}
     * Revoke a token by id (must belong to the authenticated user)
     */
    public function revokeToken(Request $request, $id)
    {
        try {
            $user = $request->attributes->get('api_user');

            if (!$user) {
                return $this->failed('Not authenticated', null, 401);
            }

            $token = ApiToken::find($id);

            if (!$token) {
                return $this->failed('Token not found', null, 404);
            }

            if ($token->user_id !== $user->id) {
                return $this->failed('Forbidden', null, 403);
            }

            $token->update(['is_revoked' => true]);

            return $this->success('Token revoked');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
