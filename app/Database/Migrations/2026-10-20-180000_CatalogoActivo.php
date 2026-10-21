<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Bandera "activo" en el catálogo: desactivar oculta el registro del POS sin borrarlo.
 * Borrar un sabor o una presentación arrastraría el detalle de ventas ya registradas
 * (ON DELETE CASCADE / SET NULL), así que solo se borra lo que nunca se ha vendido.
 */
class CatalogoActivo extends Migration
{
    private const TABLAS = [
        'categorias'      => 'nombre',
        'productos'       => 'requiere_sabor',
        'presentaciones'  => 'precio_base',
        'sabores'         => 'es_especial',
        'extras_toppings' => 'precio',
    ];

    public function up()
    {
        foreach (self::TABLAS as $tabla => $despuesDe) {
            $this->forge->addColumn($tabla, [
                'activo' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                    'null'       => false,
                    'after'      => $despuesDe,
                    'comment'    => '0 = oculto en el POS (se conserva para el historial de ventas)',
                ],
            ]);
        }
    }

    public function down()
    {
        foreach (array_keys(self::TABLAS) as $tabla) {
            $this->forge->dropColumn($tabla, 'activo');
        }
    }
}
