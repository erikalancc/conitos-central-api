<?php

namespace App\Models;

use CodeIgniter\Model;

class PromocionModel extends Model
{
    protected $table         = 'promociones';
    protected $primaryKey    = 'id_promocion';
    protected $returnType    = 'array';
    protected $allowedFields = ['nombre', 'precio_combo', 'dia_semana', 'activa'];
}
