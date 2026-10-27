<?php

namespace Database\Seeders;

use App\Services\Commerce\SettingService;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        app(SettingService::class)->update(app(SettingService::class)->defaults());
    }
}
