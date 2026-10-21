<?php

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Models\CorteModel;
use App\Models\MetodoPagoModel;
use App\Models\VentaModel;

/**
 * Registro de ventas del POS. Los precios se recalculan con el catálogo vigente;
 * lo que manda el front solo se usa para detectar que su catálogo quedó desactualizado.
 */
class Ventas extends BaseApiController
{
    private const ID_EFECTIVO = 1;

    // Los descuentos aún no viven en la BD: se acepta el que manda el POS con estos límites
    private const TIPOS_DESCUENTO = ['porcentaje', 'monto'];

    // Rango máximo de GET /ventas/resumen
    private const MAX_DIAS_RESUMEN = 366;

    // GET /api/v1/ventas/resumen?desde=AAAA-MM-DD&hasta=AAAA-MM-DD — totales por día (default: últimas 8 semanas)
    public function resumen()
    {
        $hasta = $this->request->getGet('hasta') ?: date('Y-m-d');
        $desde = $this->request->getGet('desde') ?: date('Y-m-d', strtotime($hasta . ' -55 days'));

        $errores = [];
        foreach (['desde' => $desde, 'hasta' => $hasta] as $campo => $valor) {
            if (! $this->esFecha($valor)) {
                $errores[$campo] = 'Usa el formato AAAA-MM-DD.';
            }
        }
        if ($errores === [] && $desde > $hasta) {
            $errores['desde'] = 'La fecha inicial es posterior a la final.';
        }
        if ($errores === [] && (strtotime($hasta) - strtotime($desde)) / 86400 >= self::MAX_DIAS_RESUMEN) {
            $errores['desde'] = 'El rango no puede pasar de ' . self::MAX_DIAS_RESUMEN . ' días.';
        }
        if ($errores !== []) {
            return $this->failValidationErrors($errores);
        }

        return $this->ok([
            'desde' => $desde,
            'hasta' => $hasta,
            'dias'  => model(VentaModel::class)->resumenPorDia($desde, $hasta),
            // Para ofrecer "ver semanas anteriores" solo si de verdad hay ventas antes del rango
            'hay_anteriores' => model(VentaModel::class)->where('fecha_hora <', $desde . ' 00:00:00')->countAllResults() > 0,
        ]);
    }

    // GET /api/v1/ventas?fecha=AAAA-MM-DD — ventas del día con su detalle (default: hoy)
    public function index()
    {
        $fecha = $this->request->getGet('fecha') ?: date('Y-m-d');
        if (! $this->esFecha($fecha)) {
            return $this->failValidationErrors(['fecha' => 'Usa el formato AAAA-MM-DD.']);
        }

        return $this->ok([
            'fecha'  => $fecha,
            'ventas' => model(VentaModel::class)->delDia($fecha),
        ]);
    }

    private function esFecha($valor): bool
    {
        $fecha = is_string($valor) ? \DateTime::createFromFormat('!Y-m-d', $valor) : false;

        return $fecha !== false && $fecha->format('Y-m-d') === $valor;
    }

    // POST /api/v1/ventas
    public function crear()
    {
        $datos = $this->cuerpo();

        $reglas = [
            'id_metodo_pago' => ['label' => 'método de pago', 'rules' => 'required|is_natural_no_zero|is_not_unique[metodos_pago.id_metodo]'],
            'servicio'       => ['label' => 'servicio', 'rules' => 'permit_empty|in_list[aqui,llevar]'],
            'folio_orden'    => ['label' => 'folio', 'rules' => 'permit_empty|is_natural'],
            'cliente'        => ['label' => 'cliente', 'rules' => 'permit_empty|max_length[100]'],
            'total'          => ['label' => 'total', 'rules' => 'required|decimal'],
            'monto_recibido' => ['label' => 'monto recibido', 'rules' => 'permit_empty|decimal'],
        ];
        if (! $this->validateData($datos, $reglas)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $lineasRecibidas = $datos['lineas'] ?? null;
        if (! is_array($lineasRecibidas) || $lineasRecibidas === []) {
            return $this->failValidationErrors(['lineas' => 'La venta no tiene productos.']);
        }

        $lineas  = [];
        $errores = [];
        foreach (array_values($lineasRecibidas) as $i => $linea) {
            $resultado = is_array($linea) ? $this->tasarLinea($linea) : 'Línea inválida.';
            if (is_string($resultado)) {
                $errores["lineas.{$i}"] = $resultado;
            } else {
                $lineas[] = $resultado;
            }
        }
        if ($errores !== []) {
            return $this->failValidationErrors($errores);
        }

        $subtotal  = round(array_sum(array_column($lineas, 'subtotal')), 2);
        $descuento = $this->descuento($datos['descuento'] ?? null, $subtotal);
        if (is_string($descuento)) {
            return $this->failValidationErrors(['descuento' => $descuento]);
        }
        $total = round($subtotal - $descuento['monto'], 2);

        // Precios distintos a los del POS: su catálogo quedó viejo (alguien editó precios)
        if (abs($total - (float) $datos['total']) >= 0.01) {
            return $this->fail([
                'error' => 'Los precios cambiaron desde que se cargó el catálogo. Actualiza el catálogo y revisa la orden.',
                'total' => $total,
            ], 409);
        }

        $idMetodo = (int) $datos['id_metodo_pago'];
        $recibido = null;
        $cambio   = null;
        if ($idMetodo === self::ID_EFECTIVO) {
            $recibido = round((float) ($datos['monto_recibido'] ?? $total), 2);
            if ($recibido < $total) {
                return $this->failValidationErrors(['monto_recibido' => 'El efectivo recibido no cubre el total.']);
            }
            $cambio = round($recibido - $total, 2);
        }

        $idVenta = model(VentaModel::class)->registrar([
            'folio_orden'         => isset($datos['folio_orden']) ? (int) $datos['folio_orden'] : null,
            // Turno de caja abierto (si no hay, la venta se asigna al abrir caja ese día)
            'id_corte'            => model(CorteModel::class)->abierto()['id_corte'] ?? null,
            'id_usuario_vendedor' => service('sesion')->idUsuario(),
            'fecha_hora'          => date('Y-m-d H:i:s'),
            'cliente'             => trim((string) ($datos['cliente'] ?? '')) ?: null,
            'tipo_servicio'       => ($datos['servicio'] ?? 'llevar') === 'aqui' ? 'AQUI' : 'LLEVAR',
            'id_metodo_pago'      => $idMetodo,
            'subtotal'            => $subtotal,
            'descuento'           => $descuento['monto'],
            'descuento_concepto'  => $descuento['concepto'],
            'total'               => $total,
            'monto_recibido'      => $recibido,
            'cambio'              => $cambio,
        ], $lineas);

        $venta = model(VentaModel::class)->find($idVenta);

        return $this->ok([
            'id'             => $idVenta,
            'folio_orden'    => $venta['folio_orden'] === null ? null : (int) $venta['folio_orden'],
            'fecha'          => date(DATE_ATOM, strtotime($venta['fecha_hora'])),
            'metodo_pago'    => model(MetodoPagoModel::class)->find($idMetodo)['nombre'],
            'subtotal'       => $subtotal,
            'descuento'      => $descuento['monto'],
            'total'          => $total,
            'monto_recibido' => $recibido,
            'cambio'         => $cambio,
        ], 201);
    }

    /**
     * Valida una línea contra el catálogo vigente y calcula su precio.
     * Devuelve la línea lista para guardar, o el mensaje de error.
     */
    private function tasarLinea(array $linea): array|string
    {
        $db       = db_connect();
        $cantidad = $linea['cantidad'] ?? null;
        if (! is_int($cantidad) || $cantidad < 1 || $cantidad > 99) {
            return 'La cantidad debe ser de 1 a 99.';
        }
        $notas   = mb_substr(trim((string) ($linea['notas'] ?? '')), 0, 255) ?: null;
        $sabores = array_map('intval', (array) ($linea['sabores'] ?? []));
        $extras  = array_values(array_unique(array_map('intval', (array) ($linea['extras'] ?? []))));

        // Combo de promoción
        if (! empty($linea['id_promocion'])) {
            $promo = $db->table('promociones')->where('id_promocion', (int) $linea['id_promocion'])->where('activa', 1)->get()->getRowArray();
            if ($promo === null) {
                return 'La promoción ya no está activa.';
            }
            if ($promo['dia_semana'] !== null && (int) $promo['dia_semana'] !== (int) date('w') + 1) {
                return "La promoción \"{$promo['nombre']}\" no aplica hoy.";
            }
            if ($sabores !== [] || $extras !== []) {
                return 'Las promociones no llevan sabores ni extras.';
            }
            $precio = (float) $promo['precio_combo'];

            return $this->linea(null, (int) $promo['id_promocion'], $cantidad, $precio, $notas, [], []);
        }

        $presentacion = $db->table('presentaciones p')
            ->select('p.*, pr.nombre AS producto, pr.requiere_sabor, pr.tipo_sabor, pr.activo AS producto_activo, c.activo AS categoria_activa')
            ->join('productos pr', 'pr.id_producto = p.id_producto')
            ->join('categorias c', 'c.id_categoria = pr.id_categoria')
            ->where('p.id_presentacion', (int) ($linea['id_presentacion'] ?? 0))
            ->get()->getRowArray();

        if ($presentacion === null || ! $presentacion['activo'] || ! $presentacion['producto_activo'] || ! $presentacion['categoria_activa']) {
            return 'El producto ya no está disponible.';
        }

        $producto = $presentacion['producto'];
        $precio   = (float) $presentacion['precio_base'];

        if ($presentacion['requiere_sabor']) {
            $esperados = (int) $presentacion['num_sabores'];
            if (count($sabores) !== $esperados) {
                return "{$producto}: elige {$esperados} sabor(es).";
            }
            $validos = array_column(
                $db->table('sabores')->select('id_sabor')->whereIn('id_sabor', array_unique($sabores))
                    ->where('activo', 1)->where('tipo_sabor', $presentacion['tipo_sabor'])->get()->getResultArray(),
                'id_sabor'
            );
            if (count($validos) !== count(array_unique($sabores))) {
                return "{$producto}: algún sabor no está disponible.";
            }
            $recargos = array_column(
                $db->table('recargos_sabores')->where('id_presentacion', $presentacion['id_presentacion'])->get()->getResultArray(),
                'monto_recargo',
                'id_sabor'
            );
            foreach ($sabores as $idSabor) {
                $precio += (float) ($recargos[$idSabor] ?? 0);
            }
        } elseif ($sabores !== []) {
            return "{$producto} no lleva sabor.";
        }

        $extrasPrecio = [];
        if ($extras !== []) {
            $permitidos = $db->table('productos_extras pe')
                ->select('e.id_extra, e.precio')
                ->join('extras_toppings e', 'e.id_extra = pe.id_extra')
                ->where('pe.id_producto', $presentacion['id_producto'])
                ->where('e.activo', 1)
                ->whereIn('e.id_extra', $extras)
                ->get()->getResultArray();
            if (count($permitidos) !== count($extras)) {
                return "{$producto}: algún extra no está disponible.";
            }
            foreach ($permitidos as $extra) {
                $extrasPrecio[] = ['id' => (int) $extra['id_extra'], 'precio' => (float) $extra['precio']];
                $precio += (float) $extra['precio'];
            }
        }

        return $this->linea((int) $presentacion['id_presentacion'], null, $cantidad, $precio, $notas, $sabores, $extrasPrecio);
    }

    private function linea(?int $idPresentacion, ?int $idPromocion, int $cantidad, float $precio, ?string $notas, array $sabores, array $extras): array
    {
        return [
            'id_presentacion' => $idPresentacion,
            'id_promocion'    => $idPromocion,
            'cantidad'        => $cantidad,
            'precio_unitario' => round($precio, 2),
            'subtotal'        => round($precio * $cantidad, 2),
            'notas'           => $notas,
            'sabores'         => $sabores,
            'extras'          => $extras,
        ];
    }

    /** @return array{monto: float, concepto: ?string}|string */
    private function descuento($descuento, float $subtotal): array|string
    {
        if (empty($descuento)) {
            return ['monto' => 0.0, 'concepto' => null];
        }

        $tipo  = $descuento['tipo'] ?? null;
        $valor = $descuento['valor'] ?? null;
        if (! in_array($tipo, self::TIPOS_DESCUENTO, true) || ! is_numeric($valor) || $valor <= 0 || ($tipo === 'porcentaje' && $valor > 100)) {
            return 'El descuento no es válido.';
        }

        $monto = $tipo === 'porcentaje' ? $subtotal * $valor / 100 : (float) $valor;

        return [
            'monto'    => round(min($monto, $subtotal), 2),
            'concepto' => mb_substr(trim((string) ($descuento['nombre'] ?? 'Descuento')), 0, 100),
        ];
    }
}
