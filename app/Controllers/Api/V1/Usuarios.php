<?php

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Models\UsuarioModel;

/**
 * Catálogo de usuarios (solo Administrador). Cada escritura responde con el listado completo.
 *
 * Reglas: nadie puede desactivarse ni quitarse el rol de Administrador a sí mismo, siempre queda
 * al menos un Administrador activo, y quien ya operó (ventas, caja, checklist, producción) no se
 * borra: se desactiva.
 */
class Usuarios extends BaseApiController
{
    private const ID_ADMIN = 1;

    // Costo de bcrypt igual al de los usuarios existentes
    private const COSTO_HASH = 12;

    // Dónde aparece un usuario: si tiene registros aquí no se puede borrar
    private const REFERENCIAS = [
        ['ventas', 'id_usuario_vendedor'],
        ['cortes_caja', 'id_usuario_apertura'],
        ['cortes_caja', 'id_usuario_cierre'],
        ['movimientos_caja', 'id_usuario'],
        ['checklist_registros', 'id_usuario'],
        ['checklist_registros', 'id_usuario_autoriza'],
        ['ordenes_produccion', 'id_usuario_productor'],
    ];

    // GET /api/v1/admin/usuarios
    public function index()
    {
        return $this->ok($this->listado());
    }

    // POST /api/v1/admin/usuarios
    public function crear()
    {
        return $this->guardar(null);
    }

    // PUT /api/v1/admin/usuarios/{id} — cambios parciales; "password" vacío o ausente no la cambia
    public function actualizar(int $id)
    {
        if (model(UsuarioModel::class)->find($id) === null) {
            return $this->failNotFound('El usuario no existe.');
        }

        return $this->guardar($id);
    }

    // DELETE /api/v1/admin/usuarios/{id} — solo si nunca ha operado
    public function eliminar(int $id)
    {
        $modelo  = model(UsuarioModel::class);
        $usuario = $modelo->find($id);
        if ($usuario === null) {
            return $this->failNotFound('El usuario no existe.');
        }
        if ($id === service('sesion')->idUsuario()) {
            return $this->fail(['error' => 'No puedes eliminar tu propio usuario.'], 409);
        }
        if ($this->enUso($id)) {
            return $this->fail(['error' => 'El usuario ya tiene ventas u operaciones registradas; desactívalo en su lugar.'], 409);
        }
        if ((int) $usuario['id_rol'] === self::ID_ADMIN && $usuario['activo'] && $this->adminsActivos() <= 1) {
            return $this->fail(['error' => 'Debe quedar al menos un administrador activo.'], 409);
        }

        $modelo->delete($id);

        return $this->ok(['id' => $id] + $this->listado());
    }

    private function guardar(?int $id)
    {
        $creando = $id === null;
        $datos   = $this->cuerpo();
        $modelo  = model(UsuarioModel::class);
        $actual  = $creando ? null : $modelo->find($id);

        foreach (['nombre', 'usuario'] as $campo) {
            if (isset($datos[$campo]) && is_string($datos[$campo])) {
                $datos[$campo] = trim(preg_replace('/\s+/', ' ', $datos[$campo]));
            }
        }
        if (isset($datos['usuario'])) {
            $datos['usuario'] = mb_strtolower($datos['usuario']);
        }
        // Sin contraseña nueva al editar = se conserva la actual
        if (! $creando && array_key_exists('password', $datos) && ($datos['password'] === '' || $datos['password'] === null)) {
            unset($datos['password']);
        }

        $unico  = $creando ? '' : ",id_usuario,{$id}";
        $reglas = [
            'nombre'      => ['label' => 'nombre', 'rules' => 'required|max_length[100]'],
            'usuario'     => ['label' => 'usuario', 'rules' => "required|regex_match[/^[a-z0-9._-]{3,50}$/]|is_unique[usuarios.usuario{$unico}]"],
            'password'    => ['label' => 'contraseña', 'rules' => 'required|min_length[8]|max_length[72]'],
            'id_rol'      => ['label' => 'rol', 'rules' => 'required|is_natural_no_zero|is_not_unique[roles.id_rol]'],
            'id_sucursal' => ['label' => 'sucursal', 'rules' => 'permit_empty|is_natural_no_zero|is_not_unique[sucursales.id_sucursal]'],
        ];
        if (! $creando) {
            $reglas = array_intersect_key($reglas, $datos);
        }
        if ($reglas !== [] && ! $this->validateData($datos, $reglas, [
            'usuario'  => [
                'regex_match' => 'Usa de 3 a 50 letras minúsculas, números, punto, guion o guion bajo (sin espacios).',
                'is_unique'   => 'Ese nombre de usuario ya está en uso.',
            ],
            'password' => ['min_length' => 'La contraseña debe tener al menos 8 caracteres.'],
        ])) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $activo = array_key_exists('activo', $datos) ? filter_var($datos['activo'], FILTER_VALIDATE_BOOLEAN) : ($creando ? true : (bool) $actual['activo']);
        $idRol  = (int) ($datos['id_rol'] ?? $actual['id_rol'] ?? 0);

        if (! $creando) {
            $esYo = $id === service('sesion')->idUsuario();
            if ($esYo && ! $activo) {
                return $this->fail(['error' => 'No puedes desactivar tu propio usuario.'], 409);
            }
            if ($esYo && $idRol !== self::ID_ADMIN) {
                return $this->fail(['error' => 'No puedes quitarte el rol de Administrador a ti mismo.'], 409);
            }
            $eraAdminActivo = (int) $actual['id_rol'] === self::ID_ADMIN && $actual['activo'];
            if ($eraAdminActivo && ($idRol !== self::ID_ADMIN || ! $activo) && $this->adminsActivos() <= 1) {
                return $this->fail(['error' => 'Debe quedar al menos un administrador activo.'], 409);
            }
        }

        $columnas = array_intersect_key($datos, array_flip(['nombre', 'usuario', 'id_rol', 'id_sucursal']));
        if (array_key_exists('id_sucursal', $columnas) && empty($columnas['id_sucursal'])) {
            $columnas['id_sucursal'] = null;
        }
        if (isset($datos['password'])) {
            $columnas['password_hash'] = password_hash($datos['password'], PASSWORD_BCRYPT, ['cost' => self::COSTO_HASH]);
        }
        if ($creando || array_key_exists('activo', $datos)) {
            $columnas['activo'] = $activo ? 1 : 0;
        }

        if ($creando) {
            $id = (int) $modelo->insert($columnas);
        } elseif ($columnas !== []) {
            $modelo->update($id, $columnas);
        }

        return $this->ok(['id' => $id] + $this->listado(), $creando ? 201 : 200);
    }

    private function enUso(int $id): bool
    {
        $db = db_connect();
        foreach (self::REFERENCIAS as [$tabla, $columna]) {
            if ($db->tableExists($tabla) && $db->table($tabla)->where($columna, $id)->countAllResults() > 0) {
                return true;
            }
        }

        return false;
    }

    private function adminsActivos(): int
    {
        return model(UsuarioModel::class)->where('id_rol', self::ID_ADMIN)->where('activo', 1)->countAllResults();
    }

    // Usuarios (sin contraseñas), roles y sucursales para el formulario
    private function listado(): array
    {
        $db       = db_connect();
        $enUso    = [];
        foreach (self::REFERENCIAS as [$tabla, $columna]) {
            if ($db->tableExists($tabla)) {
                foreach ($db->table($tabla)->select($columna)->distinct()->where("{$columna} IS NOT NULL")->get()->getResultArray() as $fila) {
                    $enUso[(int) $fila[$columna]] = true;
                }
            }
        }

        $usuarios = array_map(static fn (array $u) => [
            'id'             => (int) $u['id_usuario'],
            'nombre'         => $u['nombre'],
            'usuario'        => $u['usuario'],
            'id_rol'         => (int) $u['id_rol'],
            'rol'            => $u['rol'],
            'sucursal'       => $u['id_sucursal'] === null ? null : ['id' => (int) $u['id_sucursal'], 'nombre' => $u['sucursal']],
            'activo'         => (bool) $u['activo'],
            'ultimo_acceso'  => $u['ultimo_acceso'] === null ? null : date(DATE_ATOM, strtotime($u['ultimo_acceso'])),
            'fecha_creacion' => $u['fecha_creacion'] === null ? null : date(DATE_ATOM, strtotime($u['fecha_creacion'])),
            'en_uso'         => isset($enUso[(int) $u['id_usuario']]),
        ], $db->table('usuarios u')
            ->select('u.id_usuario, u.nombre, u.usuario, u.id_rol, u.id_sucursal, u.activo, u.ultimo_acceso, u.fecha_creacion, r.nombre AS rol, s.nombre AS sucursal')
            ->join('roles r', 'r.id_rol = u.id_rol')
            ->join('sucursales s', 's.id_sucursal = u.id_sucursal', 'left')
            ->orderBy('u.activo', 'DESC')
            ->orderBy('u.nombre')
            ->get()->getResultArray());

        return [
            'usuarios'   => $usuarios,
            'yo'         => service('sesion')->idUsuario(),
            'roles'      => array_map(static fn (array $r) => [
                'id'          => (int) $r['id_rol'],
                'nombre'      => $r['nombre'],
                'descripcion' => $r['descripcion'],
                'usuarios'    => count(array_filter($usuarios, static fn ($u) => $u['activo'] && $u['id_rol'] === (int) $r['id_rol'])),
            ], $db->table('roles')->orderBy('id_rol')->get()->getResultArray()),
            'sucursales' => array_map(static fn (array $s) => [
                'id'     => (int) $s['id_sucursal'],
                'codigo' => $s['codigo'],
                'nombre' => $s['nombre'],
                'activo' => (bool) $s['activo'],
            ], $db->table('sucursales')->orderBy('es_matriz', 'DESC')->orderBy('codigo')->get()->getResultArray()),
        ];
    }
}
