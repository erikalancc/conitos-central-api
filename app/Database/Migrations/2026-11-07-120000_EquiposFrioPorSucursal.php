<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Congeladores y refrigeradores por sucursal, y checklist de apertura por sucursal.
 *  - equipos_frio: equipos de cada sucursal con su rango permitido (°C, admite negativos).
 *  - checklist_registros: se agrega id_sucursal; ahora es uno por día POR SUCURSAL.
 *  - checklist_temperaturas: guarda el equipo y el rango vigente al capturar (para el historial).
 * Cada sucursal arranca con los dos equipos que antes eran fijos (Congelador -30 a -15, Refrigerador 0 a 5).
 */
class EquiposFrioPorSucursal extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id_equipo'   => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'id_sucursal' => ['type' => 'INT', 'constraint' => 11],
            'nombre'      => ['type' => 'VARCHAR', 'constraint' => 60],
            'tipo'        => ['type' => 'ENUM', 'constraint' => ['CONGELADOR', 'REFRIGERADOR'], 'default' => 'CONGELADOR'],
            'temp_min'    => ['type' => 'DECIMAL', 'constraint' => '5,2', 'comment' => '°C mínimo permitido (puede ser negativo)'],
            'temp_max'    => ['type' => 'DECIMAL', 'constraint' => '5,2', 'comment' => '°C máximo permitido (puede ser negativo)'],
            'activo'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addPrimaryKey('id_equipo');
        $this->forge->addUniqueKey(['id_sucursal', 'nombre']);
        $this->forge->addForeignKey('id_sucursal', 'sucursales', 'id_sucursal', 'CASCADE', 'CASCADE');
        $this->forge->createTable('equipos_frio', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci']);

        // Los dos equipos que antes eran fijos, en cada sucursal
        $this->db->query("INSERT INTO equipos_frio (id_sucursal, nombre, tipo, temp_min, temp_max)
            SELECT id_sucursal, 'Congelador', 'CONGELADOR', -30, -15 FROM sucursales");
        $this->db->query("INSERT INTO equipos_frio (id_sucursal, nombre, tipo, temp_min, temp_max)
            SELECT id_sucursal, 'Refrigerador', 'REFRIGERADOR', 0, 5 FROM sucursales");

        // Checklist por sucursal: los registros anteriores se asignan a la sucursal de quien los hizo (o a la matriz)
        $this->forge->addColumn('checklist_registros', [
            'id_sucursal' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'fecha'],
        ]);
        $this->db->query('UPDATE checklist_registros r
            JOIN usuarios u ON u.id_usuario = r.id_usuario
            SET r.id_sucursal = COALESCE(u.id_sucursal, (SELECT id_sucursal FROM sucursales ORDER BY es_matriz DESC, id_sucursal LIMIT 1))');
        $this->db->query('UPDATE checklist_registros SET id_sucursal = (SELECT id_sucursal FROM sucursales ORDER BY es_matriz DESC, id_sucursal LIMIT 1) WHERE id_sucursal IS NULL');
        $this->db->query('ALTER TABLE checklist_registros MODIFY id_sucursal INT(11) NOT NULL');
        $this->db->query('ALTER TABLE checklist_registros DROP INDEX fecha');
        $this->db->query('ALTER TABLE checklist_registros ADD UNIQUE KEY uq_checklist_fecha_sucursal (fecha, id_sucursal)');
        $this->db->query('ALTER TABLE checklist_registros ADD CONSTRAINT fk_checklist_registros_sucursal FOREIGN KEY (id_sucursal) REFERENCES sucursales (id_sucursal)');

        // Lecturas: equipo y rango vigente al momento de capturar
        $this->forge->addColumn('checklist_temperaturas', [
            'id_equipo' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'id_respuesta'],
            'temp_min'  => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true, 'after' => 'grados'],
            'temp_max'  => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true, 'after' => 'temp_min'],
        ]);
        $this->db->query('ALTER TABLE checklist_temperaturas ADD CONSTRAINT fk_checklist_temperaturas_equipo FOREIGN KEY (id_equipo) REFERENCES equipos_frio (id_equipo) ON DELETE SET NULL');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE checklist_temperaturas DROP FOREIGN KEY fk_checklist_temperaturas_equipo');
        $this->forge->dropColumn('checklist_temperaturas', ['id_equipo', 'temp_min', 'temp_max']);

        $this->db->query('ALTER TABLE checklist_registros DROP FOREIGN KEY fk_checklist_registros_sucursal');
        $this->db->query('ALTER TABLE checklist_registros DROP INDEX uq_checklist_fecha_sucursal');
        $this->forge->dropColumn('checklist_registros', 'id_sucursal');
        $this->db->query('ALTER TABLE checklist_registros ADD UNIQUE KEY fecha (fecha)');

        $this->forge->dropTable('equipos_frio', true);
    }
}
