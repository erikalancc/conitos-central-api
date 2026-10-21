<?php

namespace App\Models;

use CodeIgniter\Model;

class ExtraModel extends Model
{
    protected $table         = 'extras_toppings';
    protected $primaryKey    = 'id_extra';
    protected $returnType    = 'array';
    protected $allowedFields = ['nombre', 'precio', 'activo'];
}
