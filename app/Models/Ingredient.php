<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    protected $fillable = [
        'unit_id', 'name', 'sku', 'stock_quantity', 'min_stock', 'cost_per_unit', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stock_quantity' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'cost_per_unit' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(IngredientLot::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }
}
