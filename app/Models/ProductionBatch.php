<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionBatch extends Model
{
    protected $fillable = [
        'recipe_id',
        'product_id',
        'batches',
        'quantity_produced',
        'yield_unit',
        'produced_at',
        'expires_on',
        'lot_code',
        'user_id',
        'notes',
        'estimated_cost',
        'costs_complete',
    ];

    protected function casts(): array
    {
        return [
            'batches' => 'decimal:3',
            'quantity_produced' => 'decimal:3',
            'produced_at' => 'datetime',
            'expires_on' => 'datetime',
            'estimated_cost' => 'decimal:2',
            'costs_complete' => 'boolean',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
