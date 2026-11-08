<?php

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Models\ChecklistItemModel;

/**
 * Administración del checklist operativo (solo Administrador).
 * Cada punto: momento (apertura | medio-turno | cierre), tipo (BOOLEAN = solo marcar, SELECT = elegir
 * una opción con nivel ok/aviso/error, TEXT = texto libre), obligatorio, activo y sucursales (vacío = todas).
 * El punto fijo de refrigeradores y congeladores no se edita ni se borra.
 */
class ChecklistAdmin extends BaseApiController
{
    private const NIVELES = ['ok', 'aviso', 'error'];

    // GET /api/v1/admin/checklist
    public function index()
    {
        return $this->ok($this->listado());
    }

    // POST /api/v1/admin/checklist
    public function crear()
    {
        return $this->guardar(null);
    }

    // PUT /api/v1/admin/checklist/{id}
    public function actualizar(int $id)
    {
        return $this->guardar($id);
    }

    // DELETE /api/v1/admin/checklist/{id} — si ya tiene respuestas se debe desactivar
    public function eliminar(int $id)
    {
        $item = model(ChecklistItemModel::class)->find($id);
        if ($item === null) {
            return $this->failNotFound('El punto del checklist no existe.');
        }
        if ($item['es_fijo']) {
            return $this->fail(['error' => 'La revisión de refrigeradores y congeladores es fija: no se puede borrar.'], 409);
        }
        if (db_connect()->table('checklist_respuestas')->where('id_item', $id)->countAllResults() > 0) {
            return $this->fail(['error' => 'Este punto ya tiene checklists registrados: desactívalo para dejar de pedirlo.'], 409);
        }

        model(ChecklistItemModel::class)->delete($id);

        return $this->ok($this->listado());
    }

    private function guardar(?int $id)
    {
        $modelo = model(ChecklistItemModel::class);
        $actual = $id === null ? null : $modelo->find($id);
        if ($id !== null && $actual === null) {
            return $this->failNotFound('El punto del checklist no existe.');
        }
        if ($actual !== null && $actual['es_fijo']) {
            return $this->fail(['error' => 'La revisión de refrigeradores y congeladores es fija: no se puede modificar.'], 409);
        }

        $datos  = $this->cuerpo();
        $reglas = [
            'titulo'      => ['label' => 'título', 'rules' => 'required|max_length[150]'],
            'descripcion' => ['label' => 'descripción', 'rules' => 'permit_empty|max_length[500]'],
            'etiqueta_ok' => ['label' => 'texto al cumplirse', 'rules' => 'permit_empty|max_length[30]'],
            'momento'     => ['label' => 'momento', 'rules' => 'required|in_list[' . implode(',', array_keys(ChecklistItemModel::MOMENTOS)) . ']'],
            'tipo_campo'  => ['label' => 'tipo de respuesta', 'rules' => 'required|in_list[' . implode(',', ChecklistItemModel::TIPOS) . ']'],
        ];
        if (! $this->validateData($datos, $reglas)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        // Opciones de un punto "elegir una opción": texto y nivel (ok = conforme, aviso u error = incidencia)
        $config = null;
        if ($datos['tipo_campo'] === 'SELECT') {
            $opciones = [];
            foreach (array_values((array) ($datos['opciones'] ?? [])) as $i => $opcion) {
                $texto = trim((string) ($opcion['texto'] ?? ''));
                if ($texto === '' || mb_strlen($texto) > 60) {
                    return $this->failValidationErrors(["opciones.{$i}.texto" => 'Cada opción necesita un texto (máx. 60 caracteres).']);
                }
                if (! in_array($opcion['nivel'] ?? '', self::NIVELES, true)) {
                    return $this->failValidationErrors(["opciones.{$i}.nivel" => 'El nivel debe ser conforme, aviso o error.']);
                }
                $opciones[] = ['valor' => 'opcion-' . ($i + 1), 'texto' => $texto, 'nivel' => $opcion['nivel']];
            }
            if (count($opciones) < 2) {
                return $this->failValidationErrors(['opciones' => 'Agrega al menos dos opciones.']);
            }
            $config = json_encode(['opciones' => $opciones], JSON_UNESCAPED_UNICODE);
        }

        // Sucursales donde aplica (vacío = todas)
        $sucursales = array_values(array_unique(array_map('intval', (array) ($datos['sucursales'] ?? []))));
        if ($sucursales !== [] && db_connect()->table('sucursales')->whereIn('id_sucursal', $sucursales)->countAllResults() !== count($sucursales)) {
            return $this->failValidationErrors(['sucursales' => 'Alguna de las sucursales elegidas no existe.']);
        }

        $momento = ChecklistItemModel::MOMENTOS[$datos['momento']];
        $fila    = [
            'momento'        => $momento,
            'titulo'         => trim($datos['titulo']),
            'descripcion'    => trim((string) ($datos['descripcion'] ?? '')) ?: null,
            'etiqueta_ok'    => trim((string) ($datos['etiqueta_ok'] ?? '')) ?: null,
            'tipo_campo'     => $datos['tipo_campo'],
            'config'         => $config,
            'es_obligatorio' => filter_var($datos['es_obligatorio'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'activo'         => filter_var($datos['activo'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
        // Nuevo (o cambiado de momento): va al final de su momento
        if ($actual === null || $actual['momento'] !== $momento) {
            $fila['orden'] = (int) ($modelo->selectMax('orden')->where('momento', $momento)->first()['orden'] ?? 0) + 1;
        }

        $db = db_connect();
        $db->transException(true)->transStart();
        if ($id === null) {
            $id = (int) $modelo->insert($fila, true);
        } else {
            $modelo->update($id, $fila);
        }
        $db->table('checklist_items_sucursales')->where('id_item', $id)->delete();
        foreach ($sucursales as $idSucursal) {
            $db->table('checklist_items_sucursales')->insert(['id_item' => $id, 'id_sucursal' => $idSucursal]);
        }
        $db->transComplete();

        return $this->ok(['id' => $id] + $this->listado(), $actual === null ? 201 : 200);
    }

    private function listado(): array
    {
        return [
            'items'      => model(ChecklistItemModel::class)->todos(),
            'sucursales' => array_map(static fn (array $s) => ['id' => (int) $s['id_sucursal'], 'nombre' => $s['nombre']],
                db_connect()->table('sucursales')->select('id_sucursal, nombre')->orderBy('es_matriz', 'DESC')->orderBy('nombre')->get()->getResultArray()),
        ];
    }
}
