<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $fillable = [
        'product_id', 'name', 'yield_quantity', 'yield_unit', 'instructions', 'shelf_life_hours', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'yield_quantity' => 'decimal:3',
            'shelf_life_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductionBatch::class);
    }
}
