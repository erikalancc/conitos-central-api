<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Un sabor puede aplicar a varios tipos (Helado, Paleta Agua, Agua Fresca…).
 *  - Nueva tabla sabores_tipos (id_sabor, tipo_sabor), muchos a muchos.
 *  - Se copia el tipo único que tenía cada sabor y se quita sabores.tipo_sabor.
 * productos.tipo_sabor no cambia: el producto sigue pidiendo sabores de un tipo.
 */
class SaboresTipos extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id_sabor'   => ['type' => 'INT', 'constraint' => 11],
            'tipo_sabor' => ['type' => 'VARCHAR', 'constraint' => 30],
        ]);
        $this->forge->addPrimaryKey(['id_sabor', 'tipo_sabor']);
        $this->forge->addKey('tipo_sabor');
        $this->forge->addForeignKey('id_sabor', 'sabores', 'id_sabor', 'CASCADE', 'CASCADE');
        $this->forge->createTable('sabores_tipos', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci']);

        if ($this->db->fieldExists('tipo_sabor', 'sabores')) {
            $this->db->query(
                "INSERT IGNORE INTO sabores_tipos (id_sabor, tipo_sabor)
                 SELECT id_sabor, TRIM(tipo_sabor) FROM sabores WHERE tipo_sabor IS NOT NULL AND TRIM(tipo_sabor) <> ''"
            );
            $this->forge->dropColumn('sabores', 'tipo_sabor');
        }
    }

    public function down()
    {
        if (! $this->db->fieldExists('tipo_sabor', 'sabores')) {
            $this->forge->addColumn('sabores', [
                'tipo_sabor' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'nombre'],
            ]);
        }
        // Al regresar a un solo tipo se conserva el primero (alfabético) de cada sabor
        $this->db->query(
            'UPDATE sabores s
             JOIN (SELECT id_sabor, MIN(tipo_sabor) AS tipo FROM sabores_tipos GROUP BY id_sabor) t ON t.id_sabor = s.id_sabor
             SET s.tipo_sabor = t.tipo'
        );
        $this->forge->dropTable('sabores_tipos', true);
    }
}
