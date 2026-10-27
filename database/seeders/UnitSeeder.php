<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'g', 'name' => 'Gramo'],
            ['code' => 'kg', 'name' => 'Kilogramo'],
            ['code' => 'ml', 'name' => 'Mililitro'],
            ['code' => 'l', 'name' => 'Litro'],
            ['code' => 'unidad', 'name' => 'Unidad'],
        ] as $unit) {
            Unit::query()->updateOrCreate(['code' => $unit['code']], $unit + ['is_active' => true]);
        }
    }
}
