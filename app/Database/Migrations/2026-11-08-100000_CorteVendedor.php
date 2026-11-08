<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Corte de caja por pasos y vista limitada de ventas para el Vendedor / Cajero.
 *  - sucursales.ventas_visibles_vendedor: cuántas de las últimas ventas puede ver el vendedor (0 = ninguna).
 *  - cortes_caja.fondo_siguiente: efectivo que se deja en gaveta como fondo del siguiente turno
 *    (se propone al abrir caja). Se retira para depósito: efectivo contado - fondo_siguiente.
 */
class CorteVendedor extends Migration
{
    public function up()
    {
        $this->forge->addColumn('sucursales', [
            'ventas_visibles_vendedor' => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 5, 'comment' => 'Últimas ventas que ve el vendedor (0 = ninguna)', 'after' => 'drive_thru'],
        ]);
        $this->forge->addColumn('cortes_caja', [
            'fondo_siguiente' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'comment' => 'Fondo que se deja para el siguiente turno', 'after' => 'efectivo_contado'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('cortes_caja', 'fondo_siguiente');
        $this->forge->dropColumn('sucursales', 'ventas_visibles_vendedor');
    }
}
