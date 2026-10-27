<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
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
        return [
            'avatar' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Selecciona una imagen de perfil.',
            'avatar.image' => 'El archivo debe ser una imagen.',
            'avatar.mimes' => 'Formatos permitidos: JPG, PNG o WEBP.',
            'avatar.max' => 'La imagen no debe superar 2 MB.',
        ];
    }
}
