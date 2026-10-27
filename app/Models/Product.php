<?php

namespace App\Models;

use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'short_description',
        'description',
        'ingredients_text',
        'image_path',
        'is_active',
        'is_featured',
        'is_new',
        'is_seasonal',
        'season_label',
        'is_demonstration',
        'dietary_vegan',
        'dietary_gluten_free',
        'nutrition',
        'warehouse_portions',
        'low_stock_portions',
        'portion_unit',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
            'is_seasonal' => 'boolean',
            'is_demonstration' => 'boolean',
            'nutrition' => 'array',
            'warehouse_portions' => 'decimal:3',
            'low_stock_portions' => 'decimal:3',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    public function allergens(): BelongsToMany
    {
        return $this->belongsToMany(Allergen::class);
    }

    public function displayCaseItems(): HasMany
    {
        return $this->hasMany(DisplayCaseItem::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(ProductAudit::class)->latest('created_at');
    }

    public function imageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return asset('storage/'.$this->image_path);
    }

    public function availablePortions(): string
    {
        $cases = $this->case_portions ?? $this->displayCaseItems()->sum('quantity');

        return Quantity::add($this->warehouse_portions, $cases);
    }

    public function availability(): string
    {
        $portions = $this->availablePortions();

        if (Quantity::lte($portions, 0)) {
            return 'out';
        }

        if ($this->low_stock_portions !== null && Quantity::lte($portions, $this->low_stock_portions)) {
            return 'low';
        }

        return 'in_stock';
    }

    public function deleteImageFile(): void
    {
        if ($this->image_path) {
            Storage::disk('public')->delete($this->image_path);
        }
    }
}
