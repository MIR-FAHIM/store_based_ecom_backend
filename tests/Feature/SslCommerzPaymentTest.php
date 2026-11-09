<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\MediaResourceOrder;
use App\Models\Order;
use App\Models\Shops;
use App\Models\SubscriptionPackage;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SslCommerzPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_01_06_075339_create_shops_table.php',
            '2026_01_06_113927_create_orders_table.php',
            '2026_01_06_113937_create_order_items_table.php',
            '2026_01_14_043751_create_api_tokens_table.php',
            '2026_01_25_120420_create_transactions_table.php',
            '2026_06_29_174110_create_online_payments_table.php',
            '2026_06_30_000001_add_payment_group_fields_to_orders_and_online_payments.php',
            '2026_08_16_000001_create_subscription_packages_table.php',
            '2026_08_16_000002_create_store_subscriptions_table.php',
            '2026_08_23_000002_create_media_marketplace_tables.php',
            '2026_09_01_000001_add_code_to_shops_table.php',
            '2026_09_08_000001_add_product_limit_to_shops_table.php',
            '2026_09_21_112742_add_baki_fields_to_customer_preferences_and_orders_tables.php',
            '2026_09_22_105000_add_payment_method_and_order_type_to_orders_table.php',
            '2026_09_28_000002_add_must_buy_package_to_users_table.php',
        ] as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }

        // The production migration for these columns uses MySQL ALTER TABLE syntax.
        Schema::table('online_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->change();
            $table->string('payment_type')->default('order');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('store_subscription_id')->nullable();
        });

        config([
            'services.payment_gateway' => 'sslcommerz',
            'services.sslcommerz.sandbox' => true,
            'services.sslcommerz.store_id' => 'test-store',
            'services.sslcommerz.store_password' => 'test-password',
            'services.sslcommerz.callback_base_url' => 'https://staging.example.com',
        ]);
        Http::preventStrayRequests();
    }

    public function test_paid_subscription_appears_in_admin_report_and_ipn_is_idempotent(): void
    {
        $seller = User::factory()->create(['user_type' => 'seller']);
        $admin = User::factory()->create(['user_type' => 'admin']);
        $store = Shops::create(['user_id' => $seller->id, 'slug' => 'ssl-test-shop', 'status' => 'active']);
        $package = SubscriptionPackage::create([
            'name' => 'Test package',
            'slug' => 'ssl-test-package',
            'price' => 250,
            'billing_cycle' => 'monthly',
            'max_products' => 80,
            'status' => 'active',
        ]);

        $this->fakeGateway(250);
        $attempt = $this->withToken($this->token($seller))
            ->postJson("/api/stores/{$store->id}/subscription/subscribe", [
                'subscription_package_id' => $package->id,
                'billing_cycle' => 'monthly',
            ])->assertSuccessful()->json('data');

        $this->assertSame('https://sandbox.sslcommerz.com/test-checkout', $attempt['payment_url']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/gwprocess/v4/api.php')
            && $request['total_amount'] === '250.00'
            && $request['ipn_url'] === 'https://staging.example.com/api/payments/sslcommerz/ipn');

        $this->notify($attempt['merchant_transaction_id'])->assertOk();
        $this->notify($attempt['merchant_transaction_id'])->assertOk();

        $this->assertDatabaseCount('store_subscriptions', 1);
        $this->assertDatabaseHas('shops', ['id' => $store->id, 'product_limit' => 80]);
        $this->withToken($this->token($admin))->getJson('/api/reports/subscriptions?payment_status=completed')
            ->assertOk()
            ->assertJsonPath('data.subscriptions.data.0.gateway', 'sslcommerz')
            ->assertJsonPath('data.subscriptions.data.0.payment_status', 'success')
            ->assertJsonPath('data.subscriptions.data.0.package.max_products', 80);
    }

    public function test_wrong_amount_and_risk_flag_never_settle_an_order(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer']);
        $order = Order::create(['user_id' => $customer->id, 'total' => 250, 'payment_status' => 'unpaid']);
        $this->fakeGateway(249);
        $attempt = $this->withToken($this->token($customer))->postJson('/api/payments/initiate', ['order_id' => $order->id])
            ->assertOk()->json('data');

        $this->notify($attempt['merchant_transaction_id'])->assertUnprocessable();
        $this->fakeGateway(250, 1);
        $this->notify($attempt['merchant_transaction_id'])->assertOk();

        $this->getJson("/api/payments/online/{$attempt['payment_id']}/status")
            ->assertOk()->assertJsonPath('data.under_review', true);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'unpaid']);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_live_checkout_rejection_logs_safe_details_and_returns_payment_reference(): void
    {
        config(['services.sslcommerz.sandbox' => false]);
        Log::spy();
        Http::fake([
            'https://securepay.sslcommerz.com/gwprocess/v4/api.php' => Http::response([
                'status' => 'FAILED',
                'failedreason' => 'Invalid credentials: test-password',
            ]),
        ]);

        $customer = User::factory()->create(['user_type' => 'customer']);
        $order = Order::create(['user_id' => $customer->id, 'total' => 499, 'payment_status' => 'unpaid']);
        $response = $this->withToken($this->token($customer))
            ->postJson('/api/payments/initiate', ['order_id' => $order->id])
            ->assertStatus(502)
            ->assertJsonPath('message', 'SSLCOMMERZ could not start checkout. Please try again later.')
            ->assertJsonMissingPath('errors.failedreason');

        $this->assertDatabaseHas('online_payments', [
            'id' => $response->json('errors.payment_id'),
            'order_id' => $order->id,
            'gateway' => 'sslcommerz',
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'unpaid']);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) =>
            $message === 'SSLCOMMERZ session rejected'
            && $context['environment'] === 'live'
            && $context['gateway_status'] === 'FAILED'
            && $context['gateway_reason'] === 'Invalid credentials: [redacted]'
        );
    }

    #[DataProvider('checkoutHosts')]
    public function test_checkout_accepts_only_https_hosts_for_the_configured_environment(
        bool $sandbox,
        string $url,
        bool $accepted
    ): void {
        config(['services.sslcommerz.sandbox' => $sandbox]);
        $sessionHost = $sandbox ? 'sandbox.sslcommerz.com' : 'securepay.sslcommerz.com';
        Http::fake([
            "https://{$sessionHost}/gwprocess/v4/api.php" => Http::response([
                'status' => 'SUCCESS',
                'GatewayPageURL' => $url,
                'sessionkey' => 'test-session',
            ]),
        ]);

        $customer = User::factory()->create(['user_type' => 'customer']);
        $order = Order::create(['user_id' => $customer->id, 'total' => 11, 'payment_status' => 'unpaid']);
        $response = $this->withToken($this->token($customer))
            ->postJson('/api/payments/initiate', ['order_id' => $order->id]);

        if ($accepted) {
            $response->assertOk()->assertJsonPath('data.payment_url', $url);
        } else {
            $response->assertStatus(502)->assertJsonMissingPath('data.payment_url');
        }

        $this->assertDatabaseHas('online_payments', [
            'order_id' => $order->id,
            'status' => $accepted ? 'pending' : 'failed',
        ]);
    }

    public static function checkoutHosts(): array
    {
        return [
            'live hosted checkout' => [false, 'https://pay.sslcommerz.com/test-checkout', true],
            'live API host' => [false, 'https://securepay.sslcommerz.com/test-checkout', true],
            'live host in sandbox mode' => [true, 'https://pay.sslcommerz.com/test-checkout', false],
            'lookalike host' => [false, 'https://pay.sslcommerz.com.example.org/test-checkout', false],
            'insecure live URL' => [false, 'http://pay.sslcommerz.com/test-checkout', false],
        ];
    }

    public function test_grouped_checkout_credits_each_order_once(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer']);
        foreach ([100, 150] as $total) {
            Order::create([
                'user_id' => $customer->id,
                'payment_group_id' => 'test-group',
                'payment_status' => 'unpaid',
                'total' => $total,
            ]);
        }

        $this->fakeGateway(250);
        $attempt = $this->withToken($this->token($customer))
            ->postJson('/api/payments/initiate', ['payment_group_id' => 'test-group'])->assertOk()->json('data');
        $this->notify($attempt['merchant_transaction_id'])->assertOk();
        $this->notify($attempt['merchant_transaction_id'])->assertOk();

        $this->assertSame(2, Order::where('payment_method', 'sslcommerz')->where('payment_status', 'paid')->count());
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_paid_media_order_is_ready_for_design(): void
    {
        $seller = User::factory()->create(['user_type' => 'seller']);
        $store = Shops::create(['user_id' => $seller->id, 'slug' => 'ssl-media-shop', 'status' => 'active']);
        $order = MediaResourceOrder::create([
            'order_number' => 'MEDIA-TEST',
            'seller_id' => $seller->id,
            'store_id' => $store->id,
            'total' => 250,
        ]);

        $this->fakeGateway(250);
        $attempt = $this->withToken($this->token($seller))
            ->postJson("/api/seller/stores/{$store->id}/media-orders/{$order->id}/pay")
            ->assertSuccessful()->json('data');
        $this->notify($attempt['merchant_transaction_id'])->assertOk();

        $this->assertDatabaseHas('media_resource_orders', [
            'id' => $order->id,
            'status' => 'pending_design',
            'payment_status' => 'paid',
        ]);
    }

    private function fakeGateway(int $amount, int $risk = 0): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.sslcommerz.com/gwprocess/v4/api.php' => Http::response([
                'status' => 'SUCCESS',
                'GatewayPageURL' => 'https://sandbox.sslcommerz.com/test-checkout',
                'sessionkey' => 'test-session',
            ]),
            'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => fn () => Http::response([
                'status' => 'VALIDATED',
                'tran_id' => request()->input('tran_id'),
                'val_id' => 'test-validation',
                'amount' => $amount,
                'currency' => 'BDT',
                'risk_level' => $risk,
                'bank_tran_id' => 'test-bank-id',
            ]),
        ]);
    }

    private function notify(string $transactionId)
    {
        return $this->postJson('/api/payments/sslcommerz/ipn', [
            'status' => 'VALID',
            'tran_id' => $transactionId,
            'val_id' => 'test-validation',
        ]);
    }

    private function token(User $user): string
    {
        $token = 'ssl-test-' . $user->id;
        ApiToken::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'name' => 'SSL Test']);

        return $token;
    }
}
