<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemperatureAlert extends Model
{
    protected $fillable = [
        'display_case_id',
        'temperature_reading_id',
        'celsius',
        'message',
        'recorded_at',
        'user_id',
        'acknowledged_at',
        'acknowledged_by',
    ];

    protected function casts(): array
    {
        return [
            'celsius' => 'decimal:2',
            'recorded_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function displayCase(): BelongsTo
    {
        return $this->belongsTo(DisplayCase::class);
    }

    public function reading(): BelongsTo
    {
        return $this->belongsTo(TemperatureReading::class, 'temperature_reading_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
