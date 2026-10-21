<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Turnos de caja: se abren con un fondo inicial y se cierran con el corte Z.
 *  - cortes_caja: un registro por turno (apertura + resultado del corte)
 *  - cortes_caja_conteo: piezas contadas por denominación al cerrar
 *  - movimientos_caja: entradas y retiros de efectivo durante el turno
 *  - ventas.id_corte: turno al que pertenece cada venta
 * Solo puede haber un turno ABIERTO a la vez (una caja).
 */
class CortesCaja extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id_corte'            => ['type' => 'INT', 'auto_increment' => true],
            'estado'              => ['type' => 'ENUM', 'constraint' => ['ABIERTO', 'CERRADO'], 'default' => 'ABIERTO'],
            'id_usuario_apertura' => ['type' => 'INT'],
            'fecha_apertura'      => ['type' => 'DATETIME'],
            'fondo_inicial'       => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'comentario_apertura' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'id_usuario_cierre'   => ['type' => 'INT', 'null' => true],
            'fecha_cierre'        => ['type' => 'DATETIME', 'null' => true],
            // Totales congelados al cerrar (las ventas del turno ya no cambian)
            'tickets'             => ['type' => 'INT', 'null' => true],
            'ventas_brutas'       => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'descuentos'          => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'ventas_netas'        => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'ventas_efectivo'     => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'ventas_tarjeta'      => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'entradas'            => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'retiros'             => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'efectivo_esperado'   => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'efectivo_contado'    => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'diferencia_efectivo' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'tarjeta_terminal'    => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'comment' => 'Total del cierre de lote de la terminal bancaria'],
            'vouchers'            => ['type' => 'INT', 'null' => true],
            'diferencia_tarjeta'  => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'observaciones'       => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id_corte');
        $this->forge->addKey('estado');
        $this->forge->addKey('fecha_apertura');
        $this->forge->addForeignKey('id_usuario_apertura', 'usuarios', 'id_usuario');
        $this->forge->addForeignKey('id_usuario_cierre', 'usuarios', 'id_usuario');
        $this->forge->createTable('cortes_caja', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_corte'     => ['type' => 'INT'],
            'denominacion' => ['type' => 'DECIMAL', 'constraint' => '8,2'],
            'piezas'       => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey(['id_corte', 'denominacion']);
        $this->forge->addForeignKey('id_corte', 'cortes_caja', 'id_corte', '', 'CASCADE');
        $this->forge->createTable('cortes_caja_conteo', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_movimiento' => ['type' => 'INT', 'auto_increment' => true],
            'id_corte'      => ['type' => 'INT'],
            'id_usuario'    => ['type' => 'INT'],
            'tipo'          => ['type' => 'ENUM', 'constraint' => ['ENTRADA', 'RETIRO']],
            'monto'         => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'concepto'      => ['type' => 'VARCHAR', 'constraint' => 150],
            'fecha_hora'    => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id_movimiento');
        $this->forge->addForeignKey('id_corte', 'cortes_caja', 'id_corte', '', 'CASCADE');
        $this->forge->addForeignKey('id_usuario', 'usuarios', 'id_usuario');
        $this->forge->createTable('movimientos_caja', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addColumn('ventas', [
            'id_corte' => ['type' => 'INT', 'null' => true, 'after' => 'folio_orden', 'comment' => 'Turno de caja (cortes_caja)'],
        ]);
        $this->forge->addForeignKey('id_corte', 'cortes_caja', 'id_corte', '', 'SET NULL', 'fk_ventas_corte');
        $this->forge->processIndexes('ventas');
    }

    public function down()
    {
        $this->forge->dropForeignKey('ventas', 'fk_ventas_corte');
        $this->forge->dropColumn('ventas', 'id_corte');
        $this->forge->dropTable('movimientos_caja', true);
        $this->forge->dropTable('cortes_caja_conteo', true);
        $this->forge->dropTable('cortes_caja', true);
    }
}
