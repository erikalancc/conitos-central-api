<?php

namespace App\Models;

use CodeIgniter\Model;
use RuntimeException;

class ChecklistRegistroModel extends Model
{
    protected $table         = 'checklist_registros';
    protected $primaryKey    = 'id_registro';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['fecha', 'id_usuario', 'estado', 'con_incidencia', 'comentarios', 'id_usuario_autoriza', 'fecha_autorizacion'];

    // Registro del día con el nombre de quien lo hizo, o null
    public function delDia(string $fecha): ?array
    {
        $registro = $this->select('checklist_registros.*, usuarios.nombre AS usuario_nombre')
            ->join('usuarios', 'usuarios.id_usuario = checklist_registros.id_usuario')
            ->where('fecha', $fecha)
            ->first();

        return $registro === null ? null : self::publico($registro);
    }

    /**
     * Guarda el checklist completado del día con sus respuestas y temperaturas.
     * Si ese día ya había una omisión, la reemplaza.
     *
     * @param list<array{id_item:int, cumplido:bool, valor:?string, temperaturas:list<array{sensor:string, grados:float, en_rango:bool}>}> $respuestas
     */
    public function guardarCompletado(string $fecha, int $idUsuario, array $respuestas, bool $conIncidencia, ?string $comentarios): void
    {
        $this->db->transException(true)->transStart();

        $omitido = $this->where('fecha', $fecha)->where('estado', 'OMITIDO')->first();
        if ($omitido !== null) {
            $this->delete($omitido['id_registro']);
        }

        $idRegistro = $this->insert([
            'fecha'          => $fecha,
            'id_usuario'     => $idUsuario,
            'estado'         => 'COMPLETADO',
            'con_incidencia' => (int) $conIncidencia,
            'comentarios'    => $comentarios,
        ]);

        if ($idRegistro === false) {
            throw new RuntimeException('No se pudo guardar el checklist.');
        }

        foreach ($respuestas as $respuesta) {
            $this->db->table('checklist_respuestas')->insert([
                'id_registro' => $idRegistro,
                'id_item'     => $respuesta['id_item'],
                'cumplido'    => (int) $respuesta['cumplido'],
                'valor'       => $respuesta['valor'],
            ]);
            $idRespuesta = $this->db->insertID();

            foreach ($respuesta['temperaturas'] as $lectura) {
                $this->db->table('checklist_temperaturas')->insert([
                    'id_respuesta' => $idRespuesta,
                    'sensor'       => $lectura['sensor'],
                    'grados'       => $lectura['grados'],
                    'en_rango'     => (int) $lectura['en_rango'],
                ]);
            }
        }

        $this->db->transComplete();
    }

    public static function publico(array $registro): array
    {
        return [
            'id'             => (int) $registro['id_registro'],
            'fecha'          => $registro['fecha'],
            'estado'         => $registro['estado'],
            'con_incidencia' => (bool) $registro['con_incidencia'],
            'comentarios'    => $registro['comentarios'],
            'usuario'        => ['id' => (int) $registro['id_usuario'], 'nombre' => $registro['usuario_nombre'] ?? null],
            'autorizado'     => $registro['id_usuario_autoriza'] !== null,
            'registrado_en'  => date(DATE_ATOM, strtotime($registro['created_at'])),
        ];
    }
}
