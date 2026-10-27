<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $guard = config('access.guard', 'api');
        $permissionId = $this->route('permission');

        return [
            'name' => [
                'required',
                'string',
                'max:125',
                'regex:/^[a-z0-9]+(?:\.[a-z0-9-]+)+$/',
                Rule::unique('permissions', 'name')
                    ->where(fn ($q) => $q->where('guard_name', $guard))
                    ->ignore($permissionId),
            ],
        ];
    }
}
