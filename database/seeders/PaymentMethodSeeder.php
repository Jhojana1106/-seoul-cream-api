<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'code' => 'cash',
                'name' => 'Efectivo',
                'description' => 'Registro operativo de pago en caja. No hay pasarela conectada.',
                'channels' => ['pos'],
                'is_active' => true,
                'records_as_paid' => true,
            ],
            [
                'code' => 'transfer',
                'name' => 'Transferencia',
                'description' => 'El pedido queda pendiente de pago hasta que el personal confirme la transferencia.',
                'channels' => ['online', 'pos'],
                'is_active' => true,
                'records_as_paid' => false,
            ],
            [
                'code' => 'pickup_cash',
                'name' => 'Pago al recoger',
                'description' => 'El cliente paga al recoger. El pedido no se marca como pagado hasta confirmación del personal.',
                'channels' => ['online'],
                'is_active' => true,
                'records_as_paid' => false,
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::query()->updateOrCreate(['code' => $method['code']], $method);
        }
    }
}
