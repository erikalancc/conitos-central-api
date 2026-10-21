<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Firma de tokens de acceso. Los valores se sobrescriben desde .env
 * (jwt.secret, jwt.ttl).
 */
class Jwt extends BaseConfig
{
    public string $secret = '';

    public string $algoritmo = 'HS256';

    // Segundos de vigencia del token
    public int $ttl = 43200;

    public string $emisor = 'conitos-central-api';
}
