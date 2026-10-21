<?php

namespace App\Models;

use CodeIgniter\Model;
use RuntimeException;

class VentaModel extends Model
{
    protected $table         = 'ventas';
    protected $primaryKey    = 'id_venta';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'folio_orden', 'id_corte', 'id_usuario_vendedor', 'fecha_hora', 'cliente', 'tipo_servicio', 'id_metodo_pago',
        'subtotal', 'descuento', 'descuento_concepto', 'total', 'monto_recibido', 'cambio',
    ];

    /**
     * Guarda la venta con su detalle, sabores y extras en una sola transacción.
     *
     * @param list<array{id_presentacion:?int, id_promocion:?int, cantidad:int, precio_unitario:float, subtotal:float, notas:?string, sabores:list<int>, extras:list<array{id:int, precio:float}>}> $lineas
     */
    public function registrar(array $venta, array $lineas): int
    {
        $this->db->transException(true)->transStart();

        $idVenta = $this->insert($venta);
        if ($idVenta === false) {
            throw new RuntimeException('No se pudo guardar la venta.');
        }

        foreach ($lineas as $linea) {
            $this->db->table('detalle_ventas')->insert([
                'id_venta'        => $idVenta,
                'id_presentacion' => $linea['id_presentacion'],
                'id_promocion'    => $linea['id_promocion'],
                'cantidad'        => $linea['cantidad'],
                'precio_unitario' => $linea['precio_unitario'],
                'subtotal'        => $linea['subtotal'],
                'notas'           => $linea['notas'],
            ]);
            $idDetalle = $this->db->insertID();

            // Un renglón por porción de sabor (2× Vainilla = 2 renglones)
            foreach ($linea['sabores'] as $idSabor) {
                $this->db->table('detalle_venta_sabores')->insert(['id_detalle' => $idDetalle, 'id_sabor' => $idSabor]);
            }
            foreach ($linea['extras'] as $extra) {
                $this->db->table('detalle_venta_extras')->insert([
                    'id_detalle'      => $idDetalle,
                    'id_extra'        => $extra['id'],
                    'cantidad'        => 1,
                    'precio_unitario' => $extra['precio'],
                ]);
            }
        }

        $this->db->transComplete();

        return (int) $idVenta;
    }

    /**
     * Totales por día entre dos fechas (inclusive), del más reciente al más antiguo.
     * Cada día trae el desglose por método de pago y quién vendió.
     */
    public function resumenPorDia(string $desde, string $hasta): array
    {
        $rango = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

        $dias = $this->db->query(
            'SELECT DATE(v.fecha_hora) AS dia, COUNT(*) AS tickets, SUM(v.subtotal) AS subtotal, SUM(v.descuento) AS descuento,
                    SUM(v.total) AS total, GROUP_CONCAT(DISTINCT u.nombre ORDER BY u.nombre SEPARATOR ", ") AS vendedores
             FROM ventas v JOIN usuarios u ON u.id_usuario = v.id_usuario_vendedor
             WHERE v.fecha_hora BETWEEN ? AND ?
             GROUP BY DATE(v.fecha_hora) ORDER BY dia DESC',
            $rango
        )->getResultArray();

        $articulos = array_column($this->db->query(
            'SELECT DATE(v.fecha_hora) AS dia, SUM(d.cantidad) AS articulos
             FROM ventas v JOIN detalle_ventas d ON d.id_venta = v.id_venta
             WHERE v.fecha_hora BETWEEN ? AND ? GROUP BY DATE(v.fecha_hora)',
            $rango
        )->getResultArray(), 'articulos', 'dia');

        $porMetodo = [];
        $filas     = $this->db->query(
            'SELECT DATE(v.fecha_hora) AS dia, m.id_metodo, m.nombre, COUNT(*) AS tickets, SUM(v.total) AS total
             FROM ventas v JOIN metodos_pago m ON m.id_metodo = v.id_metodo_pago
             WHERE v.fecha_hora BETWEEN ? AND ?
             GROUP BY DATE(v.fecha_hora), m.id_metodo, m.nombre ORDER BY m.id_metodo',
            $rango
        )->getResultArray();
        foreach ($filas as $f) {
            $porMetodo[$f['dia']][] = [
                'id_metodo' => (int) $f['id_metodo'],
                'nombre'    => $f['nombre'],
                'tickets'   => (int) $f['tickets'],
                'total'     => round((float) $f['total'], 2),
            ];
        }

        return array_map(static fn (array $d) => [
            'fecha'      => $d['dia'],
            'tickets'    => (int) $d['tickets'],
            'articulos'  => (int) ($articulos[$d['dia']] ?? 0),
            'subtotal'   => round((float) $d['subtotal'], 2),
            'descuento'  => round((float) $d['descuento'], 2),
            'total'      => round((float) $d['total'], 2),
            'por_metodo' => $porMetodo[$d['dia']] ?? [],
            'vendedores' => $d['vendedores'] === null ? [] : explode(', ', $d['vendedores']),
        ], $dias);
    }

    // Ventas de un día con su detalle (productos, sabores, extras), en orden de hora
    public function delDia(string $fecha): array
    {
        return $this->conDetalle(fn ($query) => $query
            ->where('v.fecha_hora >=', $fecha . ' 00:00:00')
            ->where('v.fecha_hora <=', $fecha . ' 23:59:59'));
    }

    // Ventas de un turno de caja con su detalle
    public function deCorte(int $idCorte): array
    {
        return $this->conDetalle(fn ($query) => $query->where('v.id_corte', $idCorte));
    }

    /**
     * @param callable(\CodeIgniter\Database\BaseBuilder): mixed $filtro
     */
    private function conDetalle(callable $filtro): array
    {
        $query = $this->db->table('ventas v')
            ->select('v.*, u.nombre AS vendedor, m.nombre AS metodo_nombre')
            ->join('usuarios u', 'u.id_usuario = v.id_usuario_vendedor')
            ->join('metodos_pago m', 'm.id_metodo = v.id_metodo_pago')
            ->orderBy('v.fecha_hora');
        $filtro($query);
        $ventas = $query->get()->getResultArray();

        if ($ventas === []) {
            return [];
        }

        $idsVenta = array_column($ventas, 'id_venta');
        $detalles = $this->db->table('detalle_ventas d')
            ->select('d.*, p.nombre AS presentacion, pr.nombre AS producto, pr.id_producto, pm.nombre AS promocion,
                      (SELECT COUNT(*) FROM presentaciones p2 WHERE p2.id_producto = pr.id_producto) AS num_presentaciones')
            ->join('presentaciones p', 'p.id_presentacion = d.id_presentacion', 'left')
            ->join('productos pr', 'pr.id_producto = p.id_producto', 'left')
            ->join('promociones pm', 'pm.id_promocion = d.id_promocion', 'left')
            ->whereIn('d.id_venta', $idsVenta)
            ->orderBy('d.id_detalle')
            ->get()->getResultArray();

        $idsDetalle = array_column($detalles, 'id_detalle');
        $sabores    = [];
        $extras     = [];
        if ($idsDetalle !== []) {
            $filas = $this->db->table('detalle_venta_sabores ds')
                ->select('ds.id_detalle, s.id_sabor, s.nombre, COUNT(*) AS cantidad')
                ->join('sabores s', 's.id_sabor = ds.id_sabor')
                ->whereIn('ds.id_detalle', $idsDetalle)
                ->groupBy('ds.id_detalle, s.id_sabor, s.nombre')
                ->orderBy('MIN(ds.id_detalle_sabor)')
                ->get()->getResultArray();
            foreach ($filas as $f) {
                $sabores[$f['id_detalle']][] = ['id' => (int) $f['id_sabor'], 'nombre' => $f['nombre'], 'cantidad' => (int) $f['cantidad']];
            }

            $filas = $this->db->table('detalle_venta_extras de')
                ->select('de.id_detalle, e.id_extra, e.nombre, de.precio_unitario, de.cantidad')
                ->join('extras_toppings e', 'e.id_extra = de.id_extra')
                ->whereIn('de.id_detalle', $idsDetalle)
                ->get()->getResultArray();
            foreach ($filas as $f) {
                $extras[$f['id_detalle']][] = [
                    'id'       => (int) $f['id_extra'],
                    'nombre'   => $f['nombre'],
                    'precio'   => round((float) $f['precio_unitario'], 2),
                    'cantidad' => (int) $f['cantidad'],
                ];
            }
        }

        $lineas = [];
        foreach ($detalles as $d) {
            $lineas[$d['id_venta']][] = [
                'id'              => (int) $d['id_detalle'],
                'id_producto'     => $d['id_producto'] === null ? null : (int) $d['id_producto'],
                'id_promocion'    => $d['id_promocion'] === null ? null : (int) $d['id_promocion'],
                'nombre'          => $d['promocion'] ?? $d['producto'] ?? 'Producto eliminado del catálogo',
                // "Única" no aporta: solo se informa si el producto tiene varias presentaciones
                'presentacion'    => $d['presentacion'] !== null && (int) $d['num_presentaciones'] > 1 ? $d['presentacion'] : null,
                'cantidad'        => (int) $d['cantidad'],
                'precio_unitario' => round((float) $d['precio_unitario'], 2),
                'subtotal'        => round((float) $d['subtotal'], 2),
                'notas'           => $d['notas'],
                'sabores'         => $sabores[$d['id_detalle']] ?? [],
                'extras'          => $extras[$d['id_detalle']] ?? [],
            ];
        }

        return array_map(static fn (array $v) => [
            'id'                 => (int) $v['id_venta'],
            'folio_orden'        => $v['folio_orden'] === null ? null : (int) $v['folio_orden'],
            'fecha'              => date(DATE_ATOM, strtotime($v['fecha_hora'])),
            'vendedor'           => $v['vendedor'],
            'cliente'            => $v['cliente'],
            'servicio'           => $v['tipo_servicio'] === 'AQUI' ? 'aqui' : 'llevar',
            'metodo_pago'        => ['id' => (int) $v['id_metodo_pago'], 'nombre' => $v['metodo_nombre']],
            'subtotal'           => round((float) $v['subtotal'], 2),
            'descuento'          => round((float) $v['descuento'], 2),
            'descuento_concepto' => $v['descuento_concepto'],
            'total'              => round((float) $v['total'], 2),
            'monto_recibido'     => $v['monto_recibido'] === null ? null : round((float) $v['monto_recibido'], 2),
            'cambio'             => $v['cambio'] === null ? null : round((float) $v['cambio'], 2),
            'lineas'             => $lineas[$v['id_venta']] ?? [],
        ], $ventas);
    }
}
