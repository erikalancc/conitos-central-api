<?php

namespace App\Commands;

use App\Models\UsuarioModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Asigna una contraseña nueva a un usuario desde la terminal.
 *
 *   php spark usuarios:password <usuario>
 *
 * Pide la contraseña dos veces. Mismas reglas que el catálogo de usuarios del API:
 * de 8 a 72 caracteres, guardada con bcrypt (costo 12).
 */
class UsuariosPassword extends BaseCommand
{
    protected $group       = 'Conitos';
    protected $name        = 'usuarios:password';
    protected $description = 'Asigna una contraseña nueva a un usuario.';
    protected $usage       = 'usuarios:password <usuario>';
    protected $arguments   = ['usuario' => 'Nombre de usuario (p. ej. vendedor1)'];

    private const COSTO_HASH = 12;

    public function run(array $params)
    {
        $nombreUsuario = $params[0] ?? CLI::prompt('Usuario');
        $modelo        = model(UsuarioModel::class);
        $usuario       = $modelo->where('usuario', mb_strtolower(trim((string) $nombreUsuario)))->first();

        if ($usuario === null) {
            CLI::error("No existe el usuario \"{$nombreUsuario}\".");

            return EXIT_ERROR;
        }

        CLI::write("Usuario: {$usuario['nombre']} ({$usuario['usuario']})" . ($usuario['activo'] ? '' : ' — INACTIVO'));

        $password = (string) CLI::prompt('Contraseña nueva (mín. 8 caracteres)');
        if (mb_strlen($password) < 8 || mb_strlen($password) > 72) {
            CLI::error('La contraseña debe tener de 8 a 72 caracteres.');

            return EXIT_ERROR;
        }
        if ((string) CLI::prompt('Repite la contraseña') !== $password) {
            CLI::error('Las contraseñas no coinciden.');

            return EXIT_ERROR;
        }

        $modelo->update($usuario['id_usuario'], [
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => self::COSTO_HASH]),
        ]);

        CLI::write('Contraseña actualizada.', 'green');

        return EXIT_SUCCESS;
    }
}
