<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Catálogo de usuarios:
 *  - usuarios.id_sucursal: sucursal donde trabaja (los actuales quedan en la matriz)
 *  - usuarios.ultimo_acceso: se actualiza en cada login
 */
class UsuariosCatalogo extends Migration
{
    public function up()
    {
        $this->forge->addColumn('usuarios', [
            'id_sucursal'   => ['type' => 'INT', 'null' => true, 'after' => 'id_rol', 'comment' => 'Sucursal donde trabaja'],
            'ultimo_acceso' => ['type' => 'DATETIME', 'null' => true, 'after' => 'activo'],
        ]);
        $this->forge->addForeignKey('id_sucursal', 'sucursales', 'id_sucursal', '', 'SET NULL', 'fk_usuarios_sucursal');
        $this->forge->processIndexes('usuarios');

        $matriz = $this->db->table('sucursales')->select('id_sucursal')->where('es_matriz', 1)->get()->getRowArray();
        if ($matriz !== null) {
            $this->db->table('usuarios')->where('id_sucursal', null)->update(['id_sucursal' => $matriz['id_sucursal']]);
        }
    }

    public function down()
    {
        $this->forge->dropForeignKey('usuarios', 'fk_usuarios_sucursal');
        $this->forge->dropColumn('usuarios', ['id_sucursal', 'ultimo_acceso']);
    }
}
