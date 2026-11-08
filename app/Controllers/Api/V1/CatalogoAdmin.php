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
use CodeIgniter\Database\Exceptions\DatabaseException;
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

    // Imágenes de productos: se guardan en public/uploads/productos/ y en la BD va la ruta relativa
    private const IMAGEN_CARPETA = 'uploads/productos/';
    private const IMAGEN_MAX_KB  = 5120;

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
        try {
            $db->transStart();
            $id = $this->modelo($recurso)->insert($this->columnas($recurso, $datos), true);
            $this->guardarRelaciones($recurso, (int) $id, $datos, true);
            $db->transComplete();
        } catch (DatabaseException $e) {
            $db->transRollback();
            if ($this->esNombreDuplicado($e)) {
                return $this->failValidationErrors(['nombre' => 'Ya existe un registro con ese nombre.']);
            }
            throw $e;
        }

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
        try {
            $db->transStart();
            $columnas = $this->columnas($recurso, $datos);
            if ($columnas !== []) {
                $this->modelo($recurso)->update($id, $columnas);
            }
            $this->guardarRelaciones($recurso, $id, $datos);
            $db->transComplete();
        } catch (DatabaseException $e) {
            $db->transRollback();
            if ($this->esNombreDuplicado($e)) {
                return $this->failValidationErrors(['nombre' => 'Ya existe un registro con ese nombre.']);
            }
            throw $e;
        }

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

        if ($recurso === 'productos') {
            $this->borrarArchivoImagen(model(ProductoModel::class)->find($id)['imagen'] ?? null);
        }
        $this->modelo($recurso)->delete($id);

        return $this->ok(['id' => $id, 'catalogo' => $this->catalogo()]);
    }

    // POST /api/v1/admin/productos/{id}/imagen — multipart, campo "imagen" (JPG/JPEG/PNG/WEBP, máx. 5 MB)
    public function subirImagen(int $id)
    {
        $producto = model(ProductoModel::class)->find($id);
        if ($producto === null) {
            return $this->failNotFound('El producto no existe.');
        }

        $archivo = $this->request->getFile('imagen');
        if ($archivo === null || $archivo->getError() === UPLOAD_ERR_NO_FILE) {
            // Si el cuerpo supera post_max_size, PHP descarta el archivo sin avisar
            return $this->failValidationErrors(['imagen' => 'No se recibió la imagen. Revisa que sea JPG, JPEG, PNG o WEBP de máximo 5 MB.']);
        }
        if (in_array($archivo->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->failValidationErrors(['imagen' => 'La imagen pesa más de 5 MB.']);
        }

        $reglas = [
            'imagen' => [
                'label'  => 'imagen',
                'rules'  => 'uploaded[imagen]|max_size[imagen,' . self::IMAGEN_MAX_KB . ']|is_image[imagen]|mime_in[imagen,image/jpeg,image/png,image/webp]|ext_in[imagen,jpg,jpeg,png,webp]',
                'errors' => [
                    'uploaded' => 'No se pudo subir la imagen. Intenta de nuevo.',
                    'max_size' => 'La imagen pesa más de 5 MB.',
                    'is_image' => 'El archivo no es una imagen válida.',
                    'mime_in'  => 'La imagen debe ser JPG, JPEG, PNG o WEBP.',
                    'ext_in'   => 'La imagen debe ser JPG, JPEG, PNG o WEBP.',
                ],
            ],
        ];
        if (! $this->validate($reglas)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $carpeta = FCPATH . self::IMAGEN_CARPETA;
        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0755, true);
        }

        $nombre = "producto-{$id}-" . bin2hex(random_bytes(6)) . '.' . strtolower($archivo->guessExtension() ?: $archivo->getExtension());
        $archivo->move($carpeta, $nombre);

        db_connect()->table('productos')->where('id_producto', $id)->update(['imagen' => self::IMAGEN_CARPETA . $nombre]);
        $this->borrarArchivoImagen($producto['imagen'] ?? null);

        return $this->ok(['id' => $id, 'catalogo' => $this->catalogo()]);
    }

    // DELETE /api/v1/admin/productos/{id}/imagen
    public function quitarImagen(int $id)
    {
        $producto = model(ProductoModel::class)->find($id);
        if ($producto === null) {
            return $this->failNotFound('El producto no existe.');
        }

        db_connect()->table('productos')->where('id_producto', $id)->update(['imagen' => null]);
        $this->borrarArchivoImagen($producto['imagen'] ?? null);

        return $this->ok(['id' => $id, 'catalogo' => $this->catalogo()]);
    }

    // Borra el archivo solo si está dentro de la carpeta de imágenes de productos
    private function borrarArchivoImagen(?string $ruta): void
    {
        if ($ruta === null || $ruta === '' || ! str_starts_with($ruta, self::IMAGEN_CARPETA) || str_contains($ruta, '..')) {
            return;
        }
        $archivo = FCPATH . $ruta;
        if (is_file($archivo)) {
            @unlink($archivo);
        }
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

        // Tipos del sabor: sin espacios de más, sin vacíos y sin repetidos (sin distinguir mayúsculas)
        if (isset($datos['tipos']) && is_array($datos['tipos'])) {
            $tipos = [];
            foreach ($datos['tipos'] as $tipo) {
                $tipo = trim(preg_replace('/\s+/', ' ', (string) $tipo));
                if ($tipo !== '') {
                    $tipos[mb_strtolower($tipo)] ??= $tipo;
                }
            }
            $datos['tipos'] = array_values($tipos);
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
                'nombre' => ['label' => 'nombre', 'rules' => 'required|max_length[50]'],
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

        if (isset($datos['nombre']) && ! isset($errores['nombre'])) {
            $existente = $this->nombreRepetido($recurso, $datos, $id);
            if ($existente !== null) {
                $errores['nombre'] = $recurso === 'sabores'
                    ? "Ya existe el sabor \"{$existente}\". No se pueden repetir sabores."
                    : 'Ya existe un registro con ese nombre.';
            }
        }

        if ($recurso === 'productos' && array_key_exists('presentaciones', $datos) && ! isset($errores['presentaciones'])) {
            $errores += $this->validarPresentaciones($datos['presentaciones'], $id);
        }

        if ($recurso === 'productos') {
            $errores += $this->validarSaboresYExtras($datos, $id);
            $errores += $this->validarSucursales($datos);
        }

        if ($recurso === 'sabores' && array_key_exists('recargos', $datos)) {
            $errores += $this->validarRecargos($datos['recargos']);
        }

        // Al crear los tipos son obligatorios; al editar, solo si vienen
        if ($recurso === 'sabores' && ($creando || array_key_exists('tipos', $datos))) {
            $errores += $this->validarTipos($datos['tipos'] ?? null);
        }

        return $errores;
    }

    /**
     * Nombres únicos; los productos, dentro de su categoría. La intercalación de la BD (utf8mb4_unicode_ci)
     * ignora mayúsculas, acentos y espacios al final: "limon" choca con "Limón".
     * Devuelve el nombre ya registrado o null si no se repite.
     */
    private function nombreRepetido(string $recurso, array $datos, ?int $id): ?string
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

        $fila = $query->select('nombre')->get(1)->getRowArray();

        return $fila['nombre'] ?? null;
    }

    // Respaldo del índice único de la BD (p. ej. dos guardados al mismo tiempo): error 1062 -> 400 en "nombre"
    private function esNombreDuplicado(DatabaseException $e): bool
    {
        return $e->getCode() === 1062 || str_contains($e->getMessage(), 'Duplicate entry');
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

    /**
     * Producto por sucursal:
     *  - sucursales: [id_sucursal, …] donde se vende (al menos una);
     *  - presentaciones[i].precios_sucursal: { id_sucursal: precio } solo donde el precio es distinto al general.
     */
    private function validarSucursales(array $datos): array
    {
        $existentes = array_flip(array_map('intval', array_column(
            db_connect()->table('sucursales')->select('id_sucursal')->get()->getResultArray(),
            'id_sucursal'
        )));

        if (array_key_exists('sucursales', $datos)) {
            if (! is_array($datos['sucursales']) || $datos['sucursales'] === []) {
                return ['sucursales' => 'Elige al menos una sucursal donde se venda el producto.'];
            }
            foreach ($datos['sucursales'] as $idSucursal) {
                if (! isset($existentes[(int) $idSucursal])) {
                    return ['sucursales' => 'Alguna de las sucursales elegidas no existe.'];
                }
            }
        }

        foreach (array_values(is_array($datos['presentaciones'] ?? null) ? $datos['presentaciones'] : []) as $i => $p) {
            $precios = $p['precios_sucursal'] ?? [];
            if (! is_array($precios)) {
                return ["presentaciones.{$i}.precios_sucursal" => 'Los precios por sucursal no son válidos.'];
            }
            foreach ($precios as $idSucursal => $precio) {
                if (! isset($existentes[(int) $idSucursal])) {
                    return ["presentaciones.{$i}.precios_sucursal" => 'Hay un precio para una sucursal que no existe.'];
                }
                if ($precio !== null && $precio !== '' && (! is_numeric($precio) || $precio < 0)) {
                    $nombre = trim((string) ($p['nombre'] ?? ''));

                    return ["presentaciones.{$i}.precios_sucursal.{$idSucursal}" => "El precio de \"{$nombre}\" en la sucursal no es válido."];
                }
            }
        }

        return [];
    }

    // Tipos a los que aplica el sabor (ya normalizados): al menos uno, máx. 30 caracteres cada uno
    private function validarTipos($tipos): array
    {
        if (! is_array($tipos) || $tipos === []) {
            return ['tipos' => 'Elige al menos un tipo al que aplica el sabor.'];
        }
        foreach ($tipos as $tipo) {
            if (mb_strlen($tipo) > 30) {
                return ['tipos' => "El tipo \"{$tipo}\" es muy largo (máx. 30 caracteres)."];
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

    private function guardarRelaciones(string $recurso, int $id, array $datos, bool $creando = false): void
    {
        if ($recurso === 'productos' && array_key_exists('presentaciones', $datos)) {
            $this->sincronizarPresentaciones($id, $datos['presentaciones']);
        }
        // Sucursales donde se vende; un producto nuevo sin sucursales indicadas se vende en todas
        if ($recurso === 'productos' && (array_key_exists('sucursales', $datos) || $creando)) {
            $ids = array_key_exists('sucursales', $datos)
                ? array_unique(array_map('intval', $datos['sucursales']))
                : array_map('intval', array_column(db_connect()->table('sucursales')->select('id_sucursal')->get()->getResultArray(), 'id_sucursal'));
            $tabla = db_connect()->table('productos_sucursales');
            $tabla->where('id_producto', $id)->delete();
            foreach ($ids as $idSucursal) {
                $tabla->insert(['id_producto' => $id, 'id_sucursal' => $idSucursal]);
            }
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
        if ($recurso === 'sabores' && array_key_exists('tipos', $datos)) {
            $tabla = db_connect()->table('sabores_tipos');
            $tabla->where('id_sabor', $id)->delete();
            foreach ($datos['tipos'] as $tipo) {
                $tabla->insert(['id_sabor' => $id, 'tipo_sabor' => $tipo]);
            }
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
                $idPresentacion = (int) $p['id'];
                $modelo->update($idPresentacion, $fila);
                $recibidas[$idPresentacion] = true;
            } else {
                $idPresentacion = (int) $modelo->insert($fila, true);
            }

            // Precios por sucursal: se guardan solo los que difieren del general (vacío = precio general)
            if (array_key_exists('precios_sucursal', $p) && is_array($p['precios_sucursal'])) {
                $tabla = db_connect()->table('precios_sucursales');
                $tabla->where('id_presentacion', $idPresentacion)->delete();
                foreach ($p['precios_sucursal'] as $idSucursal => $precio) {
                    if ($precio === null || $precio === '' || round((float) $precio, 2) === $fila['precio_base']) {
                        continue;
                    }
                    $tabla->insert(['id_presentacion' => $idPresentacion, 'id_sucursal' => (int) $idSucursal, 'precio' => round((float) $precio, 2)]);
                }
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

    private function tiposEnUso(array $sabores, array $productos): array
    {
        $tipos = [];
        foreach (array_merge(array_merge(...array_column($sabores, 'tipos')) ?: [], array_column($productos, 'tipo_sabor')) as $tipo) {
            if ($tipo !== null && $tipo !== '') {
                $tipos[mb_strtolower($tipo)] ??= $tipo;
            }
        }
        natcasesort($tipos);

        return array_values($tipos);
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

        // Precios propios por sucursal: [id_presentacion][id_sucursal] = precio (solo los distintos al general)
        $preciosSucursal = [];
        foreach ($db->table('precios_sucursales')->get()->getResultArray() as $fila) {
            $preciosSucursal[(int) $fila['id_presentacion']][(int) $fila['id_sucursal']] = (float) $fila['precio'];
        }

        $presentaciones = [];
        foreach (model(PresentacionModel::class)->orderBy('id_presentacion')->findAll() as $p) {
            $presentaciones[$p['id_producto']][] = [
                'id'               => (int) $p['id_presentacion'],
                'nombre'           => $p['nombre'],
                'precio_base'      => (float) $p['precio_base'],
                'num_sabores'      => (int) $p['num_sabores'],
                'activo'           => (bool) $p['activo'],
                'vendida'          => isset($vendidas[(int) $p['id_presentacion']]),
                // { "id_sucursal": precio } — objeto JSON aunque esté vacío
                'precios_sucursal' => (object) ($preciosSucursal[(int) $p['id_presentacion']] ?? []),
            ];
        }

        $extrasPorProducto = [];
        foreach ($db->table('productos_extras')->orderBy('id_extra')->get()->getResultArray() as $fila) {
            $extrasPorProducto[$fila['id_producto']][] = (int) $fila['id_extra'];
        }

        // Sucursales donde se vende cada producto
        $sucursalesPorProducto = [];
        foreach ($db->table('productos_sucursales')->orderBy('id_sucursal')->get()->getResultArray() as $fila) {
            $sucursalesPorProducto[$fila['id_producto']][] = (int) $fila['id_sucursal'];
        }

        $productos = array_map(static function (array $p) use ($presentaciones, $extrasPorProducto, $sucursalesPorProducto) {
            $suyas = $presentaciones[$p['id_producto']] ?? [];

            return [
                'id'             => (int) $p['id_producto'],
                'id_categoria'   => (int) $p['id_categoria'],
                'nombre'         => $p['nombre'],
                'imagen'         => $p['imagen'] ?? null,
                'requiere_sabor' => (bool) $p['requiere_sabor'],
                'tipo_sabor'     => $p['tipo_sabor'],
                'activo'         => (bool) $p['activo'],
                'vendido'        => in_array(true, array_column($suyas, 'vendida'), true),
                'presentaciones' => $suyas,
                'extras'         => $extrasPorProducto[$p['id_producto']] ?? [],
                'sucursales'     => $sucursalesPorProducto[$p['id_producto']] ?? [],
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

        $tiposSabor = SaborModel::tiposPorSabor();
        $sabores    = array_map(static fn (array $s) => [
            'id'          => (int) $s['id_sabor'],
            'nombre'      => $s['nombre'],
            'tipos'       => $tiposSabor[(int) $s['id_sabor']] ?? [],
            'es_especial' => (bool) $s['es_especial'],
            'activo'      => (bool) $s['activo'],
            'vendido'     => isset($saboresUsados[(int) $s['id_sabor']]),
            'recargos'    => $recargos[$s['id_sabor']] ?? [],
            'inventario'  => $inventario[$s['id_sabor']] ?? null,
        ], model(SaborModel::class)->orderBy('nombre')->findAll());

        return [
            // Sucursales para asignar productos y precios (la matriz primero)
            'sucursales' => array_map(static fn (array $s) => [
                'id'        => (int) $s['id_sucursal'],
                'nombre'    => $s['nombre'],
                'es_matriz' => (bool) $s['es_matriz'],
                'activo'    => (bool) $s['activo'],
            ], $db->table('sucursales')->select('id_sucursal, nombre, es_matriz, activo')->orderBy('es_matriz', 'DESC')->orderBy('nombre')->get()->getResultArray()),

            'categorias' => array_map(static fn (array $c) => [
                'id'        => (int) $c['id_categoria'],
                'nombre'    => $c['nombre'],
                'activo'    => (bool) $c['activo'],
                'productos' => $porCategoria[(int) $c['id_categoria']] ?? 0,
            ], model(CategoriaModel::class)->orderBy('id_categoria')->findAll()),

            'productos' => $productos,

            'sabores'     => $sabores,
            // Todos los tipos en uso (de los sabores y de los productos), sin repetir y en orden alfabético
            'tipos_sabor' => $this->tiposEnUso($sabores, $productos),

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
