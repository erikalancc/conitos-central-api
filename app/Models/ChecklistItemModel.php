<?php

namespace App\Models;

use CodeIgniter\Model;

class ChecklistItemModel extends Model
{
    protected $table         = 'checklist_items';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['orden', 'titulo', 'descripcion', 'etiqueta_ok', 'etiqueta', 'tipo_campo', 'config', 'es_obligatorio', 'activo'];

    // Puntos activos en orden, con config decodificado
    public function activos(): array
    {
        return array_map(static fn (array $item) => [
            'id'             => (int) $item['id'],
            'orden'          => (int) $item['orden'],
            'titulo'         => $item['titulo'],
            'descripcion'    => $item['descripcion'],
            'etiqueta_ok'    => $item['etiqueta_ok'],
            'etiqueta'       => $item['etiqueta'],
            'tipo_campo'     => $item['tipo_campo'],
            'config'         => $item['config'] === null ? null : json_decode($item['config'], true),
            'es_obligatorio' => (bool) $item['es_obligatorio'],
        ], $this->where('activo', 1)->orderBy('orden')->orderBy('id')->findAll());
    }
}
