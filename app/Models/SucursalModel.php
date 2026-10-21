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
        'venta_mostrador', 'para_llevar', 'apps_delivery', 'drive_thru', 'es_matriz', 'activo',
    ];

    /**
     * Guarda la sucursal y, si vienen, reemplaza sus terminales
     * (con id: se actualizan; sin id: se crean; las que no vienen se borran).
     */
    public function guardar(?int $id, array $columnas, ?array $terminales): int
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
            'es_matriz'     => (bool) $s['es_matriz'],
            'activo'        => (bool) $s['activo'],
            'terminales'    => $terminales[$s['id_sucursal']] ?? [],
        ], $this->db->table('sucursales s')
            ->select('s.*, u.nombre AS encargado')
            ->join('usuarios u', 'u.id_usuario = s.id_encargado', 'left')
            ->orderBy('s.es_matriz', 'DESC')
            ->orderBy('s.codigo')
            ->get()->getResultArray());
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
