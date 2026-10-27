<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'item_type',
        'item_id',
        'movement_type',
        'quantity',
        'location',
        'location_id',
        'lot_code',
        'expires_on',
        'reason',
        'user_id',
        'reference_type',
        'reference_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'expires_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
