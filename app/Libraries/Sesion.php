<?php

namespace App\Libraries;

/**
 * Usuario autenticado en la petición actual. Lo llena AuthFilter
 * y lo leen los controladores con service('sesion').
 */
class Sesion
{
    private ?int $idUsuario = null;
    private ?int $idRol     = null;

    public function iniciar(int $idUsuario, int $idRol): void
    {
        $this->idUsuario = $idUsuario;
        $this->idRol     = $idRol;
    }

    public function idUsuario(): ?int
    {
        return $this->idUsuario;
    }

    public function idRol(): ?int
    {
        return $this->idRol;
    }
}
