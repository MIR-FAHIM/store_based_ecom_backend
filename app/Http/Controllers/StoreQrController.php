<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Shops;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Color\Color;

class StoreQrController extends Controller
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

    private function qrPayload(Shops $store): string
    {
        return json_encode([
            'seller_id' => (int) $store->user_id,
            'store_slug' => (string) $store->slug,
        ], JSON_UNESCAPED_SLASHES);
    }

    private function resolveAuthorizedStore(Request $request, $storeId): array
    {
        $user = $request->attributes->get('api_user');

        if (!$user) {
            return [
                'error_response' => $this->failed('Not authenticated', null, 401),
                'store' => null,
            ];
        }

        $store = Shops::find($storeId);

        if (!$store) {
            return [
                'error_response' => $this->failed('Store not found', null, 404),
                'store' => null,
            ];
        }

        if (!$this->isAdminUser($user) && (int) $store->user_id !== (int) $user->id) {
            return [
                'error_response' => $this->failed('Forbidden', null, 403),
                'store' => null,
            ];
        }

        if (empty($store->user_id) || empty($store->slug)) {
            return [
                'error_response' => $this->failed('Store QR data is incomplete', null, 422),
                'store' => null,
            ];
        }

        return [
            'error_response' => null,
            'store' => $store,
        ];
    }

    /**
     * GET /api/stores/{storeId}/qr/payload
     */
    public function payload(Request $request, $storeId)
    {
        try {
            $resolved = $this->resolveAuthorizedStore($request, $storeId);

            if ($resolved['error_response']) {
                return $resolved['error_response'];
            }

            $store = $resolved['store'];
            $payloadString = $this->qrPayload($store);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'store_id' => (int) $store->id,
                    'seller_id' => (int) $store->user_id,
                    'store_slug' => (string) $store->slug,
                    'payload' => $payloadString,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/stores/{storeId}/qr/app
     */
    public function appQr(Request $request, $storeId)
    {
        try {
            $resolved = $this->resolveAuthorizedStore($request, $storeId);

            if ($resolved['error_response']) {
                return $resolved['error_response'];
            }

            $store = $resolved['store'];
            $payloadString = $this->qrPayload($store);

            $qrCode = new QrCode(
                data: $payloadString,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                size: 800,
                margin: 4,
                roundBlockSizeMode: RoundBlockSizeMode::Margin,
                foregroundColor: new Color(0, 0, 0),
                backgroundColor: new Color(255, 255, 255)
            );

            $writer = new PngWriter();
            $result = $writer->write($qrCode);

            return response($result->getString(), 200)
                ->header('Content-Type', 'image/png');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
