<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductoModel extends Model
{
    protected $table         = 'productos';
    protected $primaryKey    = 'id_producto';
    protected $returnType    = 'array';
    protected $allowedFields = ['id_categoria', 'nombre', 'requiere_sabor', 'tipo_sabor', 'activo'];
}
