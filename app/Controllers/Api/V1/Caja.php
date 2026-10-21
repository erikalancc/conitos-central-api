<?php

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Models\CorteModel;

/**
 * Caja: apertura del turno con fondo, entradas/retiros de efectivo y corte Z.
 * Los totales y diferencias se calculan aquí con las ventas registradas del turno.
 */
class Caja extends BaseApiController
{
    private const MAX_DIAS_CONSULTA = 366;

    // GET /api/v1/caja/turno — turno abierto con sus totales en vivo y sus ventas (null si la caja está cerrada)
    public function turno()
    {
        $modelo  = model(CorteModel::class);
        $abierto = $modelo->abierto();

        return $this->ok(['turno' => $abierto === null ? null : $modelo->detalle((int) $abierto['id_corte'], true)]);
    }

    // POST /api/v1/caja/apertura — { fondo_inicial, comentario? }
    public function abrir()
    {
        $datos  = $this->cuerpo();
        $reglas = [
            'fondo_inicial' => ['label' => 'fondo inicial', 'rules' => 'required|decimal|greater_than_equal_to[0]|less_than[1000000]'],
            'comentario'    => ['label' => 'comentario', 'rules' => 'permit_empty|max_length[255]'],
        ];
        if (! $this->validateData($datos, $reglas)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $modelo = model(CorteModel::class);
        if ($modelo->abierto() !== null) {
            return $this->fail(['error' => 'La caja ya está abierta. Haz el corte antes de abrir un nuevo turno.'], 409);
        }

        $id = $modelo->abrir(
            service('sesion')->idUsuario(),
            round((float) $datos['fondo_inicial'], 2),
            trim((string) ($datos['comentario'] ?? '')) ?: null
        );

        return $this->ok(['turno' => $modelo->detalle($id, true)], 201);
    }

    // POST /api/v1/caja/movimientos — { tipo: entrada|retiro, monto, concepto }
    public function movimiento()
    {
        $datos  = $this->cuerpo();
        $reglas = [
            'tipo'     => ['label' => 'tipo', 'rules' => 'required|in_list[entrada,retiro]'],
            'monto'    => ['label' => 'monto', 'rules' => 'required|decimal|greater_than[0]|less_than[1000000]'],
            'concepto' => ['label' => 'concepto', 'rules' => 'required|max_length[150]'],
        ];
        if (! $this->validateData($datos, $reglas)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $modelo  = model(CorteModel::class);
        $abierto = $modelo->abierto();
        if ($abierto === null) {
            return $this->fail(['error' => 'No hay caja abierta. Abre caja antes de registrar movimientos.'], 409);
        }

        $idCorte = (int) $abierto['id_corte'];
        $monto   = round((float) $datos['monto'], 2);

        if ($datos['tipo'] === 'retiro') {
            $disponible = $modelo->detalle($idCorte)['efectivo']['esperado'];
            if ($monto > $disponible) {
                return $this->failValidationErrors(['monto' => 'El retiro es mayor al efectivo que debería haber en gaveta.']);
            }
        }

        $modelo->registrarMovimiento($idCorte, service('sesion')->idUsuario(), $datos['tipo'], $monto, trim($datos['concepto']));

        return $this->ok(['turno' => $modelo->detalle($idCorte, true)], 201);
    }

    /**
     * POST /api/v1/caja/cierre — corte Z del turno abierto.
     * { conteo: { "500": 2, "20": 1 }, tarjeta_terminal, vouchers, observaciones }
     */
    public function cerrar()
    {
        $datos   = $this->cuerpo();
        $modelo  = model(CorteModel::class);
        $abierto = $modelo->abierto();
        if ($abierto === null) {
            return $this->fail(['error' => 'No hay caja abierta.'], 409);
        }

        $idCorte = (int) $abierto['id_corte'];
        $turno   = $modelo->detalle($idCorte);

        // Conteo de gaveta: solo denominaciones válidas y piezas enteras
        $conteo   = [];
        $permitidas = array_map(static fn ($d) => number_format($d, 2, '.', ''), CorteModel::DENOMINACIONES);
        foreach ((array) ($datos['conteo'] ?? []) as $denominacion => $piezas) {
            $clave = number_format((float) $denominacion, 2, '.', '');
            if (! in_array($clave, $permitidas, true)) {
                return $this->failValidationErrors(['conteo' => "La denominación {$denominacion} no es válida."]);
            }
            if (! is_int($piezas) || $piezas < 0 || $piezas > 99999) {
                return $this->failValidationErrors(['conteo' => "Las piezas de \${$denominacion} no son válidas."]);
            }
            $conteo[$clave] = $piezas;
        }
        $contado  = round(array_sum(array_map(static fn ($d, $p) => (float) $d * $p, array_keys($conteo), $conteo)), 2);
        $esperado = $turno['efectivo']['esperado'];

        $sistemaTarjeta = $turno['tarjeta']['sistema'];
        $terminal       = $datos['tarjeta_terminal'] ?? null;
        if ($terminal === null && $sistemaTarjeta > 0) {
            return $this->failValidationErrors(['tarjeta_terminal' => 'Captura el total del cierre de lote de la terminal bancaria.']);
        }
        if ($terminal !== null && (! is_numeric($terminal) || $terminal < 0)) {
            return $this->failValidationErrors(['tarjeta_terminal' => 'El total de la terminal no es válido.']);
        }
        $terminal = round((float) ($terminal ?? 0), 2);
        $vouchers = $datos['vouchers'] ?? null;
        if ($vouchers !== null && (! is_int($vouchers) || $vouchers < 0)) {
            return $this->failValidationErrors(['vouchers' => 'El número de vouchers no es válido.']);
        }

        $difEfectivo   = round($contado - $esperado, 2);
        $difTarjeta    = round($terminal - $sistemaTarjeta, 2);
        $observaciones = trim((string) ($datos['observaciones'] ?? ''));
        if ((abs($difEfectivo) >= 0.01 || abs($difTarjeta) >= 0.01) && $observaciones === '') {
            return $this->failValidationErrors(['observaciones' => 'Hay diferencias: explica el motivo en observaciones.']);
        }

        $ventas = $turno['ventas'];
        $modelo->cerrar($idCorte, service('sesion')->idUsuario(), [
            'tickets'             => $ventas['tickets'],
            'ventas_brutas'       => $ventas['ventas_brutas'],
            'descuentos'          => $ventas['descuentos'],
            'ventas_netas'        => $ventas['ventas_netas'],
            'ventas_efectivo'     => $ventas['ventas_efectivo'],
            'ventas_tarjeta'      => $ventas['ventas_tarjeta'],
            'entradas'            => $turno['efectivo']['entradas'],
            'retiros'             => $turno['efectivo']['retiros'],
            'efectivo_esperado'   => $esperado,
            'efectivo_contado'    => $contado,
            'diferencia_efectivo' => $difEfectivo,
            'tarjeta_terminal'    => $terminal,
            'vouchers'            => $vouchers,
            'diferencia_tarjeta'  => $difTarjeta,
            'observaciones'       => $observaciones ?: null,
        ], $conteo);

        return $this->ok(['corte' => $modelo->detalle($idCorte, true)], 201);
    }

    // GET /api/v1/caja/cortes?desde=AAAA-MM-DD&hasta=AAAA-MM-DD — cortes cerrados (por fecha de apertura)
    public function cortes()
    {
        $hasta = $this->request->getGet('hasta') ?: date('Y-m-d');
        $desde = $this->request->getGet('desde') ?: date('Y-m-d', strtotime($hasta . ' -55 days'));

        foreach (['desde' => $desde, 'hasta' => $hasta] as $campo => $valor) {
            $fecha = is_string($valor) ? \DateTime::createFromFormat('!Y-m-d', $valor) : false;
            if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
                return $this->failValidationErrors([$campo => 'Usa el formato AAAA-MM-DD.']);
            }
        }
        if ($desde > $hasta || (strtotime($hasta) - strtotime($desde)) / 86400 >= self::MAX_DIAS_CONSULTA) {
            return $this->failValidationErrors(['desde' => 'Rango inválido (máximo ' . self::MAX_DIAS_CONSULTA . ' días).']);
        }

        $modelo = model(CorteModel::class);

        return $this->ok([
            'desde'  => $desde,
            'hasta'  => $hasta,
            'cortes' => array_map(static fn ($id) => $modelo->detalle((int) $id), $modelo->cerradosEntre($desde, $hasta)),
        ]);
    }

    // GET /api/v1/caja/cortes/{id} — un corte con todas sus ventas (para reimprimir el ticket)
    public function corte(int $id)
    {
        $corte = model(CorteModel::class)->detalle($id, true);

        return $corte === null ? $this->failNotFound('El corte no existe.') : $this->ok($corte);
    }
}
