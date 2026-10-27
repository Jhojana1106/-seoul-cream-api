<?php

namespace App\Support;

class CheckoutRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.toppings' => ['sometimes', 'array'],
            'items.*.toppings.*.id' => ['required', 'integer'],
            'items.*.toppings.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'items.*.special_instructions' => ['nullable', 'string', 'max:280'],
            'items.*.display_case_id' => ['nullable', 'integer'],
            'fulfillment' => ['required', 'in:pickup,delivery'],
            'payment_method_id' => ['required', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'address_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'array'],
            'address.line1' => ['nullable', 'string', 'max:180'],
            'address.line2' => ['nullable', 'string', 'max:180'],
            'address.city' => ['nullable', 'string', 'max:80'],
            'address.notes' => ['nullable', 'string', 'max:280'],
            'notes' => ['nullable', 'string', 'max:500'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
