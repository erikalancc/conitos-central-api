<?php

namespace App\Models;

use CodeIgniter\Model;

class MetodoPagoModel extends Model
{
    protected $table         = 'metodos_pago';
    protected $primaryKey    = 'id_metodo';
    protected $returnType    = 'array';
    protected $allowedFields = ['nombre'];
}
