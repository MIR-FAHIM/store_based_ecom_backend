<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationMessageResource;
use App\Http\Resources\ConversationResource;
use App\Models\ConversationMessage;
use App\Service\ChatService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    public function __construct(
        private ChatService $chatService
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

    public function conversations(Request $request)
    {
        try {
            $user = $this->authenticatedUser($request);
            $perPage = (int) $request->get('per_page', 20);

            return $this->success(
                'Conversations fetched successfully',
                $this->resourceData(ConversationResource::collection($this->chatService->conversationsFor($user, $perPage)))
            );
        } catch (\Throwable $e) {
            return $this->handleThrowable($e);
        }
    }

    public function unreadCount(Request $request)
    {
        try {
            $user = $this->authenticatedUser($request);

            return $this->success('Unread chat count fetched successfully', [
                'total_unread_count' => $this->chatService->totalUnreadCountFor($user),
            ]);
        } catch (\Throwable $e) {
            return $this->handleThrowable($e);
        }
    }

    public function openConversation(Request $request)
    {
        try {
            $validated = $request->validate([
                'shop_id' => ['required', 'integer', 'exists:shops,id'],
            ]);

            $conversation = $this->chatService->openConversation(
                $this->authenticatedUser($request),
                (int) $validated['shop_id']
            );

            return $this->success('Conversation opened successfully', (new ConversationResource($conversation))->resolve($request), 201);
        } catch (\Throwable $e) {
            return $this->handleThrowable($e);
        }
    }

    public function conversation(Request $request, int $id)
    {
        try {
            $conversation = $this->chatService->getConversationFor($this->authenticatedUser($request), $id);

            return $this->success('Conversation fetched successfully', (new ConversationResource($conversation))->resolve($request));
        } catch (\Throwable $e) {
            return $this->handleThrowable($e);
        }
    }

    public function messages(Request $request, int $id)
    {
        try {
            $conversation = $this->chatService->getConversationFor($this->authenticatedUser($request), $id);
            $messages = $this->chatService->messagesFor(
                $this->authenticatedUser($request),
                $conversation,
                (int) $request->get('per_page', 30)
            );

            return $this->success(
                'Messages fetched successfully',
                $this->resourceData(ConversationMessageResource::collection($messages))
            );
        } catch (\Throwable $e) {
            return $this->handleThrowable($e);
        }
    }

    public function sendMessage(Request $request, int $id)
    {
        try {
            $validated = $request->validate([
                'message_type' => [
                    'required',
                    'string',
                    Rule::in([
                        ConversationMessage::TYPE_TEXT,
                        ConversationMessage::TYPE_PRODUCT,
                        ConversationMessage::TYPE_ORDER,
                    ]),
                ],
                'message' => [
                    Rule::requiredIf(fn () => $request->input('message_type') === ConversationMessage::TYPE_TEXT),
                    'nullable',
                    'string',
                    'max:5000',
                ],
                'product_id' => [
                    Rule::requiredIf(fn () => $request->input('message_type') === ConversationMessage::TYPE_PRODUCT),
                    'nullable',
                    'integer',
                    'exists:products,id',
                ],
                'order_id' => [
                    Rule::requiredIf(fn () => $request->input('message_type') === ConversationMessage::TYPE_ORDER),
                    'nullable',
                    'integer',
                    'exists:orders,id',
                ],
                'reply_to_message_id' => ['nullable', 'integer', 'exists:conversation_messages,id'],
            ]);

            $conversation = $this->chatService->getConversationFor($this->authenticatedUser($request), $id);
            $message = $this->chatService->sendMessage($this->authenticatedUser($request), $conversation, $validated);

            return $this->success('Message sent successfully', (new ConversationMessageResource($message))->resolve($request), 201);
        } catch (\Throwable $e) {
            return $this->handleThrowable($e);
        }
    }

    public function markMessageRead(Request $request, int $id)
    {
        try {
            $message = ConversationMessage::with('conversation.shop')->find($id);
            if (!$message) {
                return $this->failed('Message not found', null, 404);
            }

            $message = $this->chatService->markMessageRead($this->authenticatedUser($request), $message);

            return $this->success('Message marked as read successfully', (new ConversationMessageResource($message))->resolve($request));
        } catch (\Throwable $e) {
            return $this->handleThrowable($e);
        }
    }

    public function markConversationRead(Request $request, int $id)
    {
        try {
            $conversation = $this->chatService->getConversationFor($this->authenticatedUser($request), $id);
            $conversation = $this->chatService->markConversationRead($this->authenticatedUser($request), $conversation);

            return $this->success('Conversation marked as read successfully', (new ConversationResource($conversation))->resolve($request));
        } catch (\Throwable $e) {
            return $this->handleThrowable($e);
        }
    }

    private function authenticatedUser(Request $request)
    {
        $user = $request->attributes->get('api_user');

        if (!$user) {
            abort(response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized',
                'errors' => null,
            ], 401));
        }

        return $user;
    }

    private function resourceData(JsonResource $resource): array
    {
        $data = $resource->response()->getData(true);

        return $data;
    }

    private function handleThrowable(\Throwable $e)
    {
        if ($e instanceof ValidationException) {
            return $this->failed('Validation failed', $e->errors(), 422);
        }

        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
    }
}
