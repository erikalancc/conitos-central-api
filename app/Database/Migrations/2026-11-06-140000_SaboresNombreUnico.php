<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Nombre de sabor único. Con la intercalación utf8mb4_unicode_ci la BD trata como iguales
 * mayúsculas/minúsculas, acentos y espacios al final ("Limón" = "limon"), igual que el API.
 */
class SaboresNombreUnico extends Migration
{
    public function up()
    {
        $repetidos = $this->db->query(
            'SELECT GROUP_CONCAT(nombre SEPARATOR ", ") AS nombres FROM sabores GROUP BY nombre HAVING COUNT(*) > 1'
        )->getResultArray();
        if ($repetidos !== []) {
            throw new RuntimeException('Hay sabores repetidos; renómbralos antes de migrar: ' . implode(' | ', array_column($repetidos, 'nombres')));
        }

        $this->db->query('ALTER TABLE sabores ADD UNIQUE KEY uq_sabores_nombre (nombre)');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE sabores DROP INDEX uq_sabores_nombre');
    }
}
