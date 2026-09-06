<?php

namespace App\Http\Controllers;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
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
            'errors' => $errors
        ], $code);
    }

    /**
     * Add a new review
     */
    public function addReview(Request $request)
    {
        try {
            $data = $request->validate([
                'user_id' => ['required', 'integer', 'exists:users,id'],
                'product_id' => ['nullable', 'integer', 'exists:products,id'],
                'shop_id' => ['required', 'integer', 'exists:shops,id'],
                'comment' => ['required', 'string', 'max:5000'],
                'star_count' => ['required', 'integer', 'min:1', 'max:5'],
                'status' => ['nullable', 'boolean'],
                'priority' => ['nullable', 'integer', 'min:0'],
                'type' => ['nullable', 'string', 'max:100'],
            ], [
                'user_id.required' => 'User is required.',
                'user_id.exists' => 'Selected user does not exist.',
                'product_id.exists' => 'Selected product does not exist.',
                'shop_id.required' => 'Shop is required.',
                'shop_id.exists' => 'Selected shop does not exist.',
                'comment.required' => 'Review comment is required.',
                'star_count.required' => 'Star rating is required.',
                'star_count.min' => 'Star rating must be at least 1.',
                'star_count.max' => 'Star rating cannot be greater than 5.',
            ]);

            $review = Review::create([
                'user_id' => $data['user_id'],
                'product_id' => $data['product_id'] ?? null,
                'shop_id' => $data['shop_id'],
                'comment' => $data['comment'],
                'star_count' => $data['star_count'],
                'status' => array_key_exists('status', $data) ? (bool) $data['status'] : true,
                'priority' => $data['priority'] ?? 0,
                'type' => $data['type'] ?? null,
            ]);

            return $this->success('Review created successfully', $review->load('user', 'product', 'shop'), 201);
        } catch (ValidationException $e) {
            $firstError = collect($e->errors())->flatten()->first();

            return $this->failed($firstError ?? 'Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all reviews
     */
    public function getAllReview(Request $request)
    {
        $query = Review::with('user', 'product', 'shop');

        if ($request->filled('status')) {
            $query->where('status', (bool) $request->status);
        }

        $items = $query->latest()->get();

        return $this->success('Reviews retrieved successfully', [
            'count' => $items->count(),
            'items' => $items
        ]);
    }

    /**
     * Get reviews for a product
     */
    public function getReviewByProduct($productId)
    {
        $items = Review::where('product_id', $productId)
            ->where('status', 1)
            ->with('user', 'product', 'shop')
            ->latest()
            ->get();

       return $this->success('Reviews retrieved successfully', [
            'count' => $items->count(),
            'items' => $items
        ]);
    }

    /**
     * Get reviews for a shop
     */
    public function getReviewByShop($shopId)
    {
        $items = Review::where('shop_id', $shopId)
            ->where('status', 1)
            ->with('user', 'product', 'shop')
            ->latest()
            ->get();

        return $this->success('Reviews retrieved successfully', [
            'count' => $items->count(),
            'items' => $items
        ]);
    }

    /**
     * Get reviews by a user
     */
    public function getReviewByUser($userId)
    {
        $items = Review::where('user_id', $userId)
            ->where('status', 1)
            ->with('user', 'product', 'shop')
            ->latest()
            ->get();

       return $this->success('Reviews retrieved successfully', [
            'count' => $items->count(),
            'items' => $items
        ]);
    }

    /**
     * Update a review by user
     */
    public function updateReviewByUser(Request $request, $id)
    {
        $review = Review::find($id);

        if (! $review) {
            return $this->failed('Review not found.', null, 404);
        }

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'comment' => 'nullable|string',
            'star_count' => 'nullable|integer|min:1|max:5',
            'status' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
        ]);

        if ((int) $review->user_id !== (int) $data['user_id']) {
            return $this->failed('You are not allowed to update this review.', null, 403);
        }

        if (array_key_exists('comment', $data)) {
            $review->comment = $data['comment'];
        }
        if (array_key_exists('star_count', $data)) {
            $review->star_count = $data['star_count'];
        }
        if (array_key_exists('status', $data)) {
            $review->status = (bool) $data['status'];
        }
        if (array_key_exists('priority', $data)) {
            $review->priority = $data['priority'];
        }
        if (array_key_exists('type', $data)) {
            $review->type = $data['type'];
        }

        $review->save();

        return $this->success('Review updated successfully', $review->load('user', 'product', 'shop'));
    }

    /**
     * Remove (soft) a review
     */
    public function removeReview($id)
    {
        $review = Review::find($id);

        if (! $review) {
            return $this->failed('Review not found.', null, 404);
        }

        $review->status = false;
        $review->save();

        return $this->success('Review removed successfully', $review->load('user', 'product', 'shop'));
    }
}
