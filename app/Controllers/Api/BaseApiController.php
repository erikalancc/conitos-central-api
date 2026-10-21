<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\Exceptions\HTTPException;

/**
 * Base de los endpoints /api/v1. Respuestas en JSON:
 *   éxito  -> { "data": ... }
 *   error  -> { "status", "error", "messages": { campo|error: mensaje } }
 */
abstract class BaseApiController extends BaseController
{
    use ResponseTrait;

    protected $format = 'json';

    protected function ok($data = null, int $status = 200)
    {
        return $this->respond(['data' => $data], $status);
    }

    // Cuerpo JSON de la petición como arreglo ([] si viene vacío o mal formado)
    protected function cuerpo(): array
    {
        try {
            return (array) $this->request->getJSON(true);
        } catch (HTTPException $e) {
            return [];
        }
    }
}
