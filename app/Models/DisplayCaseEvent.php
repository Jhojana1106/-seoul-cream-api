<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisplayCaseEvent extends Model
{
    protected $fillable = ['display_case_id', 'type', 'performed_at', 'user_id', 'notes'];

    protected function casts(): array
    {
        return ['performed_at' => 'datetime'];
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
