<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = (bool) $request->user()?->can('orders.view');

        return [
            'id' => $this->id,
            'number' => $this->number,
            'channel' => $this->channel,
            'fulfillment' => $this->fulfillment,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->when($staff || $request->user()?->id === $this->user_id, $this->customer_email),
            'customer_phone' => $this->when($staff || $request->user()?->id === $this->user_id, $this->customer_phone),
            'address' => $this->address,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'shipping_total' => $this->shipping_total,
            'total' => $this->total,
            'notes' => $this->notes,
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod ? [
                'id' => $this->paymentMethod->id,
                'code' => $this->paymentMethod->code,
                'name' => $this->paymentMethod->name,
                'records_as_paid' => $this->paymentMethod->records_as_paid,
            ] : null),
            'coupon' => $this->whenLoaded('coupon', fn () => $this->coupon ? [
                'code' => $this->coupon->code,
            ] : null),
            'employee' => $this->when($staff && $this->relationLoaded('employee'), fn () => $this->employee ? [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id,
                'name' => $item->name,
                'variant_name' => $item->variant_name,
                'image_url' => $item->product?->imageUrl(),
                'quantity' => $item->quantity,
                'portions' => $item->portions,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
                'toppings' => $item->toppings,
                'special_instructions' => $item->special_instructions,
            ])->values()),
            'history' => $this->whenLoaded('histories', fn () => $this->histories->map(fn ($entry) => [
                'from_status' => $entry->from_status,
                'to_status' => $entry->to_status,
                'note' => $entry->note,
                'user' => $staff ? $entry->user?->name : null,
                'created_at' => $entry->created_at,
            ])->values()),
            'allowed_transitions' => $staff ? (Order::TRANSITIONS[$this->status] ?? []) : [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
