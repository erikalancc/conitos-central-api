<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Puntos del checklist de apertura. Solo inserta si la tabla está vacía.
 * php spark db:seed ChecklistItemsSeeder
 */
class ChecklistItemsSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('checklist_items')->countAllResults() > 0) {
            return;
        }

        $items = [
            [
                'titulo'      => 'Revisión de productos en vitrina',
                'descripcion' => 'Caducidad, consistencia cremosa, rotulación y frescura de helados',
                'etiqueta_ok' => 'Conforme',
                'tipo_campo'  => 'SELECT',
                'config'      => ['etiqueta' => 'Estado de lote', 'opciones' => [
                    ['valor' => 'conforme', 'texto' => 'Conforme y Aprobado', 'nivel' => 'ok'],
                    ['valor' => 'observaciones', 'texto' => 'Con observaciones', 'nivel' => 'aviso'],
                    ['valor' => 'rechazado', 'texto' => 'Rechazado', 'nivel' => 'error'],
                ]],
            ],
            [
                'titulo'      => 'Preparación de vegetales e insumos',
                'descripcion' => 'Tener listos los vegetales e ingredientes frescos (jícama, pepino picados y abastecidos en barra)',
                'etiqueta_ok' => 'Listo',
            ],
            [
                'titulo'      => 'Control de Refrigeración y Congeladores',
                'descripcion' => 'Revisar temperatura adecuada antes de comenzar el despacho',
                'etiqueta_ok' => 'En rango',
                'tipo_campo'  => 'TEMPERATURE_PAIR',
                'config'      => ['sensores' => [
                    ['clave' => 'congelador', 'nombre' => 'Congelador', 'min' => -30, 'max' => -15],
                    ['clave' => 'refrigerador', 'nombre' => 'Refrigerador', 'min' => 0, 'max' => 5],
                ]],
            ],
            [
                'titulo'      => 'Limpieza general y sanitización',
                'descripcion' => 'Mostrador, cucharas dosificadoras, piso de servicio y vitrinas desinfectadas',
                'etiqueta_ok' => 'Sanitizado',
            ],
            [
                'titulo'      => 'Plataformas de Delivery Activas',
                'descripcion' => 'Apps conectadas con inventario y promociones activas sincronizadas',
                'etiqueta_ok' => 'En línea',
                'etiqueta'    => 'Rappi & Uber Eats',
            ],
        ];

        foreach ($items as $i => $item) {
            $this->db->table('checklist_items')->insert([
                'orden'          => $i + 1,
                'titulo'         => $item['titulo'],
                'descripcion'    => $item['descripcion'],
                'etiqueta_ok'    => $item['etiqueta_ok'],
                'etiqueta'       => $item['etiqueta'] ?? null,
                'tipo_campo'     => $item['tipo_campo'] ?? 'BOOLEAN',
                'config'         => isset($item['config']) ? json_encode($item['config'], JSON_UNESCAPED_UNICODE) : null,
                'es_obligatorio' => 1,
                'activo'         => 1,
            ]);
        }
    }
}
