<?php

namespace App\Http\Controllers;

use App\Models\WebsiteSetting;
use Illuminate\Http\Request;

class WebsiteSettingController extends Controller
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

    /**
     * POST /website-settings/logo
     */
    public function addWebsiteLogo(Request $request)
    {
        try {
            $validated = $request->validate([
                'logo_id' => ['required', 'integer', 'exists:uploads,id'],
            ]);

            $setting = WebsiteSetting::first();

            if (!$setting) {
                $setting = WebsiteSetting::create([
                    'logo_id' => $validated['logo_id'],
                ]);
            } else {
                $setting->logo_id = $validated['logo_id'];
                $setting->save();
            }

            $setting->load(['logo']);

            return $this->success('Website logo updated successfully', $setting);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /website-settings
     */
    public function addWebsiteSetting(Request $request)
    {
        try {
            $validated = $request->validate([
                'logo_id' => ['nullable', 'integer', 'exists:uploads,id'],
                'banner_id' => ['nullable', 'integer', 'exists:uploads,id'],
                'website_name' => ['nullable', 'string', 'max:255'],
                'slogan' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'short_details' => ['nullable', 'string'],
                'photo_id' => ['nullable', 'integer', 'exists:uploads,id'],
                'type' => ['nullable', 'string', 'max:50'],
                'company_name' => ['nullable', 'string', 'max:255'],
                'management_details' => ['nullable', 'string'],
                'registered_address' => ['nullable', 'string'],
                'trade_license_number' => ['nullable', 'string', 'max:100'],
                'company_phone' => ['nullable', 'string', 'max:50'],
                'company_email' => ['nullable', 'email', 'max:255'],
            ]);

            $setting = WebsiteSetting::first();

            if (!$setting) {
                $setting = WebsiteSetting::create($validated);
            } else {
                $setting->fill($validated);
                $setting->save();
            }

            $setting->load(['logo', 'banner', 'photo']);

            return $this->success('Website settings saved successfully', $setting);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /website-settings/logo
     */
    public function getLogo()
    {
        try {
            $setting = WebsiteSetting::with(['logo'])->first();

            if (!$setting) {
                return $this->failed('Website settings not found', null, 404);
            }

            return $this->success('Website logo fetched successfully', [
                'logo_id' => $setting->logo_id,
                'logo' => $setting->logo,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /website-settings
     */
    public function getWebsiteSetting()
    {
        try {
            $setting = WebsiteSetting::with(['logo', 'banner', 'photo'])->first();

            if (!$setting) {
                return $this->failed('Website settings not found', null, 404);
            }

            return $this->success('Website settings fetched successfully', $setting);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
