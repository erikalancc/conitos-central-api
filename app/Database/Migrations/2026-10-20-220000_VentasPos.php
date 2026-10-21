<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Datos de la venta que el POS ya captura y la BD no guardaba:
 * número de orden, cliente, servicio, subtotal/descuento, efectivo recibido y notas por línea.
 */
class VentasPos extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ventas', [
            'folio_orden'        => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'id_venta', 'comment' => 'Número de orden que ve el cliente en el POS'],
            'cliente'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'fecha_hora'],
            'tipo_servicio'      => ['type' => 'ENUM', 'constraint' => ['AQUI', 'LLEVAR'], 'default' => 'LLEVAR', 'after' => 'cliente'],
            'subtotal'           => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0, 'after' => 'id_metodo_pago'],
            'descuento'          => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0, 'after' => 'subtotal'],
            'descuento_concepto' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'descuento'],
            'monto_recibido'     => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'after' => 'total', 'comment' => 'Efectivo entregado por el cliente'],
            'cambio'             => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'after' => 'monto_recibido'],
        ]);

        $this->forge->addColumn('detalle_ventas', [
            'notas' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'subtotal', 'comment' => 'Indicaciones de preparación o contenido del combo'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('detalle_ventas', 'notas');
        $this->forge->dropColumn('ventas', ['folio_orden', 'cliente', 'tipo_servicio', 'subtotal', 'descuento', 'descuento_concepto', 'monto_recibido', 'cambio']);
    }
}
