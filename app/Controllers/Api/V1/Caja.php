<?php

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Models\ChecklistRegistroModel;
use App\Models\CorteModel;

/**
 * Caja: apertura del turno con fondo, entradas/retiros de efectivo y corte Z.
 * Cada sucursal tiene su propia caja: el usuario siempre trabaja con el turno de su sucursal
 * y el corte solo incluye las ventas cobradas en ella.
 * Los totales y diferencias se calculan aquí con las ventas registradas del turno.
 */
class Caja extends BaseApiController
{
    private const MAX_DIAS_CONSULTA = 366;

    // Sucursal de trabajo de quien hace la petición (la suya o la matriz)
    private function idSucursal(): ?int
    {
        return service('sesion')->idSucursal();
    }

    /**
     * GET /api/v1/caja/turno — turno abierto de la sucursal con sus totales en vivo y sus ventas.
     * Con la caja cerrada: { turno: null, fondo_sugerido } (lo que se dejó de fondo en el último corte de la sucursal).
     */
    public function turno()
    {
        $modelo  = model(CorteModel::class);
        $abierto = $modelo->abierto($this->idSucursal());

        return $this->ok($abierto === null
            ? ['turno' => null, 'fondo_sugerido' => $modelo->fondoSugerido($this->idSucursal())]
            : ['turno' => $this->turnoVisible((int) $abierto['id_corte'])]);
    }

    /**
     * Detalle del turno para quien consulta. Los totales siempre son del turno completo;
     * al vendedor solo se le envían sus últimas ventas permitidas (ventas_visibles = límite, null = todas).
     */
    private function turnoVisible(int $idCorte): array
    {
        $turno                   = model(CorteModel::class)->detalle($idCorte, true);
        $turno['ventas_detalle'] = $this->ventasVisibles($turno['ventas_detalle']);
        $turno['ventas_visibles'] = $this->limiteVentas();

        return $turno;
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
        if ($this->idSucursal() === null) {
            return $this->fail(['error' => 'Tu usuario no tiene una sucursal activa asignada. Pide a un administrador que te asigne una.'], 409);
        }
        if ($modelo->abierto($this->idSucursal()) !== null) {
            return $this->fail(['error' => 'La caja de esta sucursal ya está abierta. Haz el corte antes de abrir un nuevo turno.'], 409);
        }

        $id = $modelo->abrir(
            $this->idSucursal(),
            service('sesion')->idUsuario(),
            round((float) $datos['fondo_inicial'], 2),
            trim((string) ($datos['comentario'] ?? '')) ?: null
        );

        return $this->ok(['turno' => $this->turnoVisible($id)], 201);
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
        $abierto = $modelo->abierto($this->idSucursal());
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

        return $this->ok(['turno' => $this->turnoVisible($idCorte)], 201);
    }

    /**
     * POST /api/v1/caja/cierre — corte Z del turno abierto.
     * { conteo: { "500": 2, "20": 1 }, fondo_siguiente, tarjeta_terminal, vouchers, observaciones }
     * fondo_siguiente: efectivo que se queda en gaveta para el siguiente turno (0 a lo contado).
     */
    public function cerrar()
    {
        $datos   = $this->cuerpo();
        $modelo  = model(CorteModel::class);
        $abierto = $modelo->abierto($this->idSucursal());
        if ($abierto === null) {
            return $this->fail(['error' => 'No hay caja abierta.'], 409);
        }
        // El vendedor debe completar antes el checklist de cierre de su sucursal (si tiene puntos configurados)
        if ($this->esVendedor() && model(ChecklistRegistroModel::class)->faltaCompletar('CIERRE', service('sesion')->idSucursal())) {
            return $this->fail(['error' => 'Completa el checklist de antes del corte para poder cerrar el turno.'], 409);
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

        // Fondo para el siguiente turno: sale del efectivo contado
        $fondoSiguiente = $datos['fondo_siguiente'] ?? null;
        if (! is_numeric($fondoSiguiente) || $fondoSiguiente < 0) {
            return $this->failValidationErrors(['fondo_siguiente' => 'Indica cuánto efectivo se deja de fondo para el siguiente turno.']);
        }
        $fondoSiguiente = round((float) $fondoSiguiente, 2);
        if ($fondoSiguiente > $contado) {
            return $this->failValidationErrors(['fondo_siguiente' => 'El fondo no puede ser mayor al efectivo contado.']);
        }

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
            'fondo_siguiente'     => $fondoSiguiente,
            'diferencia_efectivo' => $difEfectivo,
            'tarjeta_terminal'    => $terminal,
            'vouchers'            => $vouchers,
            'diferencia_tarjeta'  => $difTarjeta,
            'observaciones'       => $observaciones ?: null,
        ], $conteo);

        $corte                   = $modelo->detalle($idCorte, true);
        $corte['ventas_detalle'] = $this->ventasVisibles($corte['ventas_detalle']);

        return $this->ok(['corte' => $corte], 201);
    }

    // GET /api/v1/caja/cortes?desde=AAAA-MM-DD&hasta=AAAA-MM-DD — cortes cerrados (por fecha de apertura)
    public function cortes()
    {
        if ($this->esVendedor()) {
            return $this->failForbidden('Los cortes anteriores solo los consulta un administrador.');
        }

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
            // Una sucursal a la vez: la asignada, o la que elija el administrador
            'cortes' => array_map(static fn ($id) => $modelo->detalle((int) $id), $modelo->cerradosEntre($desde, $hasta, $this->sucursalConsultada())),
        ]);
    }

    // GET /api/v1/caja/cortes/{id} — un corte con todas sus ventas (para reimprimir el ticket)
    public function corte(int $id)
    {
        if ($this->esVendedor()) {
            return $this->failForbidden('Los cortes anteriores solo los consulta un administrador.');
        }

        $corte = model(CorteModel::class)->detalle($id, true);

        // Solo el administrador llega aquí (el vendedor no consulta cortes) y puede ver los de cualquier sucursal
        return $corte === null ? $this->failNotFound('El corte no existe.') : $this->ok($corte);
    }
}
