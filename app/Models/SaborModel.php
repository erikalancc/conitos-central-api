<?php

namespace App\Models;

use CodeIgniter\Model;

class SaborModel extends Model
{
    protected $table         = 'sabores';
    protected $primaryKey    = 'id_sabor';
    protected $returnType    = 'array';
    protected $allowedFields = ['nombre', 'tipo_sabor', 'es_especial', 'activo'];
}
