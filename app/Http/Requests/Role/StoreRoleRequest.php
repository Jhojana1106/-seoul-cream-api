<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
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

        return [
            'name' => [
                'required',
                'string',
                'max:125',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('roles', 'name')->where(fn ($q) => $q->where('guard_name', $guard)),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'El nombre del rol debe ser slug (ej: jefe-turno).',
        ];
    }
}
