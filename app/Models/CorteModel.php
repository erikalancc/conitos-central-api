<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Turnos de caja (cortes_caja): apertura con fondo, movimientos de efectivo y corte Z.
 */
class CorteModel extends Model
{
    public const ID_EFECTIVO = 1;

    // Denominaciones que se cuentan en gaveta (MXN)
    public const DENOMINACIONES = [1000, 500, 200, 100, 50, 20, 10, 5, 2, 1, 0.5];

    protected $table         = 'cortes_caja';
    protected $primaryKey    = 'id_corte';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'estado', 'id_sucursal', 'id_usuario_apertura', 'fecha_apertura', 'fondo_inicial', 'comentario_apertura',
        'id_usuario_cierre', 'fecha_cierre', 'tickets', 'ventas_brutas', 'descuentos', 'ventas_netas',
        'ventas_efectivo', 'ventas_tarjeta', 'entradas', 'retiros', 'efectivo_esperado', 'efectivo_contado', 'fondo_siguiente',
        'diferencia_efectivo', 'tarjeta_terminal', 'vouchers', 'diferencia_tarjeta', 'observaciones',
    ];

    // Turno abierto de la sucursal: cada sucursal tiene su propia caja
    public function abierto(?int $idSucursal): ?array
    {
        return $this->where('estado', 'ABIERTO')->where('id_sucursal', $idSucursal)->orderBy('id_corte', 'DESC')->first();
    }

    // Fondo que se dejó en el último corte de la sucursal: se propone como fondo inicial al abrir caja (null si no hay)
    public function fondoSugerido(?int $idSucursal): ?float
    {
        $fila = $this->select('fondo_siguiente')->where('estado', 'CERRADO')->where('id_sucursal', $idSucursal)
            ->where('fondo_siguiente IS NOT NULL')->orderBy('fecha_cierre', 'DESC')->first();

        return $fila === null ? null : round((float) $fila['fondo_siguiente'], 2);
    }

    /**
     * Abre un turno de la sucursal y le asigna las ventas de hoy de esa sucursal que se cobraron sin caja abierta.
     */
    public function abrir(?int $idSucursal, int $idUsuario, float $fondo, ?string $comentario): int
    {
        $this->db->transException(true)->transStart();

        $id = (int) $this->insert([
            'estado'              => 'ABIERTO',
            'id_sucursal'         => $idSucursal,
            'id_usuario_apertura' => $idUsuario,
            'fecha_apertura'      => date('Y-m-d H:i:s'),
            'fondo_inicial'       => $fondo,
            'comentario_apertura' => $comentario,
        ]);

        $this->db->table('ventas')
            ->where('id_corte', null)
            ->where('id_sucursal', $idSucursal)
            ->where('fecha_hora >=', date('Y-m-d 00:00:00'))
            ->update(['id_corte' => $id]);

        $this->db->transComplete();

        return $id;
    }

    // Totales de las ventas del turno, con el desglose por método de pago
    public function totalesVentas(int $idCorte): array
    {
        $fila = $this->db->table('ventas')
            ->select('COUNT(*) AS tickets, COALESCE(SUM(subtotal), 0) AS brutas, COALESCE(SUM(descuento), 0) AS descuentos, COALESCE(SUM(total), 0) AS netas')
            ->where('id_corte', $idCorte)
            ->get()->getRowArray();

        $porMetodo = array_map(static fn (array $m) => [
            'id_metodo' => (int) $m['id_metodo'],
            'nombre'    => $m['nombre'],
            'tickets'   => (int) $m['tickets'],
            'total'     => round((float) $m['total'], 2),
        ], $this->db->table('ventas v')
            ->select('m.id_metodo, m.nombre, COUNT(*) AS tickets, SUM(v.total) AS total')
            ->join('metodos_pago m', 'm.id_metodo = v.id_metodo_pago')
            ->where('v.id_corte', $idCorte)
            ->groupBy('m.id_metodo, m.nombre')
            ->orderBy('m.id_metodo')
            ->get()->getResultArray());

        $efectivo = 0.0;
        $tarjeta  = 0.0;
        foreach ($porMetodo as $m) {
            if ($m['id_metodo'] === self::ID_EFECTIVO) {
                $efectivo += $m['total'];
            } else {
                $tarjeta += $m['total'];
            }
        }

        return [
            'tickets'         => (int) $fila['tickets'],
            'ventas_brutas'   => round((float) $fila['brutas'], 2),
            'descuentos'      => round((float) $fila['descuentos'], 2),
            'ventas_netas'    => round((float) $fila['netas'], 2),
            'ventas_efectivo' => round($efectivo, 2),
            'ventas_tarjeta'  => round($tarjeta, 2),
            'por_metodo'      => $porMetodo,
        ];
    }

    public function movimientos(int $idCorte): array
    {
        return array_map(static fn (array $m) => [
            'id'       => (int) $m['id_movimiento'],
            'tipo'     => strtolower($m['tipo']),
            'monto'    => round((float) $m['monto'], 2),
            'concepto' => $m['concepto'],
            'usuario'  => $m['usuario'],
            'fecha'    => date(DATE_ATOM, strtotime($m['fecha_hora'])),
        ], $this->db->table('movimientos_caja mc')
            ->select('mc.*, u.nombre AS usuario')
            ->join('usuarios u', 'u.id_usuario = mc.id_usuario')
            ->where('mc.id_corte', $idCorte)
            ->orderBy('mc.fecha_hora')
            ->get()->getResultArray());
    }

    public function registrarMovimiento(int $idCorte, int $idUsuario, string $tipo, float $monto, string $concepto): void
    {
        $this->db->table('movimientos_caja')->insert([
            'id_corte'   => $idCorte,
            'id_usuario' => $idUsuario,
            'tipo'       => strtoupper($tipo),
            'monto'      => $monto,
            'concepto'   => $concepto,
            'fecha_hora' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Cierra el turno: congela los totales y guarda el conteo de gaveta.
     *
     * @param array<string, int> $conteo denominación => piezas
     */
    public function cerrar(int $idCorte, int $idUsuario, array $resultado, array $conteo): void
    {
        $this->db->transException(true)->transStart();

        $this->update($idCorte, $resultado + [
            'estado'            => 'CERRADO',
            'id_usuario_cierre' => $idUsuario,
            'fecha_cierre'      => date('Y-m-d H:i:s'),
        ]);

        foreach ($conteo as $denominacion => $piezas) {
            if ($piezas > 0) {
                $this->db->table('cortes_caja_conteo')->insert(['id_corte' => $idCorte, 'denominacion' => $denominacion, 'piezas' => $piezas]);
            }
        }

        $this->db->transComplete();
    }

    // Turnos cerrados de una sucursal que se abrieron entre dos fechas (más recientes primero)
    public function cerradosEntre(string $desde, string $hasta, ?int $idSucursal): array
    {
        return array_column($this->select('id_corte')
            ->where('estado', 'CERRADO')
            ->where('id_sucursal', $idSucursal)
            ->where('fecha_apertura >=', $desde . ' 00:00:00')
            ->where('fecha_apertura <=', $hasta . ' 23:59:59')
            ->orderBy('fecha_apertura', 'DESC')
            ->findAll(), 'id_corte');
    }

    /**
     * Turno completo para el front. En uno abierto los totales se calculan en vivo;
     * en uno cerrado se usan los congelados al cerrar.
     */
    public function detalle(int $idCorte, bool $conVentas = false): ?array
    {
        $c = $this->db->table('cortes_caja c')
            ->select('c.*, ua.nombre AS usuario_apertura, uc.nombre AS usuario_cierre, s.nombre AS sucursal')
            ->join('sucursales s', 's.id_sucursal = c.id_sucursal', 'left')
            ->join('usuarios ua', 'ua.id_usuario = c.id_usuario_apertura')
            ->join('usuarios uc', 'uc.id_usuario = c.id_usuario_cierre', 'left')
            ->where('c.id_corte', $idCorte)
            ->get()->getRowArray();

        if ($c === null) {
            return null;
        }

        $cerrado     = $c['estado'] === 'CERRADO';
        $ventas      = $this->totalesVentas($idCorte);
        $movimientos = $this->movimientos($idCorte);
        $fondo       = round((float) $c['fondo_inicial'], 2);
        $entradas    = round(array_sum(array_column(array_filter($movimientos, static fn ($m) => $m['tipo'] === 'entrada'), 'monto')), 2);
        $retiros     = round(array_sum(array_column(array_filter($movimientos, static fn ($m) => $m['tipo'] === 'retiro'), 'monto')), 2);
        $numero      = static fn ($valor) => $valor === null ? null : round((float) $valor, 2);

        $conteo = [];
        foreach ($this->db->table('cortes_caja_conteo')->where('id_corte', $idCorte)->orderBy('denominacion', 'DESC')->get()->getResultArray() as $fila) {
            $conteo[] = ['denominacion' => (float) $fila['denominacion'], 'piezas' => (int) $fila['piezas']];
        }

        $detalle = [
            'id'       => (int) $c['id_corte'],
            'estado'   => strtolower($c['estado']),
            'sucursal' => $c['id_sucursal'] === null ? null : ['id' => (int) $c['id_sucursal'], 'nombre' => $c['sucursal']],
            'apertura' => [
                'fecha'      => date(DATE_ATOM, strtotime($c['fecha_apertura'])),
                'usuario'    => $c['usuario_apertura'],
                'fondo'      => $fondo,
                'comentario' => $c['comentario_apertura'],
            ],
            'cierre'   => $cerrado ? ['fecha' => date(DATE_ATOM, strtotime($c['fecha_cierre'])), 'usuario' => $c['usuario_cierre']] : null,
            'ventas'   => $ventas,
            'efectivo' => [
                'fondo'      => $fondo,
                'ventas'     => $ventas['ventas_efectivo'],
                'entradas'   => $entradas,
                'retiros'    => $retiros,
                'esperado'   => $cerrado ? $numero($c['efectivo_esperado']) : round($fondo + $ventas['ventas_efectivo'] + $entradas - $retiros, 2),
                'contado'    => $numero($c['efectivo_contado']),
                'diferencia' => $numero($c['diferencia_efectivo']),
                // Lo que se dejó en gaveta para el siguiente turno; el resto se entregó a depósito
                'fondo_siguiente' => $numero($c['fondo_siguiente']),
                'conteo'     => $conteo,
            ],
            'tarjeta' => [
                'sistema'    => $ventas['ventas_tarjeta'],
                'terminal'   => $numero($c['tarjeta_terminal']),
                'vouchers'   => $c['vouchers'] === null ? null : (int) $c['vouchers'],
                'diferencia' => $numero($c['diferencia_tarjeta']),
            ],
            'observaciones' => $c['observaciones'],
            'movimientos'   => $movimientos,
        ];

        if ($conVentas) {
            $detalle['ventas_detalle'] = model(VentaModel::class)->deCorte($idCorte);
        }

        return $detalle;
    }
}
