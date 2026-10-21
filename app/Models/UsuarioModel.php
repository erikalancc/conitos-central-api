<?php

namespace App\Models;

use CodeIgniter\Model;

class UsuarioModel extends Model
{
    protected $table         = 'usuarios';
    protected $primaryKey    = 'id_usuario';
    protected $returnType    = 'array';
    protected $allowedFields = ['id_rol', 'id_sucursal', 'nombre', 'usuario', 'password_hash', 'activo', 'ultimo_acceso'];

    /**
     * Usuario activo con el nombre de su rol, o null si no existe.
     * Incluye password_hash: no devolverlo nunca al cliente.
     */
    public function buscarActivo(string $campo, $valor): ?array
    {
        return $this->select('usuarios.id_usuario, usuarios.id_rol, usuarios.nombre, usuarios.usuario, usuarios.password_hash, roles.nombre AS rol')
            ->join('roles', 'roles.id_rol = usuarios.id_rol')
            ->where('usuarios.' . $campo, $valor)
            ->where('usuarios.activo', 1)
            ->first();
    }

    // Datos públicos del usuario (lo que viaja al front)
    public static function publico(array $usuario): array
    {
        return [
            'id'      => (int) $usuario['id_usuario'],
            'nombre'  => $usuario['nombre'],
            'usuario' => $usuario['usuario'],
            'id_rol'  => (int) $usuario['id_rol'],
            'rol'     => $usuario['rol'],
        ];
    }
}
