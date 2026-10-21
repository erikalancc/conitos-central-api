<?php

namespace App\Models;

use CodeIgniter\Model;

class PresentacionModel extends Model
{
    protected $table         = 'presentaciones';
    protected $primaryKey    = 'id_presentacion';
    protected $returnType    = 'array';
    protected $allowedFields = ['id_producto', 'nombre', 'precio_base', 'num_sabores', 'activo'];
}
