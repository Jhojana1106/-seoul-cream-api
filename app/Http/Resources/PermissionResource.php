<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Spatie\Permission\Models\Permission */
class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $module = explode('.', (string) $this->name)[0] ?? 'otros';
        $labels = collect(config('access.permissions', []))->flatMap(fn ($group) => $group);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'module' => $module,
            'label' => $labels[$this->name] ?? $this->name,
            'guard_name' => $this->guard_name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
