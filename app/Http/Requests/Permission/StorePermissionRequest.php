<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePermissionRequest extends FormRequest
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
                'regex:/^[a-z0-9]+(?:\.[a-z0-9-]+)+$/',
                Rule::unique('permissions', 'name')->where(fn ($q) => $q->where('guard_name', $guard)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'El permiso debe seguir el formato modulo.accion (ej: sales.refund).',
        ];
    }
}
