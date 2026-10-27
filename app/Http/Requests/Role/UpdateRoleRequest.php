<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
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
        $roleId = $this->route('role');

        return [
            'name' => [
                'sometimes',
                'string',
                'max:125',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('roles', 'name')
                    ->where(fn ($q) => $q->where('guard_name', $guard))
                    ->ignore($roleId),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ];
    }
}
