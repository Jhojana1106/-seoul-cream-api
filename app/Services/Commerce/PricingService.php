<?php

namespace App\Services\Commerce;

use App\Models\Coupon;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Topping;
use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

class PricingService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function quote(array $data, bool $lock = false): array
    {
        $channel = $data['channel'] === 'pos' ? 'pos' : 'online';
        $errors = [];
        $lines = [];
        $subtotal = '0.00';
        $items = $data['items'] ?? [];

        if (! is_array($items) || $items === []) {
            $errors['items'][] = 'Agrega al menos un sabor.';
        }

        foreach (array_values(is_array($items) ? $items : []) as $index => $item) {
            $built = $this->line($item, $index, $lock);
            if ($built['error']) {
                $errors['items.'.$index][] = $built['error'];
            }
            $lines[] = $built['line'];
            if ($built['line']) {
                $subtotal = Quantity::add($subtotal, $built['line']['line_total'], 2);
            }
        }

        $discount = '0.00';
        $couponId = null;
        if (! empty($data['coupon_code'])) {
            $coupon = $this->coupon((string) $data['coupon_code'], $lock);
            if (! $coupon) {
                $errors['coupon_code'][] = 'El cupón no existe.';
            } else {
                $couponError = $this->couponError($coupon, $subtotal);
                if ($couponError) {
                    $errors['coupon_code'][] = $couponError;
                } else {
                    $discount = $this->couponAmount($coupon, $subtotal);
                    $couponId = $coupon->id;
                }
            }
        }

        if ($channel === 'pos' && ! empty($data['discount_authorized'])) {
            $manual = Quantity::money($data['discount_amount'] ?? 0);
            if (Quantity::lt($manual, 0, 2)) {
                $errors['discount_amount'][] = 'El descuento no puede ser negativo.';
            } else {
                $discount = Quantity::add($discount, $manual, 2);
            }
        }

        if (Quantity::gt($discount, $subtotal, 2)) {
            $errors['discount_amount'][] = 'El descuento no puede superar el subtotal.';
            $discount = $subtotal;
        }

        $shipping = '0.00';
        $fulfillment = $data['fulfillment'] ?? 'pickup';
        $settings = $this->settings->all();

        if ($fulfillment === 'delivery') {
            if (empty($settings['delivery_enabled'])) {
                $errors['fulfillment'][] = 'La entrega a domicilio no está habilitada.';
            } elseif ($settings['delivery_fee'] === null || $settings['delivery_fee'] === '') {
                $errors['fulfillment'][] = 'No hay una tarifa de envío configurada.';
            } else {
                $shipping = Quantity::money($settings['delivery_fee']);
            }

            $line1 = $data['address']['line1'] ?? null;
            $city = $data['address']['city'] ?? null;
            if (! $line1 || ! $city) {
                $errors['address'][] = 'La entrega necesita dirección y ciudad.';
            }
        } elseif ($fulfillment === 'pickup') {
            if (empty($settings['pickup_enabled'])) {
                $errors['fulfillment'][] = 'La recogida en tienda no está habilitada.';
            }
        } else {
            $errors['fulfillment'][] = 'La modalidad de entrega no es válida.';
        }

        $payment = null;
        if (! empty($data['payment_method_id'])) {
            $payment = PaymentMethod::query()->find($data['payment_method_id']);
            if (! $payment || ! $payment->is_active || ! in_array($channel, $payment->channels ?? [], true)) {
                $errors['payment_method_id'][] = 'El medio de pago no está disponible para este canal.';
                $payment = null;
            }
        } else {
            $errors['payment_method_id'][] = 'Selecciona un medio de pago.';
        }

        $total = Quantity::add(Quantity::sub($subtotal, $discount, 2), $shipping, 2);
        if (Quantity::lt($total, 0, 2)) {
            $total = '0.00';
        }

        return [
            'ready' => $errors === [] && collect($lines)->every(fn ($line) => $line !== null),
            'errors' => $errors,
            'currency' => $settings['currency'] ?? 'COP',
            'items' => array_values(array_filter($lines)),
            'subtotal' => Quantity::money($subtotal),
            'discount_total' => Quantity::money($discount),
            'shipping_total' => Quantity::money($shipping),
            'total' => Quantity::money($total),
            'coupon_id' => $couponId,
            'payment_method' => $payment,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{error: string|null, line: array<string, mixed>|null}
     */
    private function line(array $item, int $index, bool $lock): array
    {
        $productQuery = Product::query();
        $variantQuery = ProductVariant::query();
        if ($lock) {
            $productQuery->lockForUpdate();
            $variantQuery->lockForUpdate();
        }

        $product = $productQuery->find($item['product_id'] ?? 0);
        $variant = $variantQuery->find($item['variant_id'] ?? 0);

        if (! $product || ! $variant || (int) $variant->product_id !== (int) $product->id) {
            return ['error' => 'El sabor o la presentación no existe.', 'line' => null];
        }

        if (! $product->is_active || ! $variant->is_active) {
            return ['error' => 'Este sabor no está disponible.', 'line' => null];
        }

        if ($product->is_demonstration) {
            return ['error' => 'Este sabor es contenido de demostración y no está a la venta.', 'line' => null];
        }

        $quantity = (int) ($item['quantity'] ?? 0);
        if ($quantity < 1 || $quantity > 50) {
            return ['error' => 'La cantidad debe estar entre 1 y 50.', 'line' => null];
        }

        $toppingLines = [];
        $toppingTotal = '0.00';
        foreach ($item['toppings'] ?? [] as $toppingInput) {
            $toppingQuery = Topping::query();
            if ($lock) {
                $toppingQuery->lockForUpdate();
            }
            $topping = $toppingQuery->find($toppingInput['id'] ?? 0);
            $toppingQty = (int) ($toppingInput['quantity'] ?? 1);
            if (! $topping || ! $topping->is_active) {
                return ['error' => 'Uno de los toppings no está disponible.', 'line' => null];
            }
            if ($toppingQty < 1 || $toppingQty > 10) {
                return ['error' => 'La cantidad de topping no es válida.', 'line' => null];
            }
            $linePrice = Quantity::mul($topping->price, $toppingQty, 2);
            $toppingTotal = Quantity::add($toppingTotal, $linePrice, 2);
            $toppingLines[] = [
                'id' => $topping->id,
                'name' => $topping->name,
                'quantity' => $toppingQty,
                'unit_price' => Quantity::money($topping->price),
            ];
        }

        $unit = Quantity::add($variant->price, $toppingTotal, 2);
        $lineTotal = Quantity::mul($unit, $quantity, 2);
        $portions = Quantity::mul($variant->portions, $quantity);

        return [
            'error' => null,
            'line' => [
                'product_id' => $product->id,
                'product' => $product,
                'variant_id' => $variant->id,
                'variant' => $variant,
                'name' => $product->name,
                'variant_name' => $variant->name,
                'quantity' => $quantity,
                'portions' => $portions,
                'unit_price' => $unit,
                'line_total' => $lineTotal,
                'toppings' => $toppingLines,
                'special_instructions' => isset($item['special_instructions'])
                    ? mb_substr(trim((string) $item['special_instructions']), 0, 280)
                    : null,
                'display_case_id' => $item['display_case_id'] ?? null,
            ],
        ];
    }

    private function coupon(string $code, bool $lock): ?Coupon
    {
        $query = Coupon::query()->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))]);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function couponError(Coupon $coupon, string $subtotal): ?string
    {
        if (! $coupon->is_active) {
            return 'El cupón no está activo.';
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            return 'El cupón todavía no está vigente.';
        }
        if ($coupon->ends_at && $coupon->ends_at->isPast()) {
            return 'El cupón está vencido.';
        }
        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            return 'El cupón alcanzó su límite de usos.';
        }
        if ($coupon->min_subtotal !== null && Quantity::lt($subtotal, $coupon->min_subtotal, 2)) {
            return 'El pedido no alcanza el mínimo del cupón.';
        }
        if (! in_array($coupon->type, ['percent', 'fixed'], true)) {
            return 'El cupón está mal configurado.';
        }

        return null;
    }

    private function couponAmount(Coupon $coupon, string $subtotal): string
    {
        if ($coupon->type === 'percent') {
            return Quantity::div(Quantity::mul($subtotal, $coupon->value, 4), '100', 2);
        }

        return Quantity::min(Quantity::money($coupon->value), $subtotal, 2);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function throwIfNotReady(array $quote): void
    {
        if ($quote['ready']) {
            return;
        }

        throw ValidationException::withMessages($quote['errors'] ?: [
            'items' => ['No se pudo calcular el pedido.'],
        ]);
    }
}
