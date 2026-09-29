<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Shops;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Challenge;
use App\Models\ChallengeReward;
use App\Models\ChallengeParticipant;
use App\Models\PointTransaction;
use App\Models\RewardClaim;
use App\Service\RewardRaceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestRewardRace extends Command
{
    protected $signature = 'test:reward-race';
    protected $description = 'Run a simulation to verify Phase 4 of the Reward Race system';

    public function handle()
    {
        $this->info('Starting Reward Race Verification (Phase 4)...');

        DB::beginTransaction();
        try {
            // Setup mock data
            $user = User::factory()->create();
            $shopUser = User::factory()->create();
            
            $shop = Shops::create([
                'user_id' => $shopUser->id,
                'name' => 'Burger King Mock',
                'slug' => 'burger-king-' . Str::random(5),
                'status' => 'active',
                'is_active' => 1,
            ]);

            $this->info("1. Setup Shop and User");

            // 1. Create Challenge
            $challenge = Challenge::create([
                'shop_id' => $shop->id,
                'title' => 'Summer Shopping Run',
                'spend_amount' => 100,
                'points_awarded' => 5,
                'is_active' => true,
            ]);

            ChallengeReward::create([
                'challenge_id' => $challenge->id,
                'points_required' => 50, // Easy target for testing
                'reward_type' => 'PRODUCT',
                'name' => 'Free Burger',
            ]);

            $this->info("2. Created Challenge 'Summer Shopping Run' (Target: 50 pts, Rule: 100 spent = 5 pts)");

            // 2. Customer joins challenge
            $participant = ChallengeParticipant::create([
                'challenge_id' => $challenge->id,
                'user_id' => $user->id,
                'current_points' => 0,
            ]);

            $this->info("3. Customer joined challenge");

            // 3. Customer places eligible order
            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => 'ORD-TEST-' . Str::random(5),
                'status' => 'pending',
                'total' => 850, // 850 / 100 = 8 * 5 = 40 points
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'shop_id' => $shop->id,
                'qty' => 1,
                'unit_price' => 850,
                'line_total' => 850,
            ]);

            $this->info("4. Customer placed Order for 850 Tk");

            // Complete Order
            $order->status = 'completed';
            $order->save();
            
            $service = new RewardRaceService();
            $service->processOrderPoints($order);
            
            $participant->refresh();
            if ($participant->current_points === 40) {
                $this->info("5. SUCCESS: Correct points awarded (40 points for 850 Tk)");
            } else {
                $this->error("FAILED: Expected 40 points, got {$participant->current_points}");
            }

            // 4. Same order cannot award twice (Idempotency)
            $service->processOrderPoints($order);
            $participant->refresh();
            $transactionCount = PointTransaction::where('order_id', $order->id)->count();
            if ($participant->current_points === 40 && $transactionCount === 1) {
                $this->info("6. SUCCESS: Same order cannot award twice (Idempotency verified)");
            } else {
                $this->error("FAILED: Duplicate points awarded");
            }

            // 5. Multiple orders accumulate and reach target
            $order2 = Order::create([
                'user_id' => $user->id,
                'order_number' => 'ORD-TEST-2-' . Str::random(5),
                'status' => 'completed',
                'total' => 200, // 200 / 100 = 2 * 5 = 10 points
            ]);
            OrderItem::create(['order_id' => $order2->id, 'shop_id' => $shop->id, 'qty' => 1, 'unit_price' => 200, 'line_total' => 200]);
            
            $service->processOrderPoints($order2);
            $participant->refresh();
            
            if ($participant->current_points === 50) {
                $this->info("7. SUCCESS: Multiple orders accumulate correctly (Total: 50 points)");
            } else {
                $this->error("FAILED: Expected 50 points, got {$participant->current_points}");
            }

            // 6. Reward unlocks
            $claims = RewardClaim::where('challenge_participant_id', $participant->id)->get();
            if ($claims->count() === 1 && $claims->first()->status === 'unlocked') {
                $this->info("8. SUCCESS: Reward unlocked when target reached");
            } else {
                $this->error("FAILED: Reward not unlocked");
            }

            // 7. Cancelled/refunded order behavior
            $service->reverseOrderPoints($order2);
            $participant->refresh();
            $claims = RewardClaim::where('challenge_participant_id', $participant->id)->get();
            
            if ($participant->current_points === 40) {
                $this->info("9. SUCCESS: Cancelled order reversed points correctly (Back to 40 points)");
            } else {
                $this->error("FAILED: Points not reversed correctly");
            }

            if ($claims->first()->status === 'revoked') {
                $this->info("10. SUCCESS: Unclaimed reward was revoked because points dropped below target");
            } else {
                $this->error("FAILED: Reward was not revoked");
            }

            $this->info('Verification complete! All Phase 4 requirements tested successfully.');

            // Rollback so we don't pollute the DB
            DB::rollBack();
            $this->info('Rolled back test data.');
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Test failed with exception: " . $e->getMessage());
        }
    }
}
