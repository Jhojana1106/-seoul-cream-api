<?php

namespace App\Services\Commerce;

use App\Models\Setting;

class SettingService
{
    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'store_name' => 'Seoul Cream',
            'tagline' => 'Heladería coreana contemporánea',
            'currency' => 'COP',
            'phone' => null,
            'email' => null,
            'address' => null,
            'city' => null,
            'hours' => [],
            'instagram' => null,
            'whatsapp' => null,
            'pickup_enabled' => true,
            'delivery_enabled' => false,
            'delivery_fee' => null,
            'faq' => [],
            'policies' => null,
            'about' => null,
            'mission' => null,
            'vision' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = Setting::query()->pluck('value', 'key')->all();

        return array_merge($this->defaults(), $stored);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        $all = $this->all();

        return [
            'store_name' => $all['store_name'],
            'tagline' => $all['tagline'],
            'currency' => $all['currency'],
            'phone' => $all['phone'],
            'email' => $all['email'],
            'address' => $all['address'],
            'city' => $all['city'],
            'hours' => $all['hours'],
            'instagram' => $all['instagram'],
            'whatsapp' => $all['whatsapp'],
            'pickup_enabled' => (bool) $all['pickup_enabled'],
            'delivery_enabled' => (bool) $all['delivery_enabled'],
            'delivery_fee' => $all['delivery_fee'],
            'faq' => $all['faq'],
            'policies' => $all['policies'],
            'about' => $all['about'],
            'mission' => $all['mission'],
            'vision' => $all['vision'],
            'hours_configured' => is_array($all['hours']) && $all['hours'] !== [],
            'location_configured' => filled($all['address']),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(array $data): array
    {
        $allowed = array_keys($this->defaults());

        foreach ($data as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return $this->all();
    }
}
