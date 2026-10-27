<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DisplayCase extends Model
{
    protected $fillable = [
        'name',
        'code',
        'location',
        'capacity_liters',
        'capacity_portions',
        'min_threshold_portions',
        'temp_min',
        'temp_max',
        'needs_cleaning',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity_liters' => 'decimal:2',
            'capacity_portions' => 'decimal:3',
            'min_threshold_portions' => 'decimal:3',
            'temp_min' => 'decimal:2',
            'temp_max' => 'decimal:2',
            'needs_cleaning' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DisplayCaseItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DisplayCaseEvent::class)->latest('performed_at');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(TemperatureReading::class)->latest('recorded_at');
    }

    public function latestReading(): HasOne
    {
        return $this->hasOne(TemperatureReading::class)->latestOfMany('recorded_at');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(TemperatureAlert::class)->latest('recorded_at');
    }

    public function openAlerts(): HasMany
    {
        return $this->hasMany(TemperatureAlert::class)->whereNull('acknowledged_at');
    }
}
