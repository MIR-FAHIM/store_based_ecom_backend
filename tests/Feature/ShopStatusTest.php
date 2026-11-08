<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Shops;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_status_changes_persist_and_control_public_storefront_access(): void
    {
        $shop = Shops::create(['slug' => 'store_status_test', 'status' => 'pending']);
        $adminToken = $this->tokenFor(User::factory()->create(['user_type' => 'admin']));
        $availabilityUrl = "/api/public/stores/{$shop->slug}/availability";

        $this->getJson($availabilityUrl)->assertNotFound();

        foreach (['active', 'inactive', 'suspended', 'active'] as $status) {
            $this->withToken($adminToken)->patchJson("/api/shops/status/{$shop->id}", ['status' => $status])
                ->assertOk()->assertJsonPath('data.status', $status);
            $this->assertDatabaseHas('shops', ['id' => $shop->id, 'status' => $status]);
            $this->getJson("/api/shops/details/{$shop->id}")->assertOk()->assertJsonPath('data.status', $status);
            $this->getJson("/api/shops/list?search={$shop->slug}")->assertOk()->assertJsonPath('data.data.0.status', $status);

            $availability = $this->withHeaders(['Authorization' => ''])->getJson($availabilityUrl);
            $this->assertStringContainsString('no-store', $availability->headers->get('Cache-Control'));
            if ($status === 'active') {
                $availability->assertOk()->assertJsonPath('data.available', true);
            } else {
                $availability->assertNotFound();
                $this->getJson("/api/products/details/test-item?store_slug={$shop->slug}")->assertNotFound();
            }
        }

        $this->withToken($adminToken)->patchJson("/api/shops/status/{$shop->id}", ['status' => 'invalid'])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['status']]);
        $this->assertDatabaseHas('shops', ['id' => $shop->id, 'status' => 'active']);
    }

    public function test_seller_cannot_reactivate_a_suspended_shop_through_either_endpoint(): void
    {
        $seller = User::factory()->create(['user_type' => 'seller']);
        $shop = Shops::create(['user_id' => $seller->id, 'slug' => 'suspended-store', 'status' => 'suspended']);
        $this->withToken($this->tokenFor($seller));

        $this->patchJson("/api/shops/status/{$shop->id}", ['status' => 'active'])->assertForbidden();
        $this->postJson("/api/shops/update/{$shop->id}", ['status' => 'active'])->assertForbidden();
        $this->postJson("/api/shops/update/{$shop->id}", ['shop_name' => 'Updated shop'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertDatabaseHas('shops', ['id' => $shop->id, 'status' => 'suspended', 'shop_name' => 'Updated shop']);
    }

    public function test_checkout_rejects_a_cart_created_before_the_shop_was_suspended(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer']);
        $seller = User::factory()->create(['user_type' => 'seller']);
        $shop = Shops::create(['user_id' => $seller->id, 'slug' => 'checkout-shop', 'status' => 'active']);
        $product = Product::create([
            'name' => 'Test item',
            'slug' => 'test-item',
            'added_by' => 'seller',
            'user_id' => $seller->id,
            'shop_id' => $shop->id,
            'category_id' => 1,
            'unit_price' => 100,
        ]);
        $cart = Cart::create(['user_id' => $customer->id, 'shop_id' => $shop->id, 'status' => 'active']);
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'shop_id' => $shop->id,
            'qty' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);
        $shop->update(['status' => 'suspended']);

        $this->withToken($this->tokenFor($customer))->postJson('/api/orders/checkout', ['user_id' => $customer->id])
            ->assertConflict()->assertJsonPath('message', 'A store in this cart is currently unavailable');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('carts', ['id' => $cart->id, 'status' => 'active']);
        $this->assertDatabaseHas('cart_items', ['id' => $item->id]);
    }

    private function tokenFor(User $user): string
    {
        $token = "shop-status-test-{$user->id}";
        ApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'name' => 'Shop status test',
        ]);

        return $token;
    }
}
