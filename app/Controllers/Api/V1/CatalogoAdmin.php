<?php

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Models\CategoriaModel;
use App\Models\ExtraModel;
use App\Models\PresentacionModel;
use App\Models\ProductoModel;
use App\Models\PromocionModel;
use App\Models\RecargoSaborModel;
use App\Models\SaborModel;
use CodeIgniter\Model;

/**
 * Administración del catálogo (solo Administrador). Cada escritura responde con el
 * catálogo completo de administración para que el front no tenga que recalcular nada.
 *
 * Borrar solo se permite si el registro nunca se ha vendido; si ya tiene ventas se desactiva.
 */
class CatalogoAdmin extends BaseApiController
{
    private const RECURSOS = [
        'categorias'  => ['modelo' => CategoriaModel::class, 'nombre' => 'La categoría'],
        'productos'   => ['modelo' => ProductoModel::class, 'nombre' => 'El producto'],
        'sabores'     => ['modelo' => SaborModel::class, 'nombre' => 'El sabor'],
        'extras'      => ['modelo' => ExtraModel::class, 'nombre' => 'El extra'],
        'promociones' => ['modelo' => PromocionModel::class, 'nombre' => 'La promoción'],
    ];

    // Campos que llegan como booleanos en JSON y se guardan como 0/1
    private const BOOLEANOS = ['activo', 'activa', 'requiere_sabor', 'es_especial'];

    // GET /api/v1/admin/catalogo — todo el catálogo, incluido lo inactivo
    public function index()
    {
        return $this->ok($this->catalogo());
    }

    // POST /api/v1/admin/{recurso}
    public function crear(string $recurso)
    {
        $datos   = $this->normalizar($this->cuerpo());
        $errores = $this->validar($recurso, $datos, true);
        if ($errores !== []) {
            return $this->failValidationErrors($errores);
        }

        $db = db_connect();
        $db->transStart();
        $id = $this->modelo($recurso)->insert($this->columnas($recurso, $datos), true);
        $this->guardarRelaciones($recurso, (int) $id, $datos);
        $db->transComplete();

        return $this->ok(['id' => (int) $id, 'catalogo' => $this->catalogo()], 201);
    }

    // PUT /api/v1/admin/{recurso}/{id} — acepta cambios parciales (p. ej. solo { "activo": false })
    public function actualizar(string $recurso, int $id)
    {
        if ($this->modelo($recurso)->find($id) === null) {
            return $this->failNotFound(self::RECURSOS[$recurso]['nombre'] . ' no existe.');
        }

        $datos   = $this->normalizar($this->cuerpo());
        $errores = $this->validar($recurso, $datos, false, $id);
        if ($errores !== []) {
            return $this->failValidationErrors($errores);
        }

        $db = db_connect();
        $db->transStart();
        $columnas = $this->columnas($recurso, $datos);
        if ($columnas !== []) {
            $this->modelo($recurso)->update($id, $columnas);
        }
        $this->guardarRelaciones($recurso, $id, $datos);
        $db->transComplete();

        return $this->ok(['id' => $id, 'catalogo' => $this->catalogo()]);
    }

    // DELETE /api/v1/admin/{recurso}/{id}
    public function eliminar(string $recurso, int $id)
    {
        if ($this->modelo($recurso)->find($id) === null) {
            return $this->failNotFound(self::RECURSOS[$recurso]['nombre'] . ' no existe.');
        }

        $motivo = $this->motivoParaNoBorrar($recurso, $id);
        if ($motivo !== null) {
            return $this->fail(['error' => $motivo], 409);
        }

        $this->modelo($recurso)->delete($id);

        return $this->ok(['id' => $id, 'catalogo' => $this->catalogo()]);
    }

    private function modelo(string $recurso): Model
    {
        return model(self::RECURSOS[$recurso]['modelo']);
    }

    private function normalizar(array $datos): array
    {
        foreach (self::BOOLEANOS as $campo) {
            if (array_key_exists($campo, $datos)) {
                $datos[$campo] = filter_var($datos[$campo], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        foreach (['nombre', 'tipo_sabor'] as $campo) {
            if (isset($datos[$campo]) && is_string($datos[$campo])) {
                $datos[$campo] = trim(preg_replace('/\s+/', ' ', $datos[$campo]));
            }
        }

        return $datos;
    }

    // Solo las columnas propias de la tabla (presentaciones y recargos se guardan aparte)
    private function columnas(string $recurso, array $datos): array
    {
        $permitidos = $this->modelo($recurso)->allowedFields;
        $columnas   = array_intersect_key($datos, array_flip($permitidos));
        foreach (['dia_semana', 'tipo_sabor'] as $campo) {
            if (array_key_exists($campo, $columnas) && $columnas[$campo] === '') {
                $columnas[$campo] = null;
            }
        }

        return $columnas;
    }

    /**
     * Valida lo recibido. Al crear, los campos obligatorios deben venir; al actualizar
     * solo se validan los que llegan.
     */
    private function validar(string $recurso, array $datos, bool $creando, ?int $id = null): array
    {
        $reglas = match ($recurso) {
            'categorias' => [
                'nombre' => ['label' => 'nombre', 'rules' => 'required|max_length[50]'],
            ],
            'productos' => [
                'nombre'         => ['label' => 'nombre', 'rules' => 'required|max_length[100]'],
                'id_categoria'   => ['label' => 'categoría', 'rules' => 'required|is_natural_no_zero|is_not_unique[categorias.id_categoria]'],
                'presentaciones' => ['label' => 'presentaciones', 'rules' => 'required'],
                'tipo_sabor'     => ['label' => 'tipo de sabor', 'rules' => 'permit_empty|max_length[30]'],
            ],
            'sabores' => [
                'nombre'     => ['label' => 'nombre', 'rules' => 'required|max_length[50]'],
                'tipo_sabor' => ['label' => 'tipo de sabor', 'rules' => 'required|max_length[30]'],
            ],
            'extras' => [
                'nombre' => ['label' => 'nombre', 'rules' => 'required|max_length[50]'],
                'precio' => ['label' => 'precio', 'rules' => 'required|decimal|greater_than_equal_to[0]'],
            ],
            'promociones' => [
                'nombre'       => ['label' => 'nombre', 'rules' => 'required|max_length[100]'],
                'precio_combo' => ['label' => 'precio del combo', 'rules' => 'required|decimal|greater_than[0]'],
                'dia_semana'   => ['label' => 'día de la semana', 'rules' => 'permit_empty|in_list[1,2,3,4,5,6,7]'],
            ],
        };

        if (! $creando) {
            $reglas = array_intersect_key($reglas, $datos);
        }

        $errores = [];
        if ($reglas !== [] && ! $this->validateData($datos, $reglas)) {
            $errores = $this->validator->getErrors();
        }

        if (isset($datos['nombre']) && ! isset($errores['nombre']) && $this->nombreRepetido($recurso, $datos, $id)) {
            $errores['nombre'] = 'Ya existe un registro con ese nombre.';
        }

        if ($recurso === 'productos' && array_key_exists('presentaciones', $datos) && ! isset($errores['presentaciones'])) {
            $errores += $this->validarPresentaciones($datos['presentaciones'], $id);
        }

        if ($recurso === 'productos') {
            $errores += $this->validarSaboresYExtras($datos, $id);
        }

        if ($recurso === 'sabores' && array_key_exists('recargos', $datos)) {
            $errores += $this->validarRecargos($datos['recargos']);
        }

        return $errores;
    }

    // Nombres únicos (sin distinguir mayúsculas); los productos, dentro de su categoría
    private function nombreRepetido(string $recurso, array $datos, ?int $id): bool
    {
        $modelo = $this->modelo($recurso);
        $query  = $modelo->builder()->where('LOWER(nombre)', mb_strtolower($datos['nombre']));
        if ($id !== null) {
            $query->where($modelo->primaryKey . ' !=', $id);
        }
        if ($recurso === 'productos') {
            $categoria = $datos['id_categoria'] ?? $modelo->find($id)['id_categoria'] ?? null;
            $query->where('id_categoria', $categoria);
        }

        return $query->countAllResults() > 0;
    }

    private function validarPresentaciones($presentaciones, ?int $idProducto): array
    {
        if (! is_array($presentaciones) || $presentaciones === []) {
            return ['presentaciones' => 'Agrega al menos una presentación con su precio.'];
        }

        $nombres = [];
        foreach (array_values($presentaciones) as $i => $p) {
            $nombre = trim((string) ($p['nombre'] ?? ''));
            $precio = $p['precio_base'] ?? null;
            if ($nombre === '' || mb_strlen($nombre) > 50) {
                return ["presentaciones.{$i}.nombre" => 'Cada presentación necesita un nombre (máx. 50 caracteres).'];
            }
            if (! is_numeric($precio) || $precio < 0) {
                return ["presentaciones.{$i}.precio_base" => "El precio de \"{$nombre}\" no es válido."];
            }
            $numSabores = $p['num_sabores'] ?? 1;
            if (! is_numeric($numSabores) || (int) $numSabores != $numSabores || $numSabores < 1 || $numSabores > 10) {
                return ["presentaciones.{$i}.num_sabores" => "Los sabores de \"{$nombre}\" deben ser de 1 a 10."];
            }
            if (isset($nombres[mb_strtolower($nombre)])) {
                return ["presentaciones.{$i}.nombre" => "La presentación \"{$nombre}\" está repetida."];
            }
            $nombres[mb_strtolower($nombre)] = true;

            if (isset($p['id']) && ($idProducto === null || ! $this->presentacionDe((int) $p['id'], $idProducto))) {
                return ["presentaciones.{$i}.id" => 'La presentación no pertenece a este producto.'];
            }
        }

        $activas = array_filter($presentaciones, static fn ($p) => filter_var($p['activo'] ?? true, FILTER_VALIDATE_BOOLEAN));

        return $activas === [] ? ['presentaciones' => 'Deja al menos una presentación activa.'] : [];
    }

    // Si el producto pide sabor debe decir de qué tipo; los extras deben existir
    private function validarSaboresYExtras(array $datos, ?int $id): array
    {
        $actual   = $id !== null ? model(ProductoModel::class)->find($id) : [];
        $requiere = (bool) ($datos['requiere_sabor'] ?? $actual['requiere_sabor'] ?? false);
        $tipo     = trim((string) (array_key_exists('tipo_sabor', $datos) ? $datos['tipo_sabor'] : ($actual['tipo_sabor'] ?? '')));

        if ($requiere && $tipo === '') {
            return ['tipo_sabor' => 'Elige qué tipo de sabores se pueden escoger.'];
        }

        if (array_key_exists('extras', $datos)) {
            $extras = $datos['extras'];
            if (! is_array($extras)) {
                return ['extras' => 'Los extras no son válidos.'];
            }
            $ids = array_unique(array_map('intval', $extras));
            if ($ids !== [] && model(ExtraModel::class)->whereIn('id_extra', $ids)->countAllResults() !== count($ids)) {
                return ['extras' => 'Alguno de los extras elegidos no existe.'];
            }
        }

        return [];
    }

    private function validarRecargos($recargos): array
    {
        if (! is_array($recargos)) {
            return ['recargos' => 'Los recargos no son válidos.'];
        }

        $vistos = [];
        foreach (array_values($recargos) as $i => $r) {
            $idPresentacion = (int) ($r['id_presentacion'] ?? 0);
            if ($idPresentacion <= 0 || model(PresentacionModel::class)->find($idPresentacion) === null) {
                return ["recargos.{$i}.id_presentacion" => 'Elige una presentación válida para el recargo.'];
            }
            if (isset($vistos[$idPresentacion])) {
                return ["recargos.{$i}.id_presentacion" => 'Hay dos recargos para la misma presentación.'];
            }
            if (! is_numeric($r['monto'] ?? null) || $r['monto'] <= 0) {
                return ["recargos.{$i}.monto" => 'El recargo debe ser mayor a cero.'];
            }
            $vistos[$idPresentacion] = true;
        }

        return [];
    }

    private function presentacionDe(int $idPresentacion, int $idProducto): bool
    {
        $presentacion = model(PresentacionModel::class)->find($idPresentacion);

        return $presentacion !== null && (int) $presentacion['id_producto'] === $idProducto;
    }

    private function guardarRelaciones(string $recurso, int $id, array $datos): void
    {
        if ($recurso === 'productos' && array_key_exists('presentaciones', $datos)) {
            $this->sincronizarPresentaciones($id, $datos['presentaciones']);
        }
        if ($recurso === 'productos' && array_key_exists('extras', $datos)) {
            $tabla = db_connect()->table('productos_extras');
            $tabla->where('id_producto', $id)->delete();
            foreach (array_unique(array_map('intval', $datos['extras'])) as $idExtra) {
                $tabla->insert(['id_producto' => $id, 'id_extra' => $idExtra]);
            }
        }
        if ($recurso === 'sabores' && array_key_exists('recargos', $datos)) {
            $this->sincronizarRecargos($id, $datos['recargos']);
        }
    }

    // Actualiza, agrega y quita presentaciones; las que ya se vendieron solo se desactivan
    private function sincronizarPresentaciones(int $idProducto, array $presentaciones): void
    {
        $modelo     = model(PresentacionModel::class);
        $existentes = array_column($modelo->where('id_producto', $idProducto)->findAll(), null, 'id_presentacion');
        $recibidas  = [];

        foreach ($presentaciones as $p) {
            $fila = [
                'id_producto' => $idProducto,
                'nombre'      => trim((string) $p['nombre']),
                'precio_base' => round((float) $p['precio_base'], 2),
                'num_sabores' => (int) ($p['num_sabores'] ?? 1),
                'activo'      => filter_var($p['activo'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            ];
            if (isset($p['id'], $existentes[$p['id']])) {
                $modelo->update((int) $p['id'], $fila);
                $recibidas[(int) $p['id']] = true;
            } else {
                $modelo->insert($fila);
            }
        }

        foreach (array_keys($existentes) as $idPresentacion) {
            if (isset($recibidas[$idPresentacion])) {
                continue;
            }
            if ($this->presentacionVendida($idPresentacion)) {
                $modelo->update($idPresentacion, ['activo' => 0]);
            } else {
                $modelo->delete($idPresentacion);
            }
        }
    }

    private function sincronizarRecargos(int $idSabor, array $recargos): void
    {
        $modelo = model(RecargoSaborModel::class);
        $modelo->where('id_sabor', $idSabor)->delete();
        foreach ($recargos as $r) {
            $modelo->insert([
                'id_sabor'        => $idSabor,
                'id_presentacion' => (int) $r['id_presentacion'],
                'monto_recargo'   => round((float) $r['monto'], 2),
            ]);
        }
    }

    private function presentacionVendida(int $idPresentacion): bool
    {
        return db_connect()->table('detalle_ventas')->where('id_presentacion', $idPresentacion)->countAllResults() > 0;
    }

    private function motivoParaNoBorrar(string $recurso, int $id): ?string
    {
        $db = db_connect();

        switch ($recurso) {
            case 'categorias':
                $productos = $db->table('productos')->where('id_categoria', $id)->countAllResults();

                return $productos > 0
                    ? "La categoría tiene {$productos} producto(s). Muévelos a otra categoría o desactívala."
                    : null;

            case 'productos':
                $vendido = $db->table('detalle_ventas dv')
                    ->join('presentaciones p', 'p.id_presentacion = dv.id_presentacion')
                    ->where('p.id_producto', $id)
                    ->countAllResults() > 0;

                return $vendido ? 'El producto ya tiene ventas registradas; desactívalo para ocultarlo del POS.' : null;

            case 'sabores':
                $vendido    = $db->table('detalle_venta_sabores')->where('id_sabor', $id)->countAllResults() > 0;
                $producido  = $db->table('ordenes_produccion')->where('id_sabor', $id)->countAllResults() > 0;

                return $vendido || $producido
                    ? 'El sabor ya tiene ventas u órdenes de producción; desactívalo para ocultarlo del POS.'
                    : null;

            case 'extras':
                return $db->table('detalle_venta_extras')->where('id_extra', $id)->countAllResults() > 0
                    ? 'El extra ya tiene ventas registradas; desactívalo para ocultarlo del POS.'
                    : null;

            case 'promociones':
                return $db->table('detalle_ventas')->where('id_promocion', $id)->countAllResults() > 0
                    ? 'La promoción ya tiene ventas registradas; desactívala en su lugar.'
                    : null;
        }

        return null;
    }

    // Catálogo completo para la pantalla de administración
    private function catalogo(): array
    {
        $db = db_connect();

        $vendidas = array_flip(array_map('intval', array_column(
            $db->table('detalle_ventas')->select('id_presentacion')->distinct()->where('id_presentacion IS NOT NULL')->get()->getResultArray(),
            'id_presentacion'
        )));
        $saboresUsados = array_flip(array_map('intval', array_merge(
            array_column($db->table('detalle_venta_sabores')->select('id_sabor')->distinct()->get()->getResultArray(), 'id_sabor'),
            array_column($db->table('ordenes_produccion')->select('id_sabor')->distinct()->get()->getResultArray(), 'id_sabor')
        )));
        $extrasVendidos = array_flip(array_map('intval', array_column(
            $db->table('detalle_venta_extras')->select('id_extra')->distinct()->get()->getResultArray(),
            'id_extra'
        )));
        $promosVendidas = array_flip(array_map('intval', array_column(
            $db->table('detalle_ventas')->select('id_promocion')->distinct()->where('id_promocion IS NOT NULL')->get()->getResultArray(),
            'id_promocion'
        )));

        $presentaciones = [];
        foreach (model(PresentacionModel::class)->orderBy('id_presentacion')->findAll() as $p) {
            $presentaciones[$p['id_producto']][] = [
                'id'          => (int) $p['id_presentacion'],
                'nombre'      => $p['nombre'],
                'precio_base' => (float) $p['precio_base'],
                'num_sabores' => (int) $p['num_sabores'],
                'activo'      => (bool) $p['activo'],
                'vendida'     => isset($vendidas[(int) $p['id_presentacion']]),
            ];
        }

        $extrasPorProducto = [];
        foreach ($db->table('productos_extras')->orderBy('id_extra')->get()->getResultArray() as $fila) {
            $extrasPorProducto[$fila['id_producto']][] = (int) $fila['id_extra'];
        }

        $productos = array_map(static function (array $p) use ($presentaciones, $extrasPorProducto) {
            $suyas = $presentaciones[$p['id_producto']] ?? [];

            return [
                'id'             => (int) $p['id_producto'],
                'id_categoria'   => (int) $p['id_categoria'],
                'nombre'         => $p['nombre'],
                'requiere_sabor' => (bool) $p['requiere_sabor'],
                'tipo_sabor'     => $p['tipo_sabor'],
                'activo'         => (bool) $p['activo'],
                'vendido'        => in_array(true, array_column($suyas, 'vendida'), true),
                'presentaciones' => $suyas,
                'extras'         => $extrasPorProducto[$p['id_producto']] ?? [],
            ];
        }, model(ProductoModel::class)->orderBy('id_categoria')->orderBy('nombre')->findAll());

        $porCategoria = array_count_values(array_column($productos, 'id_categoria'));

        $recargos = [];
        foreach (model(RecargoSaborModel::class)->findAll() as $r) {
            $recargos[$r['id_sabor']][] = ['id_presentacion' => (int) $r['id_presentacion'], 'monto' => (float) $r['monto_recargo']];
        }

        $inventario = [];
        $filas      = $db->table('inventario_sabores i')
            ->select('i.*, e.nombre AS empaque')
            ->join('unidades_empaque e', 'e.id_empaque = i.id_empaque', 'left')
            ->get()->getResultArray();
        foreach ($filas as $i) {
            $inventario[$i['id_sabor']] = [
                'botes_reserva'    => (int) $i['botes_reserva'],
                'stock_minimo'     => (int) $i['stock_minimo_reserva'],
                'porciones_actual' => (float) $i['porciones_vitrina_actual'],
                'porciones_max'    => (float) $i['porciones_vitrina_max'],
                'empaque'          => $i['empaque'],
            ];
        }

        $sabores = array_map(static fn (array $s) => [
            'id'          => (int) $s['id_sabor'],
            'nombre'      => $s['nombre'],
            'tipo'        => $s['tipo_sabor'],
            'es_especial' => (bool) $s['es_especial'],
            'activo'      => (bool) $s['activo'],
            'vendido'     => isset($saboresUsados[(int) $s['id_sabor']]),
            'recargos'    => $recargos[$s['id_sabor']] ?? [],
            'inventario'  => $inventario[$s['id_sabor']] ?? null,
        ], model(SaborModel::class)->orderBy('nombre')->findAll());

        return [
            'categorias' => array_map(static fn (array $c) => [
                'id'        => (int) $c['id_categoria'],
                'nombre'    => $c['nombre'],
                'activo'    => (bool) $c['activo'],
                'productos' => $porCategoria[(int) $c['id_categoria']] ?? 0,
            ], model(CategoriaModel::class)->orderBy('id_categoria')->findAll()),

            'productos' => $productos,

            'sabores'     => $sabores,
            'tipos_sabor' => array_values(array_unique(array_column($sabores, 'tipo'))),

            'extras' => array_map(static fn (array $e) => [
                'id'      => (int) $e['id_extra'],
                'nombre'  => $e['nombre'],
                'precio'  => (float) $e['precio'],
                'activo'  => (bool) $e['activo'],
                'vendido' => isset($extrasVendidos[(int) $e['id_extra']]),
            ], model(ExtraModel::class)->orderBy('nombre')->findAll()),

            'promociones' => array_map(static fn (array $p) => [
                'id'           => (int) $p['id_promocion'],
                'nombre'       => $p['nombre'],
                'precio_combo' => (float) $p['precio_combo'],
                'dia_semana'   => $p['dia_semana'] === null ? null : (int) $p['dia_semana'],
                'activa'       => (bool) $p['activa'],
                'vendida'      => isset($promosVendidas[(int) $p['id_promocion']]),
            ], model(PromocionModel::class)->orderBy('nombre')->findAll()),
        ];
    }
}
