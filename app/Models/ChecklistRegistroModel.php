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
    protected $allowedFields = ['fecha', 'id_sucursal', 'momento', 'id_usuario', 'estado', 'con_incidencia', 'comentarios', 'id_usuario_autoriza', 'fecha_autorizacion'];

    // Registro del día de la sucursal en un momento (APERTURA, MEDIO_TURNO, CIERRE) con quien lo hizo, o null
    public function delDia(string $fecha, ?int $idSucursal, string $momento = 'APERTURA'): ?array
    {
        $registro = $this->select('checklist_registros.*, usuarios.nombre AS usuario_nombre')
            ->join('usuarios', 'usuarios.id_usuario = checklist_registros.id_usuario')
            ->where('fecha', $fecha)
            ->where('checklist_registros.id_sucursal', $idSucursal)
            ->where('momento', $momento)
            ->first();

        return $registro === null ? null : self::publico($registro);
    }

    // ¿Hoy falta completar el checklist de ese momento en la sucursal? (solo si tiene puntos configurados)
    public function faltaCompletar(string $momento, ?int $idSucursal): bool
    {
        if (model(ChecklistItemModel::class)->activos($momento, $idSucursal) === []) {
            return false;
        }

        return ($this->delDia(date('Y-m-d'), $idSucursal, $momento)['estado'] ?? null) !== 'COMPLETADO';
    }

    /**
     * Guarda el checklist completado del día con sus respuestas y temperaturas.
     * Si ese día ya había una omisión, la reemplaza.
     *
     * @param list<array{id_item:int, cumplido:bool, valor:?string, temperaturas:list<array{sensor:string, id_equipo:?int, grados:float, temp_min:?float, temp_max:?float, en_rango:bool}>}> $respuestas
     */
    public function guardarCompletado(string $fecha, ?int $idSucursal, string $momento, int $idUsuario, array $respuestas, bool $conIncidencia, ?string $comentarios): void
    {
        $this->db->transException(true)->transStart();

        $omitido = $this->where('fecha', $fecha)->where('id_sucursal', $idSucursal)->where('momento', $momento)->where('estado', 'OMITIDO')->first();
        if ($omitido !== null) {
            $this->delete($omitido['id_registro']);
        }

        $idRegistro = $this->insert([
            'fecha'          => $fecha,
            'id_sucursal'    => $idSucursal,
            'momento'        => $momento,
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
                    'id_equipo'    => $lectura['id_equipo'] ?? null,
                    'sensor'       => mb_substr($lectura['sensor'], 0, 30),
                    'grados'       => $lectura['grados'],
                    'temp_min'     => $lectura['temp_min'] ?? null,
                    'temp_max'     => $lectura['temp_max'] ?? null,
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
            'momento'        => array_search($registro['momento'] ?? 'APERTURA', ChecklistItemModel::MOMENTOS, true),
            'estado'         => $registro['estado'],
            'con_incidencia' => (bool) $registro['con_incidencia'],
            'comentarios'    => $registro['comentarios'],
            'usuario'        => ['id' => (int) $registro['id_usuario'], 'nombre' => $registro['usuario_nombre'] ?? null],
            'autorizado'     => $registro['id_usuario_autoriza'] !== null,
            'registrado_en'  => date(DATE_ATOM, strtotime($registro['created_at'])),
        ];
    }
}
