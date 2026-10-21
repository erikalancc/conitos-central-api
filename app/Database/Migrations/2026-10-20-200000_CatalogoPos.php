<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lo que el POS necesita para armar pedidos desde la BD:
 *  - productos.tipo_sabor: qué sabores se pueden elegir (coincide con sabores.tipo_sabor)
 *  - presentaciones.num_sabores: cuántos sabores lleva (Chico 1 bola = 1, Grande 2 bolas = 2)
 *  - productos_extras: qué extras/toppings se ofrecen en cada producto
 *
 * Los valores iniciales se deducen de los nombres actuales y se ajustan desde Administración.
 */
class CatalogoPos extends Migration
{
    public function up()
    {
        $this->forge->addColumn('productos', [
            'tipo_sabor' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'after'      => 'requiere_sabor',
                'comment'    => 'Tipo de sabores que se pueden elegir (sabores.tipo_sabor)',
            ],
        ]);

        $this->forge->addColumn('presentaciones', [
            'num_sabores' => [
                'type'       => 'TINYINT',
                'unsigned'   => true,
                'default'    => 1,
                'null'       => false,
                'after'      => 'precio_base',
                'comment'    => 'Sabores a elegir en esta presentación (si el producto requiere sabor)',
            ],
        ]);

        $this->forge->addField([
            'id_producto' => ['type' => 'INT'],
            'id_extra'    => ['type' => 'INT'],
        ]);
        $this->forge->addPrimaryKey(['id_producto', 'id_extra']);
        $this->forge->addForeignKey('id_producto', 'productos', 'id_producto', '', 'CASCADE');
        $this->forge->addForeignKey('id_extra', 'extras_toppings', 'id_extra', '', 'CASCADE');
        $this->forge->createTable('productos_extras', false, ['ENGINE' => 'InnoDB', 'COMMENT' => 'Extras/toppings que se ofrecen en cada producto']);

        $this->sembrar();
    }

    public function down()
    {
        $this->forge->dropTable('productos_extras', true);
        $this->forge->dropColumn('presentaciones', 'num_sabores');
        $this->forge->dropColumn('productos', 'tipo_sabor');
    }

    // Valores iniciales según los nombres que ya existen (si alguno no existe, se omite)
    private function sembrar(): void
    {
        $tipos = [
            'Helado Tradicional'      => 'Helado',
            'Malteada'                => 'Helado',
            'Paleta de Agua'          => 'Paleta Agua',
            'Paleta Mini'             => 'Paleta Agua',
            'Paleta de Leche / Crema' => 'Paleta Crema',
            'Agua Fresca'             => 'Agua Fresca',
        ];
        foreach ($tipos as $producto => $tipo) {
            $this->db->table('productos')->where('nombre', $producto)->update(['tipo_sabor' => $tipo]);
        }

        $sabores = ['Grande (2 bolas)' => 2, '1/2 Litro' => 2, '1 Litro' => 3];
        foreach ($sabores as $presentacion => $cantidad) {
            $this->db->query(
                'UPDATE presentaciones p JOIN productos pr ON pr.id_producto = p.id_producto
                 SET p.num_sabores = ? WHERE p.nombre = ? AND pr.tipo_sabor = ?',
                [$cantidad, $presentacion, 'Helado']
            );
        }

        $extras = [
            'Crepa 3 Ingredientes' => ['Ingrediente extra crepa', 'Bola extra helado en crepa'],
            'Helado Tradicional'   => ['Bola extra helado regular', 'Bola extra Piñón'],
        ];
        foreach ($extras as $producto => $nombres) {
            $this->db->query(
                'INSERT IGNORE INTO productos_extras (id_producto, id_extra)
                 SELECT p.id_producto, e.id_extra FROM productos p JOIN extras_toppings e
                 WHERE p.nombre = ? AND e.nombre IN ?',
                [$producto, $nombres]
            );
        }
    }
}
