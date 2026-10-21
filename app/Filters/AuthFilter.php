<?php

namespace App\Filters;

use App\Models\UsuarioModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Throwable;

/**
 * Exige un token válido en "Authorization: Bearer <token>".
 *
 * Uso en rutas:
 *   'filter' => 'auth'       cualquier usuario autenticado
 *   'filter' => 'auth:1,2'   solo los id_rol indicados (rol vigente en la BD, no el del token)
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');

        if (! preg_match('/^Bearer\s+(\S+)$/i', $header, $coincidencia)) {
            return $this->rechazar(401, 'Falta el token de acceso.');
        }

        try {
            $claims = service('token')->validar($coincidencia[1]);
        } catch (Throwable $e) {
            return $this->rechazar(401, 'Token inválido o expirado.');
        }

        // El usuario debe seguir activo y su rol se toma de la BD: desactivar a alguien
        // o cambiarle el rol aplica de inmediato, sin esperar a que venza su token
        $usuario = model(UsuarioModel::class)->buscarActivo('id_usuario', (int) $claims->sub);
        if ($usuario === null) {
            return $this->rechazar(401, 'Tu usuario fue desactivado. Pide ayuda a un administrador.');
        }
        $idRol = (int) $usuario['id_rol'];

        if ($arguments !== null && $arguments !== [] && ! in_array((string) $idRol, $arguments, true)) {
            return $this->rechazar(403, 'Tu rol no tiene acceso a este recurso.');
        }

        service('sesion')->iniciar((int) $claims->sub, $idRol);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function rechazar(int $status, string $mensaje): ResponseInterface
    {
        return Services::response()
            ->setStatusCode($status)
            ->setJSON(['status' => $status, 'error' => $status, 'messages' => ['error' => $mensaje]]);
    }
}
