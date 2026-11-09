<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Caja por sucursal: cada sucursal tiene su propio turno y su corte solo incluye sus ventas.
 *  - cortes_caja.id_sucursal: sucursal del turno (un turno abierto por sucursal).
 *  - ventas.id_sucursal: sucursal donde se cobró la venta.
 *
 * Datos existentes: se les asigna la sucursal de trabajo de quien abrió el turno / vendió
 * (la suya si está activa; si no, la matriz), igual que hace el API al iniciar sesión.
 */
class CajaPorSucursal extends Migration
{
    public function up()
    {
        $this->forge->addColumn('cortes_caja', [
            'id_sucursal' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'comment' => 'Sucursal del turno', 'after' => 'estado'],
        ]);
        $this->forge->addColumn('ventas', [
            'id_sucursal' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'comment' => 'Sucursal donde se cobró', 'after' => 'id_corte'],
        ]);

        $matriz = $this->db->table('sucursales')->select('id_sucursal')
            ->where('activo', 1)->orderBy('es_matriz', 'DESC')->orderBy('id_sucursal')->get(1)->getRowArray();
        $idMatriz = $matriz === null ? 'NULL' : (int) $matriz['id_sucursal'];

        // Sucursal de trabajo del usuario: la suya si existe y está activa; si no, la matriz
        $deUsuario = static fn (string $columna) => "(SELECT COALESCE(s.id_sucursal, {$idMatriz})
            FROM usuarios u LEFT JOIN sucursales s ON s.id_sucursal = u.id_sucursal AND s.activo = 1
            WHERE u.id_usuario = {$columna})";

        $this->db->query('UPDATE cortes_caja SET id_sucursal = ' . $deUsuario('cortes_caja.id_usuario_apertura') . ' WHERE id_sucursal IS NULL');
        // La venta toma la sucursal de su turno; las que no tienen turno, la del vendedor
        $this->db->query('UPDATE ventas v JOIN cortes_caja c ON c.id_corte = v.id_corte SET v.id_sucursal = c.id_sucursal WHERE v.id_sucursal IS NULL');
        $this->db->query('UPDATE ventas SET id_sucursal = ' . $deUsuario('ventas.id_usuario_vendedor') . ' WHERE id_sucursal IS NULL');

        $this->db->query('ALTER TABLE cortes_caja ADD KEY cortes_caja_sucursal_estado (id_sucursal, estado),
            ADD CONSTRAINT cortes_caja_id_sucursal_foreign FOREIGN KEY (id_sucursal) REFERENCES sucursales (id_sucursal) ON DELETE SET NULL ON UPDATE CASCADE');
        $this->db->query('ALTER TABLE ventas ADD KEY ventas_sucursal_fecha (id_sucursal, fecha_hora),
            ADD CONSTRAINT ventas_id_sucursal_foreign FOREIGN KEY (id_sucursal) REFERENCES sucursales (id_sucursal) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE ventas DROP FOREIGN KEY ventas_id_sucursal_foreign, DROP KEY ventas_sucursal_fecha');
        $this->db->query('ALTER TABLE cortes_caja DROP FOREIGN KEY cortes_caja_id_sucursal_foreign, DROP KEY cortes_caja_sucursal_estado');
        $this->forge->dropColumn('ventas', 'id_sucursal');
        $this->forge->dropColumn('cortes_caja', 'id_sucursal');
    }
}
