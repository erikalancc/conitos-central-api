<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Imagen del producto: ruta relativa dentro de public/ (p. ej. "uploads/productos/abc.jpg").
 * NULL = sin imagen (el POS muestra el ícono de la categoría).
 */
class ProductosImagen extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('imagen', 'productos')) {
            $this->forge->addColumn('productos', [
                'imagen' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'nombre'],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('imagen', 'productos')) {
            $this->forge->dropColumn('productos', 'imagen');
        }
    }
}
