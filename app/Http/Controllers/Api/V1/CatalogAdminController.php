<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Allergen;
use App\Models\Category;
use App\Models\Product;
use App\Models\Topping;
use App\Services\Commerce\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogAdminController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function categories(Request $request): JsonResponse
    {
        $query = Category::query()->orderBy('sort_order')->orderBy('name');
        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }

        return ApiResponse::success($query->get(), 'Categorías');
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = Category::query()->create([
            ...$data,
            'slug' => $this->uniqueCategorySlug($data['name']),
            'is_active' => $data['is_active'] ?? true,
            'is_demonstration' => false,
        ]);

        return ApiResponse::success($category, 'Categoría creada', 201);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['name'])) {
            $data['slug'] = $this->uniqueCategorySlug($data['name'], $category->id);
        }
        $category->update($data);

        return ApiResponse::success($category->fresh(), 'Categoría actualizada');
    }

    public function destroyCategory(Category $category): JsonResponse
    {
        if ($category->products()->exists()) {
            throw ValidationException::withMessages([
                'category' => ['No se puede eliminar una categoría con sabores.'],
            ]);
        }
        $category->delete();

        return ApiResponse::success(null, 'Categoría eliminada');
    }

    public function products(Request $request): JsonResponse
    {
        return ApiResponse::paginated(
            ProductResource::collection($this->products->paginate($request->all(), false)),
            'Sabores'
        );
    }

    public function showProduct(int $product): JsonResponse
    {
        return ApiResponse::success(new ProductResource($this->products->find($product)), 'Sabor');
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $product = $this->products->create($this->productData($request), $request->user());

        return ApiResponse::success(new ProductResource($product), 'Sabor creado', 201);
    }

    public function updateProduct(Request $request, int $product): JsonResponse
    {
        $model = Product::query()->findOrFail($product);
        $updated = $this->products->update($model, $this->productData($request, false), $request->user());

        return ApiResponse::success(new ProductResource($updated), 'Sabor actualizado');
    }

    public function destroyProduct(Request $request, int $product): JsonResponse
    {
        $model = Product::query()->findOrFail($product);
        $this->products->delete($model, $request->user());

        return ApiResponse::success(null, 'Sabor eliminado');
    }

    public function storeImage(Request $request, int $product): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'max:4096']]);
        $model = Product::query()->findOrFail($product);

        return ApiResponse::success(
            new ProductResource($this->products->storeImage($model, $request->file('image'), $request->user())),
            'Imagen actualizada'
        );
    }

    public function destroyImage(Request $request, int $product): JsonResponse
    {
        $model = Product::query()->findOrFail($product);

        return ApiResponse::success(
            new ProductResource($this->products->removeImage($model, $request->user())),
            'Imagen eliminada'
        );
    }

    public function examples(Request $request): JsonResponse
    {
        $created = $this->products->loadExamples($request->user());

        return ApiResponse::success([
            'created' => $created,
            'notice' => 'Los sabores creados son contenido de demostración. No tienen inventario y no se pueden vender.',
        ], 'Referencias cargadas');
    }

    public function toppings(): JsonResponse
    {
        return ApiResponse::success(Topping::query()->orderBy('sort_order')->orderBy('name')->get(), 'Toppings');
    }

    public function storeTopping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        return ApiResponse::success(Topping::query()->create($data), 'Topping creado', 201);
    }

    public function updateTopping(Request $request, Topping $topping): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        $topping->update($data);

        return ApiResponse::success($topping->fresh(), 'Topping actualizado');
    }

    public function destroyTopping(Topping $topping): JsonResponse
    {
        $topping->delete();

        return ApiResponse::success(null, 'Topping eliminado');
    }

    public function allergens(): JsonResponse
    {
        return ApiResponse::success(Allergen::query()->orderBy('name')->get(), 'Alérgenos');
    }

    public function storeAllergen(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        return ApiResponse::success(Allergen::query()->create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']) ?: 'alergeno',
        ]), 'Alérgeno creado', 201);
    }

    public function destroyAllergen(Allergen $allergen): JsonResponse
    {
        $allergen->delete();

        return ApiResponse::success(null, 'Alérgeno eliminado');
    }

    /**
     * @return array<string, mixed>
     */
    private function productData(Request $request, bool $creating = true): array
    {
        $variantRule = $creating ? ['required', 'array', 'min:1'] : ['sometimes', 'array', 'min:1'];

        return $request->validate([
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:180'],
            'short_description' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'ingredients_text' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_new' => ['sometimes', 'boolean'],
            'is_seasonal' => ['sometimes', 'boolean'],
            'season_label' => ['nullable', 'string', 'max:80'],
            'is_demonstration' => ['sometimes', 'boolean'],
            'dietary_vegan' => ['nullable', 'boolean'],
            'dietary_gluten_free' => ['nullable', 'boolean'],
            'nutrition' => ['nullable', 'array'],
            'nutrition.calories_kcal' => ['nullable', 'numeric', 'min:0'],
            'nutrition.fat_g' => ['nullable', 'numeric', 'min:0'],
            'nutrition.sugar_g' => ['nullable', 'numeric', 'min:0'],
            'nutrition.protein_g' => ['nullable', 'numeric', 'min:0'],
            'nutrition.note' => ['nullable', 'string', 'max:500'],
            'low_stock_portions' => ['nullable', 'numeric', 'min:0'],
            'portion_unit' => ['nullable', 'string', 'max:40'],
            'allergen_ids' => ['sometimes', 'array'],
            'allergen_ids.*' => ['integer', Rule::exists('allergens', 'id')],
            'variants' => $variantRule,
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required', 'string', 'max:120'],
            'variants.*.sku' => ['nullable', 'string', 'max:80'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.portions' => ['nullable', 'numeric', 'min:0.001'],
            'variants.*.container' => ['nullable', Rule::in(['cone', 'cup', 'pint', 'pack', 'other'])],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'variants.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function uniqueCategorySlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $slug = $base;
        $i = 2;
        while (Category::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
