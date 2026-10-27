<?php

/**
 * Catálogo base de permisos del starter.
 * Añade módulos propios del proyecto con la convención: modulo.accion
 */
return [

    'guard' => 'api',

    'protected_roles' => [
        'super-admin',
    ],

    'permissions' => [

        'users' => [
            'users.view' => 'Ver usuarios',
            'users.create' => 'Crear usuarios',
            'users.update' => 'Actualizar usuarios',
            'users.delete' => 'Eliminar usuarios',
            'users.assign-roles' => 'Asignar roles a usuarios',
        ],

        'roles' => [
            'roles.view' => 'Ver roles',
            'roles.create' => 'Crear roles',
            'roles.update' => 'Actualizar roles',
            'roles.delete' => 'Eliminar roles',
            'roles.assign-permissions' => 'Asignar permisos a roles',
        ],

        'permissions' => [
            'permissions.view' => 'Ver permisos',
            'permissions.create' => 'Crear permisos',
            'permissions.update' => 'Actualizar permisos',
            'permissions.delete' => 'Eliminar permisos',
        ],

        'settings' => [
            'settings.view' => 'Ver configuración',
            'settings.update' => 'Actualizar configuración',
        ],

        'dashboard' => [
            'dashboard.view' => 'Ver el tablero',
        ],

        'products' => [
            'products.view' => 'Ver sabores y productos',
            'products.create' => 'Crear sabores y productos',
            'products.update' => 'Actualizar sabores y productos',
            'products.delete' => 'Eliminar sabores y productos',
        ],

        'categories' => [
            'categories.view' => 'Ver categorías',
            'categories.create' => 'Crear categorías',
            'categories.update' => 'Actualizar categorías',
            'categories.delete' => 'Eliminar categorías',
        ],

        'toppings' => [
            'toppings.view' => 'Ver toppings y extras',
            'toppings.create' => 'Crear toppings y extras',
            'toppings.update' => 'Actualizar toppings y extras',
            'toppings.delete' => 'Eliminar toppings y extras',
        ],

        'display-cases' => [
            'display-cases.view' => 'Ver vitrinas',
            'display-cases.create' => 'Crear vitrinas',
            'display-cases.update' => 'Actualizar vitrinas',
            'display-cases.delete' => 'Eliminar vitrinas',
        ],

        'inventory' => [
            'inventory.view' => 'Ver inventario y movimientos',
            'inventory.move' => 'Registrar movimientos de inventario',
        ],

        'ingredients' => [
            'ingredients.view' => 'Ver ingredientes',
            'ingredients.create' => 'Crear ingredientes',
            'ingredients.update' => 'Actualizar ingredientes',
            'ingredients.delete' => 'Eliminar ingredientes',
        ],

        'recipes' => [
            'recipes.view' => 'Ver recetas',
            'recipes.create' => 'Crear recetas',
            'recipes.update' => 'Actualizar recetas',
            'recipes.delete' => 'Eliminar recetas',
        ],

        'production' => [
            'production.view' => 'Ver producción',
            'production.create' => 'Registrar producción',
        ],

        'orders' => [
            'orders.view' => 'Ver pedidos',
            'orders.update' => 'Actualizar pedidos',
            'orders.cancel' => 'Anular pedidos',
        ],

        'pos' => [
            'pos.access' => 'Usar el punto de venta',
            'pos.discount' => 'Aplicar descuentos en caja',
            'pos.refund' => 'Registrar devoluciones',
        ],

        'customers' => [
            'customers.view' => 'Ver clientes',
        ],

        'purchases' => [
            'purchases.view' => 'Ver compras',
            'purchases.create' => 'Crear órdenes de compra',
            'purchases.receive' => 'Recibir mercancía',
        ],

        'suppliers' => [
            'suppliers.view' => 'Ver proveedores',
            'suppliers.create' => 'Crear proveedores',
            'suppliers.update' => 'Actualizar proveedores',
            'suppliers.delete' => 'Eliminar proveedores',
        ],

        'reports' => [
            'reports.view' => 'Ver reportes',
            'reports.employees' => 'Ver desempeño de empleados',
        ],

        'cash' => [
            'cash.manage' => 'Abrir y cerrar caja',
        ],

        'coupons' => [
            'coupons.view' => 'Ver cupones',
            'coupons.manage' => 'Administrar cupones',
        ],

    ],
];
