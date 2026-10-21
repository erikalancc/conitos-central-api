<?php

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Models\CategoriaModel;
use App\Models\ExtraModel;
use App\Models\MetodoPagoModel;
use App\Models\PresentacionModel;
use App\Models\ProductoModel;
use App\Models\PromocionModel;
use App\Models\RecargoSaborModel;
use App\Models\SaborModel;

class Catalogo extends BaseApiController
{
    // GET /api/v1/catalogo — todo lo que necesita el POS para armar pedidos (solo lo activo)
    public function index()
    {
        // Recargos agrupados por presentación (p. ej. Piñón cuesta más en Chico/Grande)
        $recargos = [];
        foreach (model(RecargoSaborModel::class)->findAll() as $r) {
            $recargos[$r['id_presentacion']][] = [
                'id_sabor' => (int) $r['id_sabor'],
                'monto'    => (float) $r['monto_recargo'],
            ];
        }

        $presentaciones = [];
        foreach (model(PresentacionModel::class)->where('activo', 1)->orderBy('id_presentacion')->findAll() as $p) {
            $presentaciones[$p['id_producto']][] = [
                'id'          => (int) $p['id_presentacion'],
                'nombre'      => $p['nombre'],
                'precio_base' => (float) $p['precio_base'],
                'num_sabores' => (int) $p['num_sabores'],
                'recargos'    => $recargos[$p['id_presentacion']] ?? [],
            ];
        }

        // Extras activos que ofrece cada producto
        $extrasPorProducto = [];
        $filas             = db_connect()->table('productos_extras pe')
            ->select('pe.id_producto, pe.id_extra')
            ->join('extras_toppings e', 'e.id_extra = pe.id_extra')
            ->where('e.activo', 1)
            ->orderBy('pe.id_extra')
            ->get()->getResultArray();
        foreach ($filas as $fila) {
            $extrasPorProducto[$fila['id_producto']][] = (int) $fila['id_extra'];
        }

        $categorias   = model(CategoriaModel::class)->where('activo', 1)->orderBy('id_categoria')->findAll();
        $idsCategoria = array_column($categorias, 'id_categoria');

        // Sin categoría activa o sin presentaciones activas el producto no se puede vender
        $activos = array_filter(
            model(ProductoModel::class)->where('activo', 1)->orderBy('id_categoria')->orderBy('id_producto')->findAll(),
            static fn (array $p) => in_array($p['id_categoria'], $idsCategoria, false) && isset($presentaciones[$p['id_producto']])
        );

        $productos = array_map(static fn (array $p) => [
            'id'             => (int) $p['id_producto'],
            'id_categoria'   => (int) $p['id_categoria'],
            'nombre'         => $p['nombre'],
            'requiere_sabor' => (bool) $p['requiere_sabor'],
            'tipo_sabor'     => $p['tipo_sabor'],
            'presentaciones' => $presentaciones[$p['id_producto']] ?? [],
            'extras'         => $extrasPorProducto[$p['id_producto']] ?? [],
        ], array_values($activos));

        return $this->ok([
            'categorias' => array_map(static fn (array $c) => [
                'id'     => (int) $c['id_categoria'],
                'nombre' => $c['nombre'],
            ], $categorias),

            'productos' => $productos,

            'sabores' => array_map(static fn (array $s) => [
                'id'          => (int) $s['id_sabor'],
                'nombre'      => $s['nombre'],
                'tipo'        => $s['tipo_sabor'],
                'es_especial' => (bool) $s['es_especial'],
            ], model(SaborModel::class)->where('activo', 1)->orderBy('nombre')->findAll()),

            'extras' => array_map(static fn (array $e) => [
                'id'     => (int) $e['id_extra'],
                'nombre' => $e['nombre'],
                'precio' => (float) $e['precio'],
            ], model(ExtraModel::class)->where('activo', 1)->orderBy('id_extra')->findAll()),

            'promociones' => array_map(static fn (array $p) => [
                'id'           => (int) $p['id_promocion'],
                'nombre'       => $p['nombre'],
                'precio_combo' => (float) $p['precio_combo'],
                'dia_semana'   => $p['dia_semana'] === null ? null : (int) $p['dia_semana'],
            ], model(PromocionModel::class)->where('activa', 1)->findAll()),

            'metodos_pago' => array_map(static fn (array $m) => [
                'id'     => (int) $m['id_metodo'],
                'nombre' => $m['nombre'],
            ], model(MetodoPagoModel::class)->orderBy('id_metodo')->findAll()),
        ]);
    }
}
