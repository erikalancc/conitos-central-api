<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Checklist de apertura:
 *  - checklist_items: se completa con lo que el front necesita para pintar cada punto
 *  - checklist_registros: un checklist por día (completado u omitido)
 *  - checklist_respuestas: respuesta de cada punto
 *  - checklist_temperaturas: lecturas de los puntos tipo TEMPERATURE_PAIR
 */
class ChecklistApertura extends Migration
{
    public function up()
    {
        $this->forge->addColumn('checklist_items', [
            'orden'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'after' => 'id'],
            'etiqueta_ok' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'descripcion', 'comment' => 'Texto del chip cuando el punto está cumplido'],
            'etiqueta'    => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true, 'after' => 'etiqueta_ok', 'comment' => 'Tag opcional junto al título'],
            'config'      => ['type' => 'TEXT', 'null' => true, 'after' => 'tipo_campo', 'comment' => 'JSON: opciones (SELECT) o sensores con rango (TEMPERATURE_PAIR)'],
        ]);

        $this->forge->addField([
            'id_registro'         => ['type' => 'INT', 'auto_increment' => true],
            'fecha'               => ['type' => 'DATE'],
            'id_usuario'          => ['type' => 'INT'],
            'estado'              => ['type' => 'ENUM', 'constraint' => ['COMPLETADO', 'OMITIDO']],
            'con_incidencia'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'comentarios'         => ['type' => 'TEXT', 'null' => true],
            'id_usuario_autoriza' => ['type' => 'INT', 'null' => true, 'comment' => 'Supervisor que autoriza una omisión'],
            'fecha_autorizacion'  => ['type' => 'DATETIME', 'null' => true],
            'created_at'          => ['type' => 'DATETIME'],
            'updated_at'          => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id_registro');
        $this->forge->addUniqueKey('fecha');
        $this->forge->addForeignKey('id_usuario', 'usuarios', 'id_usuario');
        $this->forge->addForeignKey('id_usuario_autoriza', 'usuarios', 'id_usuario');
        $this->forge->createTable('checklist_registros', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_respuesta' => ['type' => 'INT', 'auto_increment' => true],
            'id_registro'  => ['type' => 'INT'],
            'id_item'      => ['type' => 'INT'],
            'cumplido'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'valor'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'comment' => 'Opción elegida (SELECT) o texto (TEXT)'],
        ]);
        $this->forge->addPrimaryKey('id_respuesta');
        $this->forge->addUniqueKey(['id_registro', 'id_item']);
        $this->forge->addForeignKey('id_registro', 'checklist_registros', 'id_registro', '', 'CASCADE');
        $this->forge->addForeignKey('id_item', 'checklist_items', 'id');
        $this->forge->createTable('checklist_respuestas', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_temperatura' => ['type' => 'INT', 'auto_increment' => true],
            'id_respuesta'   => ['type' => 'INT'],
            'sensor'         => ['type' => 'VARCHAR', 'constraint' => 30, 'comment' => 'Clave del sensor en config (congelador, refrigerador)'],
            'grados'         => ['type' => 'DECIMAL', 'constraint' => '5,2'],
            'en_rango'       => ['type' => 'TINYINT', 'constraint' => 1],
        ]);
        $this->forge->addPrimaryKey('id_temperatura');
        $this->forge->addForeignKey('id_respuesta', 'checklist_respuestas', 'id_respuesta', '', 'CASCADE');
        $this->forge->createTable('checklist_temperaturas', false, ['ENGINE' => 'InnoDB']);
    }

    public function down()
    {
        $this->forge->dropTable('checklist_temperaturas', true);
        $this->forge->dropTable('checklist_respuestas', true);
        $this->forge->dropTable('checklist_registros', true);
        $this->forge->dropColumn('checklist_items', ['orden', 'etiqueta_ok', 'etiqueta', 'config']);
    }
}
