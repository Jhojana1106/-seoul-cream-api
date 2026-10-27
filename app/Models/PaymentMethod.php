<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'channels', 'is_active', 'records_as_paid',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'is_active' => 'boolean',
            'records_as_paid' => 'boolean',
        ];
    }
}
