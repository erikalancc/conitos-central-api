<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Puntos del checklist operativo (se configuran en Administración → Checklist operativo).
 * Cada punto es de un momento del día y aplica a ciertas sucursales (sin filas en
 * checklist_items_sucursales = todas). El punto fijo (es_fijo) es el de refrigeradores y congeladores.
 */
class ChecklistItemModel extends Model
{
    // Momento en la URL (/checklist/{momento}) => valor en la BD
    public const MOMENTOS = ['apertura' => 'APERTURA', 'medio-turno' => 'MEDIO_TURNO', 'cierre' => 'CIERRE'];

    // Tipos que se pueden crear desde Administración (TEMPERATURE_PAIR es solo el punto fijo)
    public const TIPOS = ['BOOLEAN', 'SELECT', 'TEXT'];

    protected $table         = 'checklist_items';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['orden', 'momento', 'titulo', 'descripcion', 'etiqueta_ok', 'etiqueta', 'tipo_campo', 'config', 'es_obligatorio', 'es_fijo', 'activo'];

    // Puntos activos de un momento que aplican a la sucursal, en orden y con config decodificado
    public function activos(string $momento, ?int $idSucursal): array
    {
        $items = $this->where('activo', 1)->where('momento', $momento)
            ->groupStart()
            ->where('NOT EXISTS (SELECT 1 FROM checklist_items_sucursales cs WHERE cs.id_item = checklist_items.id)', null, false)
            ->orWhere('EXISTS (SELECT 1 FROM checklist_items_sucursales cs WHERE cs.id_item = checklist_items.id AND cs.id_sucursal = ' . (int) $idSucursal . ')', null, false)
            ->groupEnd()
            ->orderBy('orden')->orderBy('id')->findAll();

        return array_map([self::class, 'publico'], $items);
    }

    // Todos los puntos (Administración), con sus sucursales y si ya tienen respuestas
    public function todos(): array
    {
        $sucursales = [];
        foreach ($this->db->table('checklist_items_sucursales')->get()->getResultArray() as $fila) {
            $sucursales[$fila['id_item']][] = (int) $fila['id_sucursal'];
        }
        $usados = array_flip(array_map('intval', array_column(
            $this->db->table('checklist_respuestas')->select('id_item')->distinct()->get()->getResultArray(),
            'id_item'
        )));

        return array_map(static fn (array $item) => self::publico($item) + [
            'activo'     => (bool) $item['activo'],
            'sucursales' => $sucursales[$item['id']] ?? [],
            'usado'      => isset($usados[(int) $item['id']]),
        ], $this->orderBy("FIELD(momento, 'APERTURA', 'MEDIO_TURNO', 'CIERRE')", '', false)->orderBy('orden')->orderBy('id')->findAll());
    }

    public static function publico(array $item): array
    {
        return [
            'id'             => (int) $item['id'],
            'orden'          => (int) $item['orden'],
            'momento'        => array_search($item['momento'], self::MOMENTOS, true),
            'titulo'         => $item['titulo'],
            'descripcion'    => $item['descripcion'],
            'etiqueta_ok'    => $item['etiqueta_ok'],
            'etiqueta'       => $item['etiqueta'],
            'tipo_campo'     => $item['tipo_campo'],
            'config'         => $item['config'] === null ? null : json_decode($item['config'], true),
            'es_obligatorio' => (bool) $item['es_obligatorio'],
            'es_fijo'        => (bool) $item['es_fijo'],
        ];
    }
}
