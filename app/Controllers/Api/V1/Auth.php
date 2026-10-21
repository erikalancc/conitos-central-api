<?php

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Models\UsuarioModel;

class Auth extends BaseApiController
{
    // POST /api/v1/auth/login
    public function login()
    {
        $datos = $this->cuerpo();

        $reglas = [
            'usuario'  => 'required|max_length[50]',
            'password' => 'required|max_length[72]',
        ];

        if (! $this->validateData($datos, $reglas)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $usuario = model(UsuarioModel::class)->buscarActivo('usuario', trim($datos['usuario']));

        // Se verifica contra un hash de relleno si el usuario no existe, para que el
        // tiempo de respuesta no revele qué usuarios son válidos
        $hash = $usuario['password_hash'] ?? '$2y$12$G.p4D7ZKmm0qp/sQAFzNeubFKsXyqH/6mo3TiuoO0ZNT2x4Uqj6OS';

        if (! password_verify($datos['password'], $hash) || $usuario === null) {
            return $this->failUnauthorized('Usuario o contraseña incorrectos.');
        }

        $acceso = service('token')->emitir($usuario);
        model(UsuarioModel::class)->update($usuario['id_usuario'], ['ultimo_acceso' => date('Y-m-d H:i:s')]);

        return $this->ok([
            'token'   => $acceso['token'],
            'expira'  => date(DATE_ATOM, $acceso['expira']),
            'usuario' => UsuarioModel::publico($usuario),
        ]);
    }

    // GET /api/v1/auth/me
    public function me()
    {
        $usuario = model(UsuarioModel::class)->buscarActivo('id_usuario', service('sesion')->idUsuario());

        if ($usuario === null) {
            return $this->failUnauthorized('El usuario ya no está activo.');
        }

        return $this->ok(UsuarioModel::publico($usuario));
    }
}
