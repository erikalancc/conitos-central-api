<?php

namespace App\Libraries;

use Config\Jwt as JwtConfig;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * Emite y valida los tokens de acceso (JWT firmado con HS256).
 */
class Token
{
    private JwtConfig $config;

    public function __construct(?JwtConfig $config = null)
    {
        $this->config = $config ?? config(JwtConfig::class);

        if (strlen($this->config->secret) < 32) {
            throw new RuntimeException('jwt.secret no está configurado en .env (mínimo 32 caracteres).');
        }
    }

    /**
     * @param array{id_usuario:int, id_rol:int} $usuario
     *
     * @return array{token:string, expira:int}
     */
    public function emitir(array $usuario): array
    {
        $ahora  = time();
        $expira = $ahora + $this->config->ttl;

        $token = JWT::encode([
            'iss' => $this->config->emisor,
            'sub' => (int) $usuario['id_usuario'],
            'rol' => (int) $usuario['id_rol'],
            'iat' => $ahora,
            'exp' => $expira,
        ], $this->config->secret, $this->config->algoritmo);

        return ['token' => $token, 'expira' => $expira];
    }

    /**
     * Devuelve los claims del token o lanza excepción si es inválido o expiró.
     */
    public function validar(string $token): object
    {
        return JWT::decode($token, new Key($this->config->secret, $this->config->algoritmo));
    }
}
