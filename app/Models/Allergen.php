<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Allergen extends Model
{
    protected $fillable = ['name', 'slug'];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
