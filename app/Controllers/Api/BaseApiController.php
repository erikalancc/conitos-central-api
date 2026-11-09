<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\Exceptions\HTTPException;

/**
 * Base de los endpoints /api/v1. Respuestas en JSON:
 *   éxito  -> { "data": ... }
 *   error  -> { "status", "error", "messages": { campo|error: mensaje } }
 */
abstract class BaseApiController extends BaseController
{
    use ResponseTrait;

    protected $format = 'json';

    protected function ok($data = null, int $status = 200)
    {
        return $this->respond(['data' => $data], $status);
    }

    // Cuerpo JSON de la petición como arreglo ([] si viene vacío o mal formado)
    protected function cuerpo(): array
    {
        try {
            return (array) $this->request->getJSON(true);
        } catch (HTTPException $e) {
            return [];
        }
    }

    // roles.id_rol del Vendedor / Cajero
    protected const ROL_VENDEDOR = 2;

    // roles.id_rol del Administrador
    protected const ROL_ADMIN = 1;

    protected function esVendedor(): bool
    {
        return service('sesion')->idRol() === self::ROL_VENDEDOR;
    }

    protected function esAdmin(): bool
    {
        return service('sesion')->idRol() === self::ROL_ADMIN;
    }

    /**
     * Sucursal de la que se consultan ventas y cortes (historial): el administrador puede pedir
     * cualquiera con ?id_sucursal=; los demás siempre consultan la suya, aunque manden otra.
     */
    protected function sucursalConsultada(): ?int
    {
        $pedida = $this->request->getGet('id_sucursal');
        if ($this->esAdmin() && is_string($pedida) && ctype_digit($pedida)) {
            return (int) $pedida;
        }

        return service('sesion')->idSucursal();
    }

    /**
     * Cuántas de las últimas ventas puede ver quien consulta: el límite de su sucursal
     * (sucursales.ventas_visibles_vendedor) si es vendedor; null = sin límite.
     */
    protected function limiteVentas(): ?int
    {
        if (! $this->esVendedor()) {
            return null;
        }
        $fila = db_connect()->table('sucursales')->select('ventas_visibles_vendedor')
            ->where('id_sucursal', service('sesion')->idSucursal())->get()->getRowArray();

        return (int) ($fila['ventas_visibles_vendedor'] ?? 5);
    }

    // Deja solo las últimas ventas permitidas (la lista viene en orden cronológico)
    protected function ventasVisibles(array $ventas): array
    {
        $limite = $this->limiteVentas();

        return $limite === null ? $ventas : array_slice($ventas, max(0, count($ventas) - $limite));
    }
}
