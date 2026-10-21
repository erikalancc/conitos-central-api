<?php

namespace App\Models;

use CodeIgniter\Model;

class RecargoSaborModel extends Model
{
    protected $table         = 'recargos_sabores';
    protected $primaryKey    = 'id_recargo';
    protected $returnType    = 'array';
    protected $allowedFields = ['id_sabor', 'id_presentacion', 'monto_recargo'];
}
