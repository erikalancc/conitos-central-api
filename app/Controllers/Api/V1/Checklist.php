<?php

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Models\ChecklistItemModel;
use App\Models\ChecklistRegistroModel;
use App\Models\SucursalModel;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Checklist operativo por sucursal (la del usuario de la sesión), uno por día y por momento:
 *   apertura    -> al iniciar el día
 *   medio-turno -> desde la mitad del horario de la sucursal (disponible_desde)
 *   cierre      -> antes del corte de caja
 * Los puntos se configuran en Administración → Checklist operativo. El punto fijo de temperaturas
 * (TEMPERATURE_PAIR) revisa los congeladores y refrigeradores de la sucursal con su rango.
 */
class Checklist extends BaseApiController
{
    private const NOMBRES = ['APERTURA' => 'de apertura', 'MEDIO_TURNO' => 'de mitad del turno', 'CIERRE' => 'antes del corte'];

    /**
     * GET /api/v1/checklist/{momento} — puntos a revisar y registro de hoy (null si falta).
     * Sin puntos configurados para ese momento, items viene vacío y no hay nada que llenar.
     */
    public function ver(string $momento)
    {
        $clave      = ChecklistItemModel::MOMENTOS[$momento];
        $idSucursal = service('sesion')->idSucursal();
        $desde      = $clave === 'MEDIO_TURNO' ? model(SucursalModel::class)->medioTurno($idSucursal) : null;

        return $this->ok([
            'fecha'            => date('Y-m-d'),
            'momento'          => $momento,
            'sucursal'         => $this->sucursal(),
            'disponible_desde' => $desde,
            'disponible'       => $desde === null || date('H:i') >= $desde,
            'items'            => $this->items($clave),
            'registro'         => model(ChecklistRegistroModel::class)->delDia(date('Y-m-d'), $idSucursal, $clave),
        ]);
    }

    // Sucursal del checklist (la del usuario de la sesión)
    private function sucursal(): ?array
    {
        $fila = db_connect()->table('sucursales')->select('id_sucursal, nombre')->where('id_sucursal', service('sesion')->idSucursal())->get()->getRowArray();

        return $fila === null ? null : ['id' => (int) $fila['id_sucursal'], 'nombre' => $fila['nombre']];
    }

    /**
     * Puntos activos del momento para la sucursal. En el de temperaturas, los sensores son los equipos
     * activos de la sucursal: { clave: 'equipo-{id}', id_equipo, nombre, tipo, min, max }.
     */
    private function items(string $clave): array
    {
        $sensores = array_map(static fn (array $e) => [
            'clave'     => 'equipo-' . $e['id'],
            'id_equipo' => $e['id'],
            'nombre'    => $e['nombre'],
            'tipo'      => $e['tipo'],
            'min'       => $e['temp_min'],
            'max'       => $e['temp_max'],
        ], model(SucursalModel::class)->equiposActivos(service('sesion')->idSucursal()));

        return array_map(static function (array $item) use ($sensores) {
            if ($item['tipo_campo'] === 'TEMPERATURE_PAIR') {
                $item['config'] = ['sensores' => $sensores];
            }

            return $item;
        }, model(ChecklistItemModel::class)->activos($clave, service('sesion')->idSucursal()));
    }

    // POST /api/v1/checklist/{momento} — guarda el checklist completado de hoy
    public function guardar(string $momento)
    {
        $clave      = ChecklistItemModel::MOMENTOS[$momento];
        $nombre     = self::NOMBRES[$clave];
        $fecha      = date('Y-m-d');
        $idSucursal = service('sesion')->idSucursal();
        $registros  = model(ChecklistRegistroModel::class);
        $actual     = $registros->delDia($fecha, $idSucursal, $clave);

        if ($actual !== null && $actual['estado'] === 'COMPLETADO') {
            return $this->failResourceExists("El checklist {$nombre} de hoy ya fue registrado.");
        }

        $datos     = $this->cuerpo();
        $recibidas = [];
        foreach ((array) ($datos['respuestas'] ?? []) as $respuesta) {
            if (is_array($respuesta) && isset($respuesta['id_item'])) {
                $recibidas[(int) $respuesta['id_item']] = $respuesta;
            }
        }

        $errores       = [];
        $respuestas    = [];
        $conIncidencia = false;

        foreach ($this->items($clave) as $item) {
            $campo     = 'item_' . $item['id'];
            $recibida  = $recibidas[$item['id']] ?? [];
            $cumplido  = ($recibida['cumplido'] ?? false) === true;
            $respuesta = ['id_item' => $item['id'], 'cumplido' => $cumplido, 'valor' => null, 'temperaturas' => []];

            if ($item['es_obligatorio'] && ! $cumplido) {
                $errores[$campo] = "Falta marcar: {$item['titulo']}.";

                continue;
            }

            if ($item['tipo_campo'] === 'SELECT') {
                $opciones = array_column($item['config']['opciones'] ?? [], null, 'valor');
                $valor    = (string) ($recibida['valor'] ?? '');
                if (! isset($opciones[$valor])) {
                    $errores[$campo] = "Elige una opción válida en: {$item['titulo']}.";

                    continue;
                }
                $respuesta['valor'] = $valor;
                $conIncidencia      = $conIncidencia || ($opciones[$valor]['nivel'] ?? 'ok') !== 'ok';
            }

            if ($item['tipo_campo'] === 'TEMPERATURE_PAIR') {
                foreach ($item['config']['sensores'] ?? [] as $sensor) {
                    $grados = $recibida['temperaturas'][$sensor['clave']] ?? null;
                    if (! is_int($grados) && ! is_float($grados)) {
                        $errores[$campo] = "Captura la temperatura de {$sensor['nombre']}.";

                        continue 2;
                    }
                    // Rango de lo más bajo a lo más alto, aunque mínima y máxima se hayan capturado al revés
                    $enRango                   = $grados >= min($sensor['min'], $sensor['max']) && $grados <= max($sensor['min'], $sensor['max']);
                    $conIncidencia             = $conIncidencia || ! $enRango;
                    // Se guarda el equipo y el rango vigente, para que el historial no cambie si luego se edita
                    $respuesta['temperaturas'][] = [
                        'sensor'    => $sensor['nombre'],
                        'id_equipo' => $sensor['id_equipo'],
                        'grados'    => round($grados, 2),
                        'temp_min'  => $sensor['min'],
                        'temp_max'  => $sensor['max'],
                        'en_rango'  => $enRango,
                    ];
                }
            }

            if ($item['tipo_campo'] === 'TEXT') {
                $respuesta['valor'] = mb_substr(trim((string) ($recibida['valor'] ?? '')), 0, 255) ?: null;
            }

            $respuestas[] = $respuesta;
        }

        $comentarios = trim((string) ($datos['comentarios'] ?? ''));
        if ($errores === [] && $conIncidencia && $comentarios === '') {
            $errores['comentarios'] = 'Describe la incidencia (punto con observaciones o temperatura fuera de rango).';
        }

        if ($errores !== []) {
            return $this->failValidationErrors($errores);
        }

        try {
            $registros->guardarCompletado($fecha, $idSucursal, $clave, service('sesion')->idUsuario(), $respuestas, $conIncidencia, $comentarios ?: null);
        } catch (DatabaseException $e) {
            // Otro usuario de la sucursal lo guardó al mismo tiempo (fecha + sucursal + momento es único)
            return $this->failResourceExists("El checklist {$nombre} de hoy ya fue registrado.");
        }

        return $this->ok($registros->delDia($fecha, $idSucursal, $clave), 201);
    }

    // POST /api/v1/checklist/{momento}/omitir — deja constancia de que se omitió (no para el vendedor)
    public function omitir(string $momento)
    {
        // Para el Vendedor / Cajero el checklist es obligatorio: no se puede omitir
        if ($this->esVendedor()) {
            return $this->failForbidden('El checklist es obligatorio para el vendedor: complétalo y guárdalo.');
        }

        $clave      = ChecklistItemModel::MOMENTOS[$momento];
        $fecha      = date('Y-m-d');
        $idSucursal = service('sesion')->idSucursal();
        $registros  = model(ChecklistRegistroModel::class);
        $actual     = $registros->delDia($fecha, $idSucursal, $clave);

        if ($actual !== null) {
            return $actual['estado'] === 'OMITIDO'
                ? $this->ok($actual)
                : $this->failResourceExists('El checklist de hoy ya fue registrado.');
        }

        $registros->insert([
            'fecha'       => $fecha,
            'id_sucursal' => $idSucursal,
            'momento'     => $clave,
            'id_usuario'  => service('sesion')->idUsuario(),
            'estado'      => 'OMITIDO',
        ]);

        return $this->ok($registros->delDia($fecha, $idSucursal, $clave), 201);
    }
}
