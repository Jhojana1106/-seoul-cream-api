<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->can('products.view') && ! $request->routeIs('store.*');

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
                'slug' => $this->category?->slug,
                'is_demonstration' => (bool) $this->category?->is_demonstration,
            ]),
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'ingredients_text' => $this->ingredients_text,
            'image_url' => $this->imageUrl(),
            'is_active' => $this->is_active,
            'is_featured' => $this->is_featured,
            'is_new' => $this->is_new,
            'is_seasonal' => $this->is_seasonal,
            'season_label' => $this->season_label,
            'is_demonstration' => $this->is_demonstration,
            'dietary_vegan' => $this->nullableBool($this->dietary_vegan),
            'dietary_gluten_free' => $this->nullableBool($this->dietary_gluten_free),
            'nutrition' => $this->nutrition,
            'portion_unit' => $this->portion_unit,
            'available_portions' => $this->availablePortions(),
            'availability' => $this->availability(),
            'purchasable' => $this->is_active
                && ! $this->is_demonstration
                && $this->availability() !== 'out'
                && $this->relationLoaded('variants')
                && $this->variants->isNotEmpty(),
            'min_price' => $this->min_price !== null ? number_format((float) $this->min_price, 2, '.', '') : $this->variants->where('is_active', true)->min('price'),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'portions' => $variant->portions,
                'container' => $variant->container,
                'is_active' => $variant->is_active,
                'sort_order' => $variant->sort_order,
            ])->values()),
            'allergens' => $this->whenLoaded('allergens', fn () => $this->allergens->map(fn ($allergen) => [
                'id' => $allergen->id,
                'name' => $allergen->name,
                'slug' => $allergen->slug,
            ])->values()),
            'warehouse_portions' => $this->when($staff, (string) $this->warehouse_portions),
            'case_portions' => $this->when($staff, (string) ($this->case_portions ?? '0')),
            'low_stock_portions' => $this->when($staff, $this->low_stock_portions),
            'audits' => $this->when($staff, fn () => $this->relationLoaded('audits') ? $this->audits->map(fn ($audit) => [
                'id' => $audit->id,
                'action' => $audit->action,
                'changes' => $audit->changes,
                'user' => $audit->user?->name,
                'created_at' => $audit->created_at,
            ])->values() : []),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
