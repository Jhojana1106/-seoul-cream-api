<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoulCreamCommerceTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->token = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->json('data.access_token');
    }

    public function test_checkout_uses_server_prices_and_stock_and_rejects_duplicates(): void
    {
        $category = $this->auth()->postJson('/api/v1/categories', [
            'name' => 'Firma',
        ])->assertCreated()->json('data.id');

        $productId = $this->auth()->postJson('/api/v1/products', [
            'name' => 'Matcha Cream',
            'category_id' => $category,
            'variants' => [[
                'name' => 'Copa',
                'price' => 10000,
                'portions' => 1,
                'container' => 'cup',
            ]],
        ])->assertCreated()->json('data.id');

        $variantId = $this->auth()->getJson("/api/v1/products/{$productId}")->json('data.variants.0.id');

        $toppingId = $this->auth()->postJson('/api/v1/toppings', [
            'name' => 'Sésamo',
            'price' => 2000,
        ])->assertCreated()->json('data.id');

        $paymentId = PaymentMethod::query()->where('code', 'pickup_cash')->value('id');
        $payload = [
            'items' => [[
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => 1,
                'toppings' => [['id' => $toppingId, 'quantity' => 1]],
            ]],
            'fulfillment' => 'pickup',
            'payment_method_id' => $paymentId,
            'customer_name' => 'Ana',
            'customer_email' => 'ana@example.com',
            'customer_phone' => '3000000000',
        ];

        $this->postJson('/api/v1/store/checkout', $payload)
            ->assertStatus(422);

        $this->auth()->postJson('/api/v1/inventory/movements', [
            'item_type' => 'finished',
            'item_id' => $productId,
            'direction' => 'in',
            'quantity' => 1,
            'reason' => 'Preparación del día',
        ])->assertCreated();

        $order = $this->withHeader('Idempotency-Key', 'checkout-1')
            ->postJson('/api/v1/store/checkout', $payload);

        $order->assertCreated()
            ->assertJsonPath('data.total', '12000.00')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame('0.000', (string) Product::query()->find($productId)->warehouse_portions);

        $this->withHeader('Idempotency-Key', 'checkout-1')
            ->postJson('/api/v1/store/checkout', $payload)
            ->assertCreated()
            ->assertJsonPath('data.number', $order->json('data.number'));

        $this->assertSame('0.000', (string) Product::query()->find($productId)->warehouse_portions);

        $this->auth()->postJson('/api/v1/orders/'.$order->json('data.id').'/status', [
            'status' => 'cancelled',
            'note' => 'Cliente desistió',
        ])->assertOk();

        $this->assertSame('1.000', (string) Product::query()->find($productId)->warehouse_portions);

        $this->auth()->postJson('/api/v1/orders/'.$order->json('data.id').'/status', [
            'status' => 'preparing',
        ])->assertStatus(422);
    }

    public function test_demonstration_flavor_cannot_be_sold_and_customer_cannot_open_admin_catalog(): void
    {
        $this->auth()->postJson('/api/v1/products/examples')->assertOk();

        $example = Product::query()->where('is_demonstration', true)->firstOrFail();
        $variantId = $example->variants()->value('id');
        $paymentId = PaymentMethod::query()->where('code', 'pickup_cash')->value('id');

        $this->postJson('/api/v1/store/checkout', [
            'items' => [[
                'product_id' => $example->id,
                'variant_id' => $variantId,
                'quantity' => 1,
            ]],
            'fulfillment' => 'pickup',
            'payment_method_id' => $paymentId,
            'customer_name' => 'Ana',
            'customer_email' => 'ana@example.com',
            'customer_phone' => '3000000000',
        ])->assertStatus(422);

        $this->getJson('/api/v1/store/products')
            ->assertOk()
            ->assertJsonPath('data', []);

        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$register->json('data.access_token'))
            ->getJson('/api/v1/products')
            ->assertForbidden();
    }

    public function test_transfer_moves_portions_without_changing_total_availability(): void
    {
        $productId = $this->auth()->postJson('/api/v1/products', [
            'name' => 'Ube Milk',
            'variants' => [[
                'name' => 'Copa',
                'price' => 15000,
                'portions' => 1,
                'container' => 'cup',
            ]],
        ])->json('data.id');

        $this->auth()->postJson('/api/v1/inventory/movements', [
            'item_type' => 'finished',
            'item_id' => $productId,
            'direction' => 'in',
            'quantity' => 4,
            'reason' => 'Producción manual auditada',
        ])->assertCreated();

        $caseId = $this->auth()->postJson('/api/v1/display-cases', [
            'name' => 'Barra',
            'code' => 'BARRA-1',
            'capacity_portions' => 10,
            'min_threshold_portions' => 1,
        ])->assertCreated()->json('data.id');

        $this->auth()->postJson('/api/v1/inventory/transfers', [
            'product_id' => $productId,
            'quantity' => 3,
            'from' => 'warehouse',
            'to' => 'display_case',
            'to_id' => $caseId,
            'reason' => 'Reposición de vitrina',
        ])->assertCreated();

        $product = Product::query()->withSum('displayCaseItems as case_portions', 'quantity')->find($productId);
        $this->assertSame('1.000', (string) $product->warehouse_portions);
        $this->assertSame('3.000', number_format((float) $product->case_portions, 3, '.', ''));
        $this->assertSame('4.000', $product->availablePortions());

        $this->auth()->postJson('/api/v1/inventory/transfers', [
            'product_id' => $productId,
            'quantity' => 5,
            'from' => 'warehouse',
            'to' => 'display_case',
            'to_id' => $caseId,
            'reason' => 'Intento por encima del almacén',
        ])->assertStatus(422);
    }

    private function auth(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }
}
