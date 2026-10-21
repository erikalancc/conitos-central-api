<?php

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Models\SucursalModel;

/**
 * Administración de sucursales y sus terminales POS (solo Administrador).
 * Cada escritura responde con el listado completo para que el front reemplace su estado.
 */
class Sucursales extends BaseApiController
{
    private const MODALIDADES = [
        'mostrador'     => 'venta_mostrador',
        'para_llevar'   => 'para_llevar',
        'apps_delivery' => 'apps_delivery',
        'drive_thru'    => 'drive_thru',
    ];

    // GET /api/v1/admin/sucursales
    public function index()
    {
        return $this->ok($this->listado());
    }

    // POST /api/v1/admin/sucursales
    public function crear()
    {
        return $this->guardar(null);
    }

    // PUT /api/v1/admin/sucursales/{id} — acepta cambios parciales (p. ej. solo { "activo": false })
    public function actualizar(int $id)
    {
        if (model(SucursalModel::class)->find($id) === null) {
            return $this->failNotFound('La sucursal no existe.');
        }

        return $this->guardar($id);
    }

    // DELETE /api/v1/admin/sucursales/{id} — la matriz no se puede borrar
    public function eliminar(int $id)
    {
        $modelo   = model(SucursalModel::class);
        $sucursal = $modelo->find($id);
        if ($sucursal === null) {
            return $this->failNotFound('La sucursal no existe.');
        }
        if ($sucursal['es_matriz']) {
            return $this->fail(['error' => 'La sucursal matriz no se puede eliminar.'], 409);
        }

        $modelo->delete($id);

        return $this->ok(['id' => $id] + $this->listado());
    }

    private function guardar(?int $id)
    {
        $creando = $id === null;
        $datos   = $this->cuerpo();
        $modelo  = model(SucursalModel::class);

        // Textos vacíos = null
        foreach (['direccion', 'telefono', 'correo', 'hora_apertura', 'hora_cierre'] as $campo) {
            if (array_key_exists($campo, $datos) && is_string($datos[$campo]) && trim($datos[$campo]) === '') {
                $datos[$campo] = null;
            }
        }
        if (isset($datos['codigo']) && is_string($datos['codigo'])) {
            $datos['codigo'] = strtoupper(trim($datos['codigo']));
        }

        $unico  = $creando ? '' : ",id_sucursal,{$id}";
        $reglas = [
            'codigo'        => ['label' => 'código', 'rules' => "required|max_length[20]|regex_match[/^[A-Z0-9-]{3,20}$/]|is_unique[sucursales.codigo{$unico}]"],
            'nombre'        => ['label' => 'nombre', 'rules' => "required|max_length[100]|is_unique[sucursales.nombre{$unico}]"],
            'direccion'     => ['label' => 'dirección', 'rules' => 'required|max_length[255]'],
            'telefono'      => ['label' => 'teléfono', 'rules' => 'permit_empty|max_length[20]|regex_match[/^[0-9 +()-]{7,20}$/]'],
            'correo'        => ['label' => 'correo', 'rules' => 'permit_empty|max_length[120]|valid_email'],
            'id_encargado'  => ['label' => 'encargado', 'rules' => 'permit_empty|is_natural_no_zero|is_not_unique[usuarios.id_usuario]'],
            'hora_apertura' => ['label' => 'hora de apertura', 'rules' => 'permit_empty|regex_match[/^([01]\d|2[0-3]):[0-5]\d$/]'],
            'hora_cierre'   => ['label' => 'hora de cierre', 'rules' => 'permit_empty|regex_match[/^([01]\d|2[0-3]):[0-5]\d$/]'],
        ];
        if (! $creando) {
            $reglas = array_intersect_key($reglas, $datos);
        }
        // La matriz ya registrada puede no tener dirección; se pide al capturarla
        if ($reglas !== [] && ! $this->validateData($datos, $reglas, [
            'codigo' => ['regex_match' => 'Usa letras mayúsculas, números y guiones (p. ej. SUC-005).', 'is_unique' => 'Ya existe una sucursal con ese código.'],
            'nombre' => ['is_unique' => 'Ya existe una sucursal con ese nombre.'],
            'telefono' => ['regex_match' => 'Escribe un teléfono válido (solo números, espacios y guiones).'],
            'hora_apertura' => ['regex_match' => 'Usa el formato HH:MM.'],
            'hora_cierre' => ['regex_match' => 'Usa el formato HH:MM.'],
        ])) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $actual   = $creando ? [] : $modelo->find($id);
        $apertura = array_key_exists('hora_apertura', $datos) ? $datos['hora_apertura'] : ($actual['hora_apertura'] ?? null);
        $cierre   = array_key_exists('hora_cierre', $datos) ? $datos['hora_cierre'] : ($actual['hora_cierre'] ?? null);
        if ($apertura !== null && $cierre !== null && substr($cierre, 0, 5) <= substr($apertura, 0, 5)) {
            return $this->failValidationErrors(['hora_cierre' => 'La hora de cierre debe ser posterior a la de apertura.']);
        }

        $terminales = null;
        if ($creando || array_key_exists('terminales', $datos)) {
            $terminales = $datos['terminales'] ?? [];
            $error      = $this->validarTerminales($terminales, $id);
            if ($error !== null) {
                return $this->failValidationErrors($error);
            }
        }

        if (! $creando && $actual['es_matriz'] && array_key_exists('activo', $datos) && ! filter_var($datos['activo'], FILTER_VALIDATE_BOOLEAN)) {
            return $this->fail(['error' => 'La sucursal matriz no se puede desactivar.'], 409);
        }

        $columnas = array_intersect_key($datos, array_flip(['codigo', 'nombre', 'direccion', 'telefono', 'correo', 'id_encargado', 'hora_apertura', 'hora_cierre']));
        if (array_key_exists('id_encargado', $columnas) && empty($columnas['id_encargado'])) {
            $columnas['id_encargado'] = null;
        }
        foreach (self::MODALIDADES as $campo => $columna) {
            if (isset($datos['modalidades']) && is_array($datos['modalidades']) && array_key_exists($campo, $datos['modalidades'])) {
                $columnas[$columna] = filter_var($datos['modalidades'][$campo], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        if (array_key_exists('activo', $datos)) {
            $columnas['activo'] = filter_var($datos['activo'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        $id = $modelo->guardar($id, $columnas, $terminales);

        return $this->ok(['id' => $id] + $this->listado(), $creando ? 201 : 200);
    }

    private function validarTerminales($terminales, ?int $idSucursal): ?array
    {
        if (! is_array($terminales) || $terminales === []) {
            return ['terminales' => 'Agrega al menos una terminal (caja, tablet o kiosko).'];
        }
        if (count($terminales) > 20) {
            return ['terminales' => 'Máximo 20 terminales por sucursal.'];
        }

        $nombres = [];
        foreach (array_values($terminales) as $i => $t) {
            $nombre = trim((string) ($t['nombre'] ?? ''));
            if ($nombre === '' || mb_strlen($nombre) > 50) {
                return ["terminales.{$i}.nombre" => 'Cada terminal necesita un nombre (máx. 50 caracteres).'];
            }
            if (isset($nombres[mb_strtolower($nombre)])) {
                return ["terminales.{$i}.nombre" => "La terminal \"{$nombre}\" está repetida."];
            }
            $nombres[mb_strtolower($nombre)] = true;
            if (! in_array(strtoupper((string) ($t['tipo'] ?? 'CAJA')), SucursalModel::TIPOS_TERMINAL, true)) {
                return ["terminales.{$i}.tipo" => 'El tipo debe ser caja, tablet o kiosko.'];
            }
            if (isset($t['id'])) {
                $propia = db_connect()->table('terminales')->where('id_terminal', (int) $t['id'])->where('id_sucursal', $idSucursal)->countAllResults() > 0;
                if (! $propia) {
                    return ["terminales.{$i}.id" => 'La terminal no pertenece a esta sucursal.'];
                }
            }
        }

        return null;
    }

    // Sucursales, usuarios asignables como encargado e indicadores del encabezado
    private function listado(): array
    {
        $modelo     = model(SucursalModel::class);
        $sucursales = $modelo->listado();
        $hoy        = db_connect()->table('ventas')
            ->select('COUNT(*) AS tickets, COALESCE(SUM(total), 0) AS total')
            ->where('fecha_hora >=', date('Y-m-d 00:00:00'))
            ->get()->getRowArray();

        $terminalesActivas = 0;
        foreach ($sucursales as $s) {
            if ($s['activo']) {
                $terminalesActivas += count(array_filter($s['terminales'], static fn ($t) => $t['activo']));
            }
        }

        return [
            'sucursales'       => $sucursales,
            'siguiente_codigo' => $modelo->siguienteCodigo(),
            'usuarios'         => array_map(static fn (array $u) => [
                'id'     => (int) $u['id_usuario'],
                'nombre' => $u['nombre'],
                'rol'    => $u['rol'],
            ], db_connect()->table('usuarios u')
                ->select('u.id_usuario, u.nombre, r.nombre AS rol')
                ->join('roles r', 'r.id_rol = u.id_rol')
                ->where('u.activo', 1)
                ->orderBy('u.nombre')
                ->get()->getResultArray()),
            'resumen' => [
                'sucursales_activas'  => count(array_filter($sucursales, static fn ($s) => $s['activo'])),
                'terminales_activas'  => $terminalesActivas,
                'ventas_hoy'          => (int) $hoy['tickets'],
                'total_hoy'           => round((float) $hoy['total'], 2),
                'ticket_promedio_hoy' => (int) $hoy['tickets'] ? round((float) $hoy['total'] / (int) $hoy['tickets'], 2) : 0,
            ],
        ];
    }
}
