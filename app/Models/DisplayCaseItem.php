<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisplayCaseItem extends Model
{
    protected $fillable = ['display_case_id', 'product_id', 'quantity', 'container_label'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function displayCase(): BelongsTo
    {
        return $this->belongsTo(DisplayCase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
