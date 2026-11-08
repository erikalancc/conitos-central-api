<?php

namespace App\Libraries;

/**
 * Usuario autenticado en la petición actual. Lo llena AuthFilter
 * y lo leen los controladores con service('sesion').
 */
class Sesion
{
    private ?int $idUsuario  = null;
    private ?int $idRol      = null;
    private ?int $idSucursal = null;

    public function iniciar(int $idUsuario, int $idRol, ?int $idSucursal = null): void
    {
        $this->idUsuario  = $idUsuario;
        $this->idRol      = $idRol;
        $this->idSucursal = $idSucursal;
    }

    // Sucursal con la que trabaja el usuario (la suya o la matriz): define productos y precios del POS
    public function idSucursal(): ?int
    {
        return $this->idSucursal;
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
