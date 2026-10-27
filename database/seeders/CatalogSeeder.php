<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use App\Services\Commerce\InventoryService;
use App\Services\Commerce\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (Product::query()->where('slug', 'matcha-de-jeju')->exists()) {
            return;
        }

        $actor = User::query()->where('email', 'admin@example.com')->first();
        $catalog = app(ProductService::class);
        $inventory = app(InventoryService::class);

        $menu = [
            ['name' => 'Fresa de leche', 'slug' => 'fresa-de-leche', 'price' => 12000, 'file' => 'fresa.jpg', 'short' => 'Soft-serve de fresa en cono taiyaki.', 'ingredients' => 'Leche, fresa, azúcar.', 'featured' => true, 'new' => false, 'seasonal' => false, 'container' => 'cone'],
            ['name' => 'Matcha de Jeju', 'slug' => 'matcha-de-jeju', 'price' => 18000, 'file' => 'matcha.jpg', 'short' => 'Té verde suave con base de leche.', 'ingredients' => 'Leche, matcha, azúcar.', 'featured' => true, 'new' => false, 'seasonal' => false, 'container' => 'cone'],
            ['name' => 'Chocolate Seúl', 'slug' => 'chocolate-seul', 'price' => 17000, 'file' => 'chocolate.jpg', 'short' => 'Cacao intenso sobre cono caliente.', 'ingredients' => 'Leche, cacao, azúcar.', 'featured' => true, 'new' => false, 'seasonal' => false, 'container' => 'cone'],
            ['name' => 'Ube Milk', 'slug' => 'ube-milk', 'price' => 19000, 'file' => 'ube.jpg', 'short' => 'Camote morado, leche y vainilla.', 'ingredients' => 'Leche, ube, azúcar.', 'featured' => false, 'new' => true, 'seasonal' => false, 'container' => 'cone'],
            ['name' => 'Mango Yogurt', 'slug' => 'mango-yogurt', 'price' => 16000, 'file' => 'mango.jpg', 'short' => 'Mango y yogur, frío y ácido.', 'ingredients' => 'Yogur, mango, azúcar.', 'featured' => false, 'new' => false, 'seasonal' => true, 'container' => 'cone'],
            ['name' => 'Sésamo negro', 'slug' => 'sesamo-negro', 'price' => 18000, 'file' => 'sesame.jpg', 'short' => 'Sésamo tostado y leche.', 'ingredients' => 'Leche, sésamo negro, azúcar.', 'featured' => false, 'new' => true, 'seasonal' => false, 'container' => 'cone'],
        ];

        foreach ($menu as $item) {
            $product = $catalog->create([
                'name' => $item['name'],
                'slug' => $item['slug'],
                'short_description' => $item['short'],
                'ingredients_text' => $item['ingredients'],
                'is_active' => true,
                'is_featured' => $item['featured'],
                'is_new' => $item['new'],
                'is_seasonal' => $item['seasonal'],
                'is_demonstration' => false,
                'variants' => [[
                    'name' => 'Copa',
                    'price' => $item['price'],
                    'portions' => 1,
                    'container' => $item['container'],
                    'is_active' => true,
                ]],
            ], $actor);

            $inventory->receiveFinished($product->id, 12, [
                'user_id' => $actor?->id,
                'reason' => 'Carga inicial de la carta',
                'idempotency_key' => 'carta-'.$item['slug'],
            ]);

            $path = database_path('seeders/catalog/'.$item['file']);
            if (is_file($path)) {
                $catalog->storeImage(
                    $product,
                    new UploadedFile($path, $item['file'], 'image/jpeg', null, true),
                    $actor
                );
            }
        }
    }
}
