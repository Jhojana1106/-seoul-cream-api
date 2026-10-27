# Laravel Starter API

Plantilla base con **auth JWT**, **usuarios**, **roles** y **permisos** dinámicos (Spatie).
Úsala como punto de partida para proyectos nuevos.

## Stack

- Laravel 12 (API-first)
- JWT Auth (`tymon/jwt-auth`)
- Roles y permisos (`spatie/laravel-permission`)
- Arquitectura: Controllers → Services → Models
- Responses JSON estandarizadas

## Arranque

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
# Ajusta DB_* en .env
php artisan migrate:fresh --seed
php artisan serve
```

## Credenciales seed

- Email: `admin@example.com`
- Password: `password`
- Rol: `super-admin`

## API (`/api/v1`)

**Auth:** `POST /auth/login` · `GET /auth/me` · `POST /auth/logout` · `POST /auth/refresh`

**Users / Roles / Permissions:** CRUD + sync de permisos y asignación de roles.

Header: `Authorization: Bearer {token}`

## Extender permisos

Edita `config/access.php` y vuelve a sembrar (o crea permisos por API).

```php
'orders' => [
    'orders.view' => 'Ver pedidos',
    'orders.create' => 'Crear pedidos',
],
```

## Relacionado

Frontend companion: `react-admin-starter`
