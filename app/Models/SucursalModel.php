<?php

namespace App\Models;

use CodeIgniter\Model;

class SucursalModel extends Model
{
    public const TIPOS_TERMINAL = ['CAJA', 'TABLET', 'KIOSKO'];

    protected $table         = 'sucursales';
    protected $primaryKey    = 'id_sucursal';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'codigo', 'nombre', 'direccion', 'telefono', 'correo', 'id_encargado', 'hora_apertura', 'hora_cierre',
        'venta_mostrador', 'para_llevar', 'apps_delivery', 'drive_thru', 'ventas_visibles_vendedor', 'es_matriz', 'activo',
    ];

    /**
     * Guarda la sucursal y, si vienen, reemplaza sus terminales
     * (con id: se actualizan; sin id: se crean; las que no vienen se borran).
     */
    public function guardar(?int $id, array $columnas, ?array $terminales, ?array $equipos = null): int
    {
        $this->db->transException(true)->transStart();

        if ($id === null) {
            $id = (int) $this->insert($columnas);
        } elseif ($columnas !== []) {
            $this->update($id, $columnas);
        }

        if ($terminales !== null) {
            $tabla      = $this->db->table('terminales');
            $existentes = array_column($tabla->where('id_sucursal', $id)->get()->getResultArray(), null, 'id_terminal');
            $recibidas  = [];

            foreach ($terminales as $t) {
                $fila = [
                    'id_sucursal' => $id,
                    'nombre'      => trim($t['nombre']),
                    'tipo'        => strtoupper($t['tipo'] ?? 'CAJA'),
                    'activo'      => filter_var($t['activo'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                ];
                if (isset($t['id'], $existentes[$t['id']])) {
                    $this->db->table('terminales')->where('id_terminal', (int) $t['id'])->update($fila);
                    $recibidas[(int) $t['id']] = true;
                } else {
                    $this->db->table('terminales')->insert($fila);
                }
            }

            $sobrantes = array_diff(array_keys($existentes), array_keys($recibidas));
            if ($sobrantes !== []) {
                $this->db->table('terminales')->whereIn('id_terminal', $sobrantes)->delete();
            }
        }

        // Congeladores y refrigeradores (mismo criterio que las terminales). Las lecturas pasadas
        // conservan nombre y rango aunque el equipo se borre (id_equipo queda en NULL)
        if ($equipos !== null) {
            $existentes = array_column($this->db->table('equipos_frio')->where('id_sucursal', $id)->get()->getResultArray(), null, 'id_equipo');
            $recibidos  = [];

            foreach ($equipos as $e) {
                $fila = [
                    'id_sucursal' => $id,
                    'nombre'      => trim($e['nombre']),
                    'tipo'        => strtoupper($e['tipo'] ?? 'CONGELADOR'),
                    'temp_min'    => round((float) $e['temp_min'], 2),
                    'temp_max'    => round((float) $e['temp_max'], 2),
                    'activo'      => filter_var($e['activo'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                ];
                if (isset($e['id'], $existentes[$e['id']])) {
                    $this->db->table('equipos_frio')->where('id_equipo', (int) $e['id'])->update($fila);
                    $recibidos[(int) $e['id']] = true;
                } else {
                    $this->db->table('equipos_frio')->insert($fila);
                }
            }

            $sobrantes = array_diff(array_keys($existentes), array_keys($recibidos));
            if ($sobrantes !== []) {
                $this->db->table('equipos_frio')->whereIn('id_equipo', $sobrantes)->delete();
            }
        }

        $this->db->transComplete();

        return $id;
    }

    // Todas las sucursales con su encargado y terminales (matriz primero)
    public function listado(): array
    {
        $terminales = [];
        foreach ($this->db->table('terminales')->orderBy('id_terminal')->get()->getResultArray() as $t) {
            $terminales[$t['id_sucursal']][] = [
                'id'     => (int) $t['id_terminal'],
                'nombre' => $t['nombre'],
                'tipo'   => strtolower($t['tipo']),
                'activo' => (bool) $t['activo'],
            ];
        }

        $equipos = [];
        foreach ($this->db->table('equipos_frio')->orderBy('tipo')->orderBy('nombre')->get()->getResultArray() as $e) {
            $equipos[$e['id_sucursal']][] = self::equipoPublico($e);
        }

        $hora = static fn ($valor) => $valor === null ? null : substr($valor, 0, 5);

        return array_map(static fn (array $s) => [
            'id'            => (int) $s['id_sucursal'],
            'codigo'        => $s['codigo'],
            'nombre'        => $s['nombre'],
            'direccion'     => $s['direccion'],
            'telefono'      => $s['telefono'],
            'correo'        => $s['correo'],
            'encargado'     => $s['id_encargado'] === null ? null : ['id' => (int) $s['id_encargado'], 'nombre' => $s['encargado']],
            'hora_apertura' => $hora($s['hora_apertura']),
            'hora_cierre'   => $hora($s['hora_cierre']),
            'modalidades'   => [
                'mostrador'     => (bool) $s['venta_mostrador'],
                'para_llevar'   => (bool) $s['para_llevar'],
                'apps_delivery' => (bool) $s['apps_delivery'],
                'drive_thru'    => (bool) $s['drive_thru'],
            ],
            'ventas_visibles_vendedor' => (int) $s['ventas_visibles_vendedor'],
            'es_matriz'     => (bool) $s['es_matriz'],
            'activo'        => (bool) $s['activo'],
            'terminales'    => $terminales[$s['id_sucursal']] ?? [],
            'equipos_frio'  => $equipos[$s['id_sucursal']] ?? [],
        ], $this->db->table('sucursales s')
            ->select('s.*, u.nombre AS encargado')
            ->join('usuarios u', 'u.id_usuario = s.id_encargado', 'left')
            ->orderBy('s.es_matriz', 'DESC')
            ->orderBy('s.codigo')
            ->get()->getResultArray());
    }

    public const TIPOS_EQUIPO = ['CONGELADOR', 'REFRIGERADOR'];

    public static function equipoPublico(array $e): array
    {
        return [
            'id'       => (int) $e['id_equipo'],
            'nombre'   => $e['nombre'],
            'tipo'     => strtolower($e['tipo']),
            'temp_min' => (float) $e['temp_min'],
            'temp_max' => (float) $e['temp_max'],
            'activo'   => (bool) $e['activo'],
        ];
    }

    /**
     * Hora (HH:MM) a partir de la cual se pide el checklist de mitad del turno: la mitad del horario
     * de la sucursal (hora_apertura a hora_cierre). Sin horario se usa 10:00 a 21:00.
     */
    public function medioTurno(?int $idSucursal): string
    {
        $s        = $this->select('hora_apertura, hora_cierre')->find($idSucursal);
        $minutos  = static fn (?string $hora, string $defecto) => (int) substr($hora ?: $defecto, 0, 2) * 60 + (int) substr($hora ?: $defecto, 3, 2);
        $apertura = $minutos($s['hora_apertura'] ?? null, '10:00');
        $cierre   = $minutos($s['hora_cierre'] ?? null, '21:00');
        $mitad    = intdiv($apertura + max($cierre, $apertura), 2);

        return sprintf('%02d:%02d', intdiv($mitad, 60), $mitad % 60);
    }

    // Equipos activos de una sucursal (los que se revisan en el checklist de apertura)
    public function equiposActivos(?int $idSucursal): array
    {
        return array_map(
            [self::class, 'equipoPublico'],
            $this->db->table('equipos_frio')->where('id_sucursal', $idSucursal)->where('activo', 1)
                ->orderBy('tipo')->orderBy('nombre')->get()->getResultArray()
        );
    }

    // Siguiente código libre con el formato SUC-###
    public function siguienteCodigo(): string
    {
        $numeros = array_map(
            static fn ($codigo) => (int) preg_replace('/\D/', '', $codigo),
            array_column($this->select('codigo')->like('codigo', 'SUC-', 'after')->findAll(), 'codigo')
        );

        return sprintf('SUC-%03d', ($numeros === [] ? 0 : max($numeros)) + 1);
    }
}
