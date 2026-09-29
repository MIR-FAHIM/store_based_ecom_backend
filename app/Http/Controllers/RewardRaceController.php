<?php

namespace App\Http\Controllers;

use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengeReward;
use App\Models\RewardClaim;
use App\Models\Shops;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RewardRaceController extends Controller
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

    // --- SELLER APIs ---

    /**
     * POST /api/seller/challenges
     */
    public function createChallenge(Request $request)
    {
        try {
            $validated = $request->validate([
                'shop_id' => 'required|exists:shops,id',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'spend_amount' => 'required|numeric|min:1',
                'points_awarded' => 'required|integer|min:1',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'rewards' => 'required|array|min:1',
                'rewards.*.points_required' => 'required|integer|min:1',
                'rewards.*.reward_type' => 'required|in:PRODUCT,DISCOUNT,VOUCHER,FREE_DELIVERY,CUSTOM',
                'rewards.*.reward_value' => 'nullable|string|max:255',
                'rewards.*.name' => 'required|string|max:255',
            ]);

            // Optional: enforce only 1 active challenge per store
            Challenge::where('shop_id', $validated['shop_id'])->update(['is_active' => false]);

            DB::beginTransaction();

            $challenge = Challenge::create([
                'shop_id' => $validated['shop_id'],
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'spend_amount' => $validated['spend_amount'],
                'points_awarded' => $validated['points_awarded'],
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'is_active' => true,
            ]);

            foreach ($validated['rewards'] as $rewardData) {
                ChallengeReward::create([
                    'challenge_id' => $challenge->id,
                    'points_required' => $rewardData['points_required'],
                    'reward_type' => $rewardData['reward_type'],
                    'reward_value' => $rewardData['reward_value'] ?? null,
                    'name' => $rewardData['name'],
                ]);
            }

            DB::commit();

            return $this->success('Challenge created successfully', $challenge->load('rewards'), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/seller/challenges
     */
    public function getSellerChallenges(Request $request)
    {
        try {
            $shopId = $request->query('shop_id');
            if (!$shopId) {
                return $this->failed('shop_id query parameter is required', null, 400);
            }

            $challenges = Challenge::where('shop_id', $shopId)
                ->with('rewards')
                ->latest()
                ->get();

            return $this->success('Challenges retrieved', $challenges);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/seller/challenges/{id}/participants
     */
    public function getChallengeLeaderboard($id, Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $participants = ChallengeParticipant::where('challenge_id', $id)
                ->with('user')
                ->orderByDesc('current_points')
                ->paginate($perPage);

            return $this->success('Leaderboard retrieved', $participants);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    // --- CUSTOMER APIs ---

    /**
     * GET /api/customer/shops/{store_slug}/challenge
     */
    public function getActiveChallenge($storeSlug, Request $request)
    {
        try {
            $shop = Shops::where('slug', $storeSlug)->first();
            if (!$shop) {
                return $this->failed('Shop not found', null, 404);
            }

            $challenge = Challenge::where('shop_id', $shop->id)
                ->where('is_active', true)
                ->where(function ($query) {
                    $now = now();
                    $query->whereNull('start_date')->orWhere('start_date', '<=', $now);
                })
                ->where(function ($query) {
                    $now = now();
                    $query->whereNull('end_date')->orWhere('end_date', '>=', $now);
                })
                ->with('rewards')
                ->first();

            if (!$challenge) {
                return $this->success('No active challenge for this shop', null);
            }

            return $this->success('Active challenge found', $challenge);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/customer/challenges/{id}/join
     */
    public function joinChallenge(Request $request, $id)
    {
        try {
            $userId = $request->input('user_id'); // Or from Auth token
            if (!$userId) {
                return $this->failed('user_id is required', null, 400);
            }

            $challenge = Challenge::findOrFail($id);

            $participant = ChallengeParticipant::firstOrCreate(
                ['challenge_id' => $challenge->id, 'user_id' => $userId],
                ['current_points' => 0]
            );

            return $this->success('Joined challenge successfully', $participant);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/customer/challenges/my
     */
    public function getMyChallenges(Request $request)
    {
        try {
            $userId = $request->query('user_id'); // Or auth()->id()
            if (!$userId) {
                return $this->failed('user_id is required', null, 400);
            }

            $participants = ChallengeParticipant::where('user_id', $userId)
                ->with(['challenge.shop', 'challenge.rewards'])
                ->get();

            // Format response to include claim statuses and progress
            $data = $participants->map(function ($participant) {
                $claims = RewardClaim::where('challenge_participant_id', $participant->id)->get()->keyBy('challenge_reward_id');
                
                $formattedRewards = $participant->challenge->rewards->map(function ($reward) use ($claims) {
                    $claim = $claims->get($reward->id);
                    return [
                        'id' => $reward->id,
                        'name' => $reward->name,
                        'points_required' => $reward->points_required,
                        'reward_type' => $reward->reward_type,
                        'reward_value' => $reward->reward_value,
                        'status' => $claim ? $claim->status : 'locked',
                        'claim_id' => $claim ? $claim->id : null,
                    ];
                });

                $targetPoints = $participant->challenge->rewards->max('points_required') ?? 0;
                $currentPoints = $participant->current_points;
                $remainingPoints = max(0, $targetPoints - $currentPoints);
                $progressPercentage = $targetPoints > 0 ? min(100, round(($currentPoints / $targetPoints) * 100)) : 100;

                return [
                    'challenge_id' => $participant->challenge->id,
                    'shop_name' => $participant->challenge->shop->name ?? $participant->challenge->shop->shop_name,
                    'shop_slug' => $participant->challenge->shop->slug,
                    'title' => $participant->challenge->title,
                    'current_points' => $currentPoints,
                    'target_points' => $targetPoints,
                    'remaining_points' => $remainingPoints,
                    'progress_percentage' => $progressPercentage,
                    'status' => $participant->challenge->is_active ? 'active' : 'inactive',
                    'rewards' => $formattedRewards,
                ];
            });

            return $this->success('My challenges retrieved', $data);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/customer/rewards/{claimId}/claim
     */
    public function claimReward(Request $request, $claimId)
    {
        try {
            $claim = RewardClaim::with(['participant', 'reward'])->findOrFail($claimId);

            // Add authorization check here if using Auth
            $userId = $request->input('user_id'); 
            if ($userId && $claim->participant->user_id != $userId) {
                return $this->failed('Unauthorized', null, 403);
            }

            if ($claim->status !== 'unlocked') {
                return $this->failed('Reward cannot be claimed. Status is: ' . $claim->status, null, 400);
            }

            $claim->status = 'claimed';
            $claim->save();

            // Here you could generate a coupon code, etc. depending on the reward type.
            
            return $this->success('Reward claimed successfully', $claim);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/seller/challenges/{id}/statistics
     */
    public function getChallengeStatistics($id)
    {
        try {
            $challenge = Challenge::findOrFail($id);

            $totalParticipants = ChallengeParticipant::where('challenge_id', $id)->count();
            $totalPointsIssued = \App\Models\PointTransaction::whereHas('participant', function($q) use ($id) {
                $q->where('challenge_id', $id);
            })->where('type', 'earned')->sum('points');

            $rewardStats = RewardClaim::whereHas('participant', function($q) use ($id) {
                $q->where('challenge_id', $id);
            })->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');

            return $this->success('Challenge statistics retrieved', [
                'total_participants' => $totalParticipants,
                'total_points_issued' => $totalPointsIssued,
                'rewards_unlocked' => ($rewardStats['unlocked'] ?? 0) + ($rewardStats['claimed'] ?? 0) + ($rewardStats['redeemed'] ?? 0),
                'rewards_claimed' => ($rewardStats['claimed'] ?? 0) + ($rewardStats['redeemed'] ?? 0),
                'rewards_redeemed' => $rewardStats['redeemed'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
