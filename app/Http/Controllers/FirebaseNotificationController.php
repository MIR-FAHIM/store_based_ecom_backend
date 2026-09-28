<?php

namespace App\Http\Controllers;

use App\Exceptions\FirebaseNotificationException;
use App\Models\User;
use App\Service\FirebaseNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class FirebaseNotificationController extends Controller
{
    public function __construct(
        private FirebaseNotificationService $firebaseNotificationService
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

    /**
     * POST /firebase/test-push
     */
    public function sendPushNotification(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => ['nullable', 'integer', 'exists:users,id', 'required_without:device_token'],
                'device_token' => ['nullable', 'string', 'max:4096', 'required_without:user_id'],
                'title' => ['required', 'string', 'max:255'],
                'body' => ['required', 'string', 'max:1000'],
                'image' => ['nullable', 'url', 'max:2048'],
                'data' => ['nullable', 'array'],
            ]);

            if (!empty($validated['device_token'])) {
                $result = $this->firebaseNotificationService->sendToToken(
                    $validated['device_token'],
                    $validated['title'],
                    $validated['body'],
                    $validated['data'] ?? [],
                    $validated['image'] ?? null
                );
            } else {
                $user = User::findOrFail($validated['user_id']);
                $result = $this->firebaseNotificationService->sendToUser(
                    $user,
                    $validated['title'],
                    $validated['body'],
                    $validated['data'] ?? [],
                    $validated['image'] ?? null
                );
            }

            return $this->success('Push notification sent successfully', $result);
        } catch (ValidationException $e) {
            $firstError = collect($e->errors())->flatten()->first();

            return $this->failed($firstError ?? 'Validation failed', $e->errors(), 422);
        } catch (FirebaseNotificationException $e) {
            Log::warning('Firebase push notification failed', [
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ]);

            return $this->failed($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            Log::error('Firebase push notification error', [
                'error' => $e->getMessage(),
            ]);

            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
