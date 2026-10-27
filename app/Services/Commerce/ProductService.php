<?php

namespace App\Services\Commerce;

use App\Models\Allergen;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAudit;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function paginate(array $filters, bool $public): LengthAwarePaginator
    {
        $query = Product::query()
            ->with([
                'category',
                'allergens',
                'variants' => fn ($variants) => $public ? $variants->where('is_active', true) : $variants,
            ])
            ->withSum('displayCaseItems as case_portions', 'quantity')
            ->withMin(['variants as min_price' => fn ($variants) => $public ? $variants->where('is_active', true) : $variants], 'price');

        if ($public) {
            $query->where('is_active', true);
            $query->where('is_demonstration', ! empty($filters['include_examples']));
        } elseif (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['category'])) {
            $category = $filters['category'];
            $query->whereHas('category', function ($inner) use ($category) {
                $inner->where('slug', $category)->orWhere('id', $category);
            });
        }

        foreach (['featured' => 'is_featured', 'seasonal' => 'is_seasonal', 'new' => 'is_new'] as $filter => $column) {
            if (($filters[$filter] ?? null) === '1' || ($filters[$filter] ?? null) === 1 || ($filters[$filter] ?? null) === true) {
                $query->where($column, true);
            }
        }

        if (($filters['vegan'] ?? null) === '1') {
            $query->where('dietary_vegan', true);
        }
        if (($filters['gluten_free'] ?? null) === '1') {
            $query->where('dietary_gluten_free', true);
        }

        $stockSql = '(warehouse_portions + COALESCE((select sum(quantity) from display_case_items where display_case_items.product_id = products.id), 0))';
        if (($filters['availability'] ?? null) === 'in_stock') {
            $query->whereRaw($stockSql.' > 0');
        }
        if (($filters['availability'] ?? null) === 'out') {
            $query->whereRaw($stockSql.' <= 0');
        }

        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('min_price'),
            'price_desc' => $query->orderByDesc('min_price'),
            'popular' => $query->withCount(['orderItems as sales_count' => function ($items) {
                $items->whereHas('order', fn ($order) => $order->where('status', '!=', 'cancelled')->where('payment_status', 'paid'));
            }])->orderByDesc('sales_count'),
            default => $query->latest(),
        };

        $perPage = min(48, max(1, (int) ($filters['per_page'] ?? 12)));

        return $query->paginate($perPage);
    }

    public function findPublic(string $slug): Product
    {
        return Product::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['category', 'allergens', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->withSum('displayCaseItems as case_portions', 'quantity')
            ->firstOrFail();
    }

    public function find(int $id): Product
    {
        return Product::query()
            ->with(['category', 'allergens', 'variants', 'audits.user'])
            ->withSum('displayCaseItems as case_portions', 'quantity')
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor): Product
    {
        return DB::transaction(function () use ($data, $actor) {
            $product = Product::query()->create([
                'category_id' => $data['category_id'] ?? null,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['name']),
                'short_description' => $data['short_description'] ?? null,
                'description' => $data['description'] ?? null,
                'ingredients_text' => $data['ingredients_text'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_featured' => $data['is_featured'] ?? false,
                'is_new' => $data['is_new'] ?? false,
                'is_seasonal' => $data['is_seasonal'] ?? false,
                'season_label' => $data['season_label'] ?? null,
                'is_demonstration' => $data['is_demonstration'] ?? false,
                'dietary_vegan' => $data['dietary_vegan'] ?? null,
                'dietary_gluten_free' => $data['dietary_gluten_free'] ?? null,
                'nutrition' => $this->nutrition($data['nutrition'] ?? null),
                'low_stock_portions' => $data['low_stock_portions'] ?? null,
                'portion_unit' => $data['portion_unit'] ?? 'porcion',
                'warehouse_portions' => 0,
            ]);

            $this->syncVariants($product, $data['variants'] ?? []);
            $product->allergens()->sync($data['allergen_ids'] ?? []);
            $this->audit($product, $actor, 'created', ['name' => $product->name]);

            return $this->find($product->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data, ?User $actor): Product
    {
        return DB::transaction(function () use ($product, $data, $actor) {
            $payload = collect($data)->only([
                'category_id',
                'name',
                'short_description',
                'description',
                'ingredients_text',
                'is_active',
                'is_featured',
                'is_new',
                'is_seasonal',
                'season_label',
                'is_demonstration',
                'dietary_vegan',
                'dietary_gluten_free',
                'low_stock_portions',
                'portion_unit',
            ])->all();

            if (array_key_exists('nutrition', $data)) {
                $payload['nutrition'] = $this->nutrition($data['nutrition']);
            }
            if (! empty($data['slug']) || ! empty($data['name'])) {
                $payload['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name'] ?? $product->name, $product->id);
            }

            $product->fill($payload);
            $changes = $product->getDirty();
            $product->save();

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($product, $data['variants']);
                $changes['variants'] = true;
            }
            if (array_key_exists('allergen_ids', $data)) {
                $product->allergens()->sync($data['allergen_ids'] ?? []);
                $changes['allergens'] = $data['allergen_ids'];
            }

            $this->audit($product, $actor, 'updated', $changes);

            return $this->find($product->id);
        });
    }

    public function delete(Product $product, ?User $actor): void
    {
        $this->audit($product, $actor, 'deleted', ['name' => $product->name]);
        $product->delete();
    }

    public function storeImage(Product $product, UploadedFile $file, ?User $actor): Product
    {
        $product->deleteImageFile();
        $path = $file->store('products/'.$product->id, 'public');
        $product->update(['image_path' => $path]);
        $this->audit($product, $actor, 'image', ['image_path' => $path]);

        return $this->find($product->id);
    }

    public function removeImage(Product $product, ?User $actor): Product
    {
        $product->deleteImageFile();
        $product->update(['image_path' => null]);
        $this->audit($product, $actor, 'image_removed', []);

        return $this->find($product->id);
    }

    public function loadExamples(?User $actor): int
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'referencia'],
            [
                'name' => 'Referencia',
                'description' => 'Categoría de demostración. No agrupa inventario real.',
                'is_active' => true,
                'is_demonstration' => true,
            ]
        );

        $created = 0;
        foreach ($this->examples() as $example) {
            $exists = Product::query()->where('slug', Str::slug($example['name']))->exists();
            if ($exists) {
                continue;
            }

            $this->create([
                'category_id' => $category->id,
                'name' => $example['name'],
                'short_description' => 'Ejemplo de contenido. No es inventario ni una receta verificada.',
                'description' => 'Ficha de referencia para visualizar la carta. No está a la venta y no tiene existencia.',
                'is_active' => true,
                'is_demonstration' => true,
                'is_featured' => false,
                'variants' => [[
                    'name' => 'Copa de referencia',
                    'price' => $example['reference_price'],
                    'portions' => 1,
                    'container' => 'cup',
                    'is_active' => true,
                ]],
            ], $actor);
            $created++;
        }

        return $created;
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants): void
    {
        if ($variants === []) {
            throw ValidationException::withMessages([
                'variants' => ['El sabor necesita al menos una presentación.'],
            ]);
        }

        $kept = [];
        foreach (array_values($variants) as $index => $variant) {
            $payload = [
                'name' => $variant['name'],
                'sku' => $variant['sku'] ?? null ?: null,
                'price' => $variant['price'],
                'portions' => $variant['portions'] ?? 1,
                'container' => $variant['container'] ?? 'cup',
                'is_active' => $variant['is_active'] ?? true,
                'sort_order' => $variant['sort_order'] ?? $index,
            ];

            if (! empty($variant['id'])) {
                $model = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->find($variant['id']);
                if (! $model) {
                    throw ValidationException::withMessages([
                        'variants' => ['Una presentación no pertenece a este sabor.'],
                    ]);
                }
                $model->update($payload);
            } else {
                $model = $product->variants()->create($payload);
            }
            $kept[] = $model->id;
        }

        $product->variants()->whereNotIn('id', $kept)->get()->each(function (ProductVariant $variant) {
            if ($variant->orderItems()->exists()) {
                $variant->update(['is_active' => false]);

                return;
            }
            $variant->delete();
        });
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'sabor';
        $slug = $base;
        $i = 2;
        while (
            Product::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>|null  $nutrition
     * @return array<string, mixed>|null
     */
    private function nutrition(?array $nutrition): ?array
    {
        if (! $nutrition) {
            return null;
        }

        $clean = array_filter([
            'calories_kcal' => $nutrition['calories_kcal'] ?? null,
            'fat_g' => $nutrition['fat_g'] ?? null,
            'sugar_g' => $nutrition['sugar_g'] ?? null,
            'protein_g' => $nutrition['protein_g'] ?? null,
            'note' => $nutrition['note'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(Product $product, ?User $actor, string $action, array $changes): void
    {
        ProductAudit::query()->create([
            'product_id' => $product->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    /**
     * @return list<array{name: string, reference_price: int}>
     */
    private function examples(): array
    {
        return [
            ['name' => 'Strawberry Milk', 'reference_price' => 14000],
            ['name' => 'Matcha Cream', 'reference_price' => 15000],
            ['name' => 'Black Sesame', 'reference_price' => 15000],
            ['name' => 'Mango Yogurt', 'reference_price' => 14000],
            ['name' => 'Korean Melon', 'reference_price' => 16000],
            ['name' => 'Vanilla Bean', 'reference_price' => 13000],
            ['name' => 'Chocolate Seoul', 'reference_price' => 15000],
            ['name' => 'Injeolmi', 'reference_price' => 16000],
            ['name' => 'Ube Milk', 'reference_price' => 16000],
        ];
    }
}
