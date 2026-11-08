<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Checklist operativo configurable desde Administración.
 *  - checklist_items.momento: APERTURA (al iniciar el día), MEDIO_TURNO (a la mitad del horario
 *    de la sucursal) o CIERRE (antes del corte de caja).
 *  - checklist_items.es_fijo: el punto de refrigeradores y congeladores no se edita ni se borra.
 *  - checklist_items_sucursales: en qué sucursales aplica cada punto (sin filas = todas).
 *  - checklist_registros.momento: un registro por día, sucursal y momento.
 */
class ChecklistConfigurable extends Migration
{
    public function up()
    {
        $this->forge->addColumn('checklist_items', [
            'momento' => ['type' => 'ENUM', 'constraint' => ['APERTURA', 'MEDIO_TURNO', 'CIERRE'], 'default' => 'APERTURA', 'after' => 'orden'],
            'es_fijo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'es_obligatorio'],
        ]);
        // El de temperaturas es el punto fijo (se llena con los equipos de frío de cada sucursal)
        $this->db->query("UPDATE checklist_items SET es_fijo = 1, momento = 'APERTURA' WHERE tipo_campo = 'TEMPERATURE_PAIR'");

        $this->forge->addField([
            'id_item'     => ['type' => 'INT', 'constraint' => 11],
            'id_sucursal' => ['type' => 'INT', 'constraint' => 11],
        ]);
        $this->forge->addPrimaryKey(['id_item', 'id_sucursal']);
        $this->forge->addKey('id_sucursal');
        $this->forge->addForeignKey('id_item', 'checklist_items', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_sucursal', 'sucursales', 'id_sucursal', 'CASCADE', 'CASCADE');
        $this->forge->createTable('checklist_items_sucursales', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci']);

        $this->forge->addColumn('checklist_registros', [
            'momento' => ['type' => 'ENUM', 'constraint' => ['APERTURA', 'MEDIO_TURNO', 'CIERRE'], 'default' => 'APERTURA', 'after' => 'id_sucursal'],
        ]);
        $this->db->query('ALTER TABLE checklist_registros DROP INDEX uq_checklist_fecha_sucursal, ADD UNIQUE KEY uq_checklist_fecha_sucursal_momento (fecha, id_sucursal, momento)');
    }

    public function down()
    {
        $this->db->query("DELETE FROM checklist_registros WHERE momento <> 'APERTURA'");
        $this->db->query('ALTER TABLE checklist_registros DROP INDEX uq_checklist_fecha_sucursal_momento, ADD UNIQUE KEY uq_checklist_fecha_sucursal (fecha, id_sucursal)');
        $this->forge->dropColumn('checklist_registros', 'momento');
        $this->forge->dropTable('checklist_items_sucursales', true);
        $this->forge->dropColumn('checklist_items', ['momento', 'es_fijo']);
    }
}
