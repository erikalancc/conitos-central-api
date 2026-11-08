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
        $usuario = $this->select('usuarios.id_usuario, usuarios.id_rol, usuarios.id_sucursal, usuarios.nombre, usuarios.usuario, usuarios.password_hash, roles.nombre AS rol, s.nombre AS sucursal, s.activo AS sucursal_activa')
            ->join('roles', 'roles.id_rol = usuarios.id_rol')
            ->join('sucursales s', 's.id_sucursal = usuarios.id_sucursal', 'left')
            ->where('usuarios.' . $campo, $valor)
            ->where('usuarios.activo', 1)
            ->first();

        return $usuario === null ? null : self::conSucursalDeTrabajo($usuario);
    }

    /**
     * Sucursal con la que trabaja el usuario (de ella salen productos y precios del POS):
     * la que tiene asignada; si no tiene o está desactivada, la matriz.
     */
    private static function conSucursalDeTrabajo(array $usuario): array
    {
        if ($usuario['id_sucursal'] === null || ! $usuario['sucursal_activa']) {
            $matriz = db_connect()->table('sucursales')->select('id_sucursal, nombre')
                ->where('activo', 1)->orderBy('es_matriz', 'DESC')->orderBy('id_sucursal')->get(1)->getRowArray();
            $usuario['id_sucursal'] = $matriz['id_sucursal'] ?? null;
            $usuario['sucursal']    = $matriz['nombre'] ?? null;
        }
        unset($usuario['sucursal_activa']);

        return $usuario;
    }

    // Datos públicos del usuario (lo que viaja al front)
    public static function publico(array $usuario): array
    {
        return [
            'id'          => (int) $usuario['id_usuario'],
            'nombre'      => $usuario['nombre'],
            'usuario'     => $usuario['usuario'],
            'id_rol'      => (int) $usuario['id_rol'],
            'rol'         => $usuario['rol'],
            'id_sucursal' => $usuario['id_sucursal'] === null ? null : (int) $usuario['id_sucursal'],
            'sucursal'    => $usuario['sucursal'],
        ];
    }
}
