<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemperatureReading extends Model
{
    protected $fillable = [
        'display_case_id', 'celsius', 'recorded_at', 'user_id', 'source', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'celsius' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function displayCase(): BelongsTo
    {
        return $this->belongsTo(DisplayCase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
