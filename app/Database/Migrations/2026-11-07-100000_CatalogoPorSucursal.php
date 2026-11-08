<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Catálogo por sucursal (el producto se registra una sola vez):
 *  - productos_sucursales: en qué sucursales se vende cada producto. Sin fila = no se vende ahí.
 *  - precios_sucursales: precio de una presentación en una sucursal, SOLO cuando es distinto
 *    del precio general (presentaciones.precio_base). Sin fila = precio general.
 * Al migrar, todos los productos quedan a la venta en todas las sucursales con su precio general
 * (el POS se ve igual que antes hasta que se ajuste algo).
 */
class CatalogoPorSucursal extends Migration
{
    private const OPCIONES = ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci'];

    public function up()
    {
        $this->forge->addField([
            'id_producto' => ['type' => 'INT', 'constraint' => 11],
            'id_sucursal' => ['type' => 'INT', 'constraint' => 11],
        ]);
        $this->forge->addPrimaryKey(['id_producto', 'id_sucursal']);
        $this->forge->addKey('id_sucursal');
        $this->forge->addForeignKey('id_producto', 'productos', 'id_producto', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_sucursal', 'sucursales', 'id_sucursal', 'CASCADE', 'CASCADE');
        $this->forge->createTable('productos_sucursales', true, self::OPCIONES);

        $this->forge->addField([
            'id_presentacion' => ['type' => 'INT', 'constraint' => 11],
            'id_sucursal'     => ['type' => 'INT', 'constraint' => 11],
            'precio'          => ['type' => 'DECIMAL', 'constraint' => '8,2'],
        ]);
        $this->forge->addPrimaryKey(['id_presentacion', 'id_sucursal']);
        $this->forge->addKey('id_sucursal');
        $this->forge->addForeignKey('id_presentacion', 'presentaciones', 'id_presentacion', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_sucursal', 'sucursales', 'id_sucursal', 'CASCADE', 'CASCADE');
        $this->forge->createTable('precios_sucursales', true, self::OPCIONES);

        // Todo lo que existe hoy se sigue vendiendo en todas las sucursales
        $this->db->query('INSERT IGNORE INTO productos_sucursales (id_producto, id_sucursal) SELECT p.id_producto, s.id_sucursal FROM productos p CROSS JOIN sucursales s');
    }

    public function down()
    {
        $this->forge->dropTable('precios_sucursales', true);
        $this->forge->dropTable('productos_sucursales', true);
    }
}
