<?php

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Models\ChecklistItemModel;
use App\Models\ChecklistRegistroModel;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Checklist operativo de apertura: uno por día.
 */
class Checklist extends BaseApiController
{
    // GET /api/v1/checklist/apertura — puntos a revisar y registro de hoy (null si falta)
    public function apertura()
    {
        return $this->ok([
            'fecha'    => date('Y-m-d'),
            'items'    => model(ChecklistItemModel::class)->activos(),
            'registro' => model(ChecklistRegistroModel::class)->delDia(date('Y-m-d')),
        ]);
    }

    // POST /api/v1/checklist/apertura — guarda el checklist completado de hoy
    public function guardar()
    {
        $fecha     = date('Y-m-d');
        $registros = model(ChecklistRegistroModel::class);
        $actual    = $registros->delDia($fecha);

        if ($actual !== null && $actual['estado'] === 'COMPLETADO') {
            return $this->failResourceExists('El checklist de apertura de hoy ya fue registrado.');
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

        foreach (model(ChecklistItemModel::class)->activos() as $item) {
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
                    $enRango                   = $grados >= $sensor['min'] && $grados <= $sensor['max'];
                    $conIncidencia             = $conIncidencia || ! $enRango;
                    $respuesta['temperaturas'][] = ['sensor' => $sensor['clave'], 'grados' => round($grados, 2), 'en_rango' => $enRango];
                }
            }

            if ($item['tipo_campo'] === 'TEXT') {
                $respuesta['valor'] = mb_substr(trim((string) ($recibida['valor'] ?? '')), 0, 255) ?: null;
            }

            $respuestas[] = $respuesta;
        }

        $comentarios = trim((string) ($datos['comentarios'] ?? ''));
        if ($errores === [] && $conIncidencia && $comentarios === '') {
            $errores['comentarios'] = 'Describe la incidencia (lote o temperatura fuera de rango).';
        }

        if ($errores !== []) {
            return $this->failValidationErrors($errores);
        }

        try {
            $registros->guardarCompletado($fecha, service('sesion')->idUsuario(), $respuestas, $conIncidencia, $comentarios ?: null);
        } catch (DatabaseException $e) {
            // Otro usuario lo guardó al mismo tiempo (fecha es única)
            return $this->failResourceExists('El checklist de apertura de hoy ya fue registrado.');
        }

        return $this->ok($registros->delDia($fecha), 201);
    }

    // POST /api/v1/checklist/apertura/omitir — deja constancia de que se omitió (requiere autorización)
    public function omitir()
    {
        $fecha     = date('Y-m-d');
        $registros = model(ChecklistRegistroModel::class);
        $actual    = $registros->delDia($fecha);

        if ($actual !== null) {
            return $actual['estado'] === 'OMITIDO'
                ? $this->ok($actual)
                : $this->failResourceExists('El checklist de apertura de hoy ya fue registrado.');
        }

        $registros->insert([
            'fecha'      => $fecha,
            'id_usuario' => service('sesion')->idUsuario(),
            'estado'     => 'OMITIDO',
        ]);

        return $this->ok($registros->delDia($fecha), 201);
    }
}
