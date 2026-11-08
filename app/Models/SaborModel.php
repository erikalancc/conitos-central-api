<?php

namespace App\Models;

use CodeIgniter\Model;

class SaborModel extends Model
{
    protected $table         = 'sabores';
    protected $primaryKey    = 'id_sabor';
    protected $returnType    = 'array';
    // Los tipos a los que aplica el sabor van en sabores_tipos (ver tiposPorSabor)
    protected $allowedFields = ['nombre', 'es_especial', 'activo'];

    /**
     * Tipos de cada sabor: [id_sabor => ['Helado', 'Paleta Agua', …]] (orden alfabético).
     */
    public static function tiposPorSabor(): array
    {
        $tipos = [];
        $filas = db_connect()->table('sabores_tipos')->orderBy('tipo_sabor')->get()->getResultArray();
        foreach ($filas as $fila) {
            $tipos[(int) $fila['id_sabor']][] = $fila['tipo_sabor'];
        }

        return $tipos;
    }
}
