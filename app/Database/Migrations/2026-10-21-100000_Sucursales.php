<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sucursales y sus terminales POS (cajas, tablets, kioskos).
 * Se registra como matriz la sucursal con la que ya opera el sistema (Terminal #01).
 */
class Sucursales extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id_sucursal'   => ['type' => 'INT', 'auto_increment' => true],
            'codigo'        => ['type' => 'VARCHAR', 'constraint' => 20, 'comment' => 'Clave visible, p. ej. SUC-001'],
            'nombre'        => ['type' => 'VARCHAR', 'constraint' => 100],
            'direccion'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'telefono'      => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'correo'        => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'id_encargado'  => ['type' => 'INT', 'null' => true],
            'hora_apertura' => ['type' => 'TIME', 'null' => true],
            'hora_cierre'   => ['type' => 'TIME', 'null' => true],
            // Modalidades de venta que ofrece la sucursal
            'venta_mostrador' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'para_llevar'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'apps_delivery'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'comment' => 'Rappi / Uber Eats'],
            'drive_thru'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'es_matriz'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'activo'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id_sucursal');
        $this->forge->addUniqueKey('codigo');
        $this->forge->addForeignKey('id_encargado', 'usuarios', 'id_usuario', '', 'SET NULL');
        $this->forge->createTable('sucursales', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_terminal' => ['type' => 'INT', 'auto_increment' => true],
            'id_sucursal' => ['type' => 'INT'],
            'nombre'      => ['type' => 'VARCHAR', 'constraint' => 50, 'comment' => 'p. ej. Caja #01 Principal'],
            'tipo'        => ['type' => 'ENUM', 'constraint' => ['CAJA', 'TABLET', 'KIOSKO'], 'default' => 'CAJA'],
            'activo'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addPrimaryKey('id_terminal');
        $this->forge->addForeignKey('id_sucursal', 'sucursales', 'id_sucursal', '', 'CASCADE');
        $this->forge->createTable('terminales', false, ['ENGINE' => 'InnoDB']);

        $ahora = date('Y-m-d H:i:s');
        $this->db->table('sucursales')->insert([
            'codigo'     => 'SUC-001',
            'nombre'     => 'Sucursal Centro Histórico',
            'es_matriz'  => 1,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        $this->db->table('terminales')->insert(['id_sucursal' => $this->db->insertID(), 'nombre' => 'Caja #01', 'tipo' => 'CAJA']);
    }

    public function down()
    {
        $this->forge->dropTable('terminales', true);
        $this->forge->dropTable('sucursales', true);
    }
}
