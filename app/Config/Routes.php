<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

/*
 * API v1 — documentación de cada endpoint en docs/endpoints/
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1', 'filter' => 'cors'], static function (RouteCollection $routes) {
    // Preflight de CORS para cualquier ruta del API
    $routes->options('(:any)', static function () {});

    // Públicas
    $routes->post('auth/login', 'Auth::login');

    // Requieren token
    $routes->group('', ['filter' => 'auth'], static function (RouteCollection $routes) {
        $routes->get('auth/me', 'Auth::me');
        $routes->get('catalogo', 'Catalogo::index');

        // Checklist operativo por momento del día: apertura, medio-turno o cierre (antes del corte)
        $routes->get('checklist/(apertura|medio-turno|cierre)', 'Checklist::ver/$1');
        $routes->post('checklist/(apertura|medio-turno|cierre)', 'Checklist::guardar/$1');
        $routes->post('checklist/(apertura|medio-turno|cierre)/omitir', 'Checklist::omitir/$1');
    });

    // Ventas (cobro e historial): Administrador y Vendedor / Cajero
    $routes->group('ventas', ['filter' => 'auth:1,2'], static function (RouteCollection $routes) {
        $routes->get('/', 'Ventas::index');
        $routes->get('resumen', 'Ventas::resumen');
        $routes->post('/', 'Ventas::crear');
    });

    // Caja (apertura, movimientos y corte): Administrador y Vendedor / Cajero
    $routes->group('caja', ['filter' => 'auth:1,2'], static function (RouteCollection $routes) {
        $routes->get('turno', 'Caja::turno');
        $routes->post('apertura', 'Caja::abrir');
        $routes->post('movimientos', 'Caja::movimiento');
        $routes->post('cierre', 'Caja::cerrar');
        $routes->get('cortes', 'Caja::cortes');
        $routes->get('cortes/(:num)', 'Caja::corte/$1');
    });

    // Administración del catálogo: solo Administrador (id_rol 1)
    $routes->group('admin', ['filter' => 'auth:1'], static function (RouteCollection $routes) {
        $routes->get('catalogo', 'CatalogoAdmin::index');

        $routes->get('usuarios', 'Usuarios::index');
        $routes->post('usuarios', 'Usuarios::crear');
        $routes->put('usuarios/(:num)', 'Usuarios::actualizar/$1');
        $routes->delete('usuarios/(:num)', 'Usuarios::eliminar/$1');

        $routes->get('sucursales', 'Sucursales::index');
        $routes->post('sucursales', 'Sucursales::crear');
        $routes->put('sucursales/(:num)', 'Sucursales::actualizar/$1');
        $routes->delete('sucursales/(:num)', 'Sucursales::eliminar/$1');

        // Checklist operativo: puntos por momento del día y sucursal
        $routes->get('checklist', 'ChecklistAdmin::index');
        $routes->post('checklist', 'ChecklistAdmin::crear');
        $routes->put('checklist/(:num)', 'ChecklistAdmin::actualizar/$1');
        $routes->delete('checklist/(:num)', 'ChecklistAdmin::eliminar/$1');

        // Imagen del producto (multipart, campo "imagen": JPG/JPEG/PNG/WEBP, máx. 5 MB)
        $routes->post('productos/(:num)/imagen', 'CatalogoAdmin::subirImagen/$1');
        $routes->delete('productos/(:num)/imagen', 'CatalogoAdmin::quitarImagen/$1');

        $routes->post('(categorias|productos|sabores|extras|promociones)', 'CatalogoAdmin::crear/$1');
        $routes->put('(categorias|productos|sabores|extras|promociones)/(:num)', 'CatalogoAdmin::actualizar/$1/$2');
        $routes->delete('(categorias|productos|sabores|extras|promociones)/(:num)', 'CatalogoAdmin::eliminar/$1/$2');
    });
});
