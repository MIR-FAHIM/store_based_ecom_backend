<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shops;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_reopens_same_shop_chat_without_duplicate_conversation(): void
    {
        [$customer, $token] = $this->userWithToken('customer');
        $shop = $this->shopFor($this->userWithToken('seller')[0]);

        $this->withToken($token)->postJson('/api/chat/conversations', [
            'shop_id' => $shop->id,
        ])->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $this->withToken($token)->postJson('/api/chat/conversations', [
            'shop_id' => $shop->id,
        ])->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseHas('conversations', [
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_customer_can_send_product_and_order_messages_then_shop_marks_read(): void
    {
        [$customer, $customerToken] = $this->userWithToken('customer');
        [$seller, $sellerToken] = $this->userWithToken('seller');
        $shop = $this->shopFor($seller);
        $product = $this->productFor($shop, $seller);
        $order = $this->orderFor($customer, $shop, $product);

        $conversationId = $this->withToken($customerToken)->postJson('/api/chat/conversations', [
            'shop_id' => $shop->id,
        ])->json('data.id');

        $this->withToken($customerToken)->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'message_type' => 'product',
            'product_id' => $product->id,
            'message' => 'I am interested in this product',
        ])->assertStatus(201)
            ->assertJsonPath('data.message_type', 'product')
            ->assertJsonPath('data.product.id', $product->id);

        $this->withToken($customerToken)->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'message_type' => 'order',
            'order_id' => $order->id,
            'message' => 'I want to discuss this order',
        ])->assertStatus(201)
            ->assertJsonPath('data.message_type', 'order')
            ->assertJsonPath('data.order.id', $order->id);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversationId,
            'shop_unread_count' => 2,
        ]);

        $this->withToken($sellerToken)->postJson("/api/chat/conversations/{$conversationId}/read")
            ->assertStatus(200)
            ->assertJsonPath('data.shop_unread_count', 0);

        $this->assertDatabaseMissing('conversation_messages', [
            'conversation_id' => $conversationId,
            'sender_type' => 'customer',
            'is_read' => 0,
        ]);
    }

    public function test_customer_cannot_share_another_customer_order(): void
    {
        [$customer, $customerToken] = $this->userWithToken('customer');
        [$otherCustomer] = $this->userWithToken('customer');
        [$seller] = $this->userWithToken('seller');
        $shop = $this->shopFor($seller);
        $product = $this->productFor($shop, $seller);
        $order = $this->orderFor($otherCustomer, $shop, $product);

        $conversationId = $this->withToken($customerToken)->postJson('/api/chat/conversations', [
            'shop_id' => $shop->id,
        ])->json('data.id');

        $this->withToken($customerToken)->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'message_type' => 'order',
            'order_id' => $order->id,
        ])->assertStatus(422)
            ->assertJsonPath('status', 'failed');
    }

    private function userWithToken(string $userType): array
    {
        $user = User::factory()->create([
            'user_type' => $userType,
        ]);

        $plainToken = 'token-' . $userType . '-' . $user->id . '-' . uniqid();

        ApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'name' => 'Test token',
            'scopes' => ['*'],
        ]);

        return [$user, $plainToken];
    }

    private function shopFor(User $seller): Shops
    {
        return Shops::create([
            'user_id' => $seller->id,
            'name' => 'Simple Lifestyle',
            'shop_name' => 'Simple Lifestyle',
            'slug' => 'simple-lifestyle-' . $seller->id,
            'status' => 'active',
        ]);
    }

    private function productFor(Shops $shop, User $seller): Product
    {
        return Product::create([
            'name' => 'Cotton Panjabi',
            'added_by' => 'seller',
            'user_id' => $seller->id,
            'shop_id' => $shop->id,
            'category_id' => 1,
            'unit_price' => 1770,
            'slug' => 'cotton-panjabi-' . $shop->id,
        ]);
    }

    private function orderFor(User $customer, Shops $shop, Product $product): Order
    {
        $order = Order::create([
            'user_id' => $customer->id,
            'order_number' => 'MYZ' . $customer->id . $shop->id . $product->id,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'total' => 1770,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'shop_id' => $shop->id,
            'product_name' => $product->name,
            'unit_price' => 1770,
            'qty' => 1,
            'line_total' => 1770,
            'status' => 'pending',
        ]);

        return $order;
    }
}
