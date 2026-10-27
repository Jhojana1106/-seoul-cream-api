<?php

namespace App\Enums;

enum SystemRole: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Customer = 'customer';
    case Cashier = 'cashier';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Administrador',
            self::Admin => 'Administrador',
            self::Customer => 'Cliente',
            self::Cashier => 'Caja',
            self::Production => 'Producción',
        };
    }

    public function isProtected(): bool
    {
        return in_array($this->value, config('access.protected_roles', []), true);
    }
}
