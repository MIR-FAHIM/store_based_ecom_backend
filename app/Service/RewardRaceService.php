<?php

namespace App\Service;

use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengeReward;
use App\Models\Order;
use App\Models\PointTransaction;
use App\Models\RewardClaim;
use App\Models\Shops;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RewardRaceService
{
    /**
     * Process points for an order that has just been completed.
     */
    public function processOrderPoints(Order $order): void
    {
        try {
            $shopId = $this->getShopIdFromOrder($order);
            if (!$shopId) {
                return;
            }

            // Check for an active challenge for this shop
            $challenge = Challenge::where('shop_id', $shopId)
                ->where('is_active', true)
                ->where(function ($query) {
                    $now = now();
                    $query->whereNull('start_date')->orWhere('start_date', '<=', $now);
                })
                ->where(function ($query) {
                    $now = now();
                    $query->whereNull('end_date')->orWhere('end_date', '>=', $now);
                })
                ->first();

            if (!$challenge) {
                return;
            }

            // Get or create participant
            $participant = ChallengeParticipant::firstOrCreate(
                ['challenge_id' => $challenge->id, 'user_id' => $order->user_id],
                ['current_points' => 0]
            );

            // Idempotency: ensure we haven't already awarded points for this order
            $existingTransaction = PointTransaction::where('challenge_participant_id', $participant->id)
                ->where('order_id', $order->id)
                ->where('type', 'earned')
                ->exists();

            if ($existingTransaction) {
                return;
            }

            // Calculate eligible amount (we'll use order subtotal minus discount to be fair)
            // Or total, depending on business rules. Let's use `total` for simplicity.
            $eligibleAmount = max(0, $order->total);

            if ($challenge->spend_amount > 0) {
                $multiplier = floor($eligibleAmount / (float) $challenge->spend_amount);
                $pointsEarned = (int) ($multiplier * $challenge->points_awarded);

                if ($pointsEarned > 0) {
                    DB::transaction(function () use ($participant, $order, $pointsEarned, $challenge) {
                        PointTransaction::create([
                            'challenge_participant_id' => $participant->id,
                            'order_id' => $order->id,
                            'points' => $pointsEarned,
                            'type' => 'earned',
                            'description' => "Earned points for order #" . $order->order_number,
                        ]);

                        $participant->current_points += $pointsEarned;
                        $participant->save();

                        $this->checkAndUnlockRewards($participant, $challenge);
                    });
                }
            }

        } catch (\Throwable $e) {
            Log::error('RewardRaceService processOrderPoints Error', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Reverse points if an order is cancelled or refunded
     */
    public function reverseOrderPoints(Order $order): void
    {
        try {
            $shopId = $this->getShopIdFromOrder($order);
            if (!$shopId) {
                return;
            }

            // Find if this order had points earned
            $earnedTransaction = PointTransaction::where('order_id', $order->id)
                ->where('type', 'earned')
                ->first();

            if (!$earnedTransaction) {
                return;
            }

            $participant = ChallengeParticipant::find($earnedTransaction->challenge_participant_id);
            if (!$participant) {
                return;
            }

            // Check if already reversed
            $alreadyReversed = PointTransaction::where('order_id', $order->id)
                ->where('type', 'reversed')
                ->exists();

            if ($alreadyReversed) {
                return;
            }

            DB::transaction(function () use ($participant, $order, $earnedTransaction) {
                PointTransaction::create([
                    'challenge_participant_id' => $participant->id,
                    'order_id' => $order->id,
                    'points' => -$earnedTransaction->points,
                    'type' => 'reversed',
                    'description' => "Reversed points for cancelled order #" . $order->order_number,
                ]);

                $participant->current_points -= $earnedTransaction->points;
                // Floor at 0 if you don't want negative balances
                // $participant->current_points = max(0, $participant->current_points);
                $participant->save();

                $this->revokeRewardsIfBelowThreshold($participant);
            });

        } catch (\Throwable $e) {
            Log::error('RewardRaceService reverseOrderPoints Error', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Helper to unlock rewards when points threshold is crossed
     */
    private function checkAndUnlockRewards(ChallengeParticipant $participant, Challenge $challenge): void
    {
        $rewards = ChallengeReward::where('challenge_id', $challenge->id)
            ->where('points_required', '<=', $participant->current_points)
            ->get();

        foreach ($rewards as $reward) {
            RewardClaim::firstOrCreate(
                [
                    'challenge_participant_id' => $participant->id,
                    'challenge_reward_id' => $reward->id
                ],
                [
                    'status' => 'unlocked'
                ]
            );
        }
    }

    /**
     * Helper to revoke unlocked (but not claimed/redeemed) rewards if balance drops
     */
    private function revokeRewardsIfBelowThreshold(ChallengeParticipant $participant): void
    {
        $claims = RewardClaim::with('reward')
            ->where('challenge_participant_id', $participant->id)
            ->where('status', 'unlocked')
            ->get();

        foreach ($claims as $claim) {
            if ($participant->current_points < $claim->reward->points_required) {
                $claim->status = 'revoked';
                $claim->save();
            }
        }
    }

    /**
     * Resolves the shop_id from the first item of an order
     */
    private function getShopIdFromOrder(Order $order): ?int
    {
        $order->loadMissing('items');
        $firstItem = $order->items->first();
        return $firstItem ? $firstItem->shop_id : null;
    }
}
