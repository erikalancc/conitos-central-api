# Caja (turnos y corte Z)

Un **turno** se abre con el fondo inicial de la gaveta y se cierra con el **corte Z**. Mientras está abierto, cada venta (`POST /ventas`) y cada entrada/retiro de efectivo quedan ligados a él.

- Solo hay **un turno abierto a la vez** (una caja).
- Al abrir, las ventas de **hoy** que se cobraron sin caja abierta se asignan al nuevo turno.
- El API calcula el efectivo esperado, lo cobrado con tarjeta y las diferencias; el front solo manda lo que se contó.

**Auth (todas):** Administrador (1) o Vendedor / Cajero (2).

**Tablas:** `cortes_caja` (un registro por turno), `cortes_caja_conteo` (piezas por denominación), `movimientos_caja`, `ventas.id_corte`.

### Formato de un turno / corte

```json
{
  "id": 1,
  "estado": "cerrado",
  "apertura": { "fecha": "2026-10-20T09:00:00-06:00", "usuario": "María López", "fondo": 1000, "comentario": null },
  "cierre": { "fecha": "2026-10-20T17:05:00-06:00", "usuario": "María López" },
  "ventas": {
    "tickets": 3, "ventas_brutas": 330, "descuentos": 0, "ventas_netas": 330,
    "ventas_efectivo": 125, "ventas_tarjeta": 205,
    "por_metodo": [{ "id_metodo": 1, "nombre": "Efectivo", "tickets": 2, "total": 125 }]
  },
  "efectivo": {
    "fondo": 1000, "ventas": 125, "entradas": 0, "retiros": 30,
    "esperado": 1095, "contado": 1095, "diferencia": 0,
    "conteo": [{ "denominacion": 1000, "piezas": 1 }, { "denominacion": 50, "piezas": 1 }]
  },
  "tarjeta": { "sistema": 205, "terminal": 205, "vouchers": 1, "diferencia": 0 },
  "observaciones": null,
  "movimientos": [
    { "id": 1, "tipo": "retiro", "monto": 30, "concepto": "Compra de hielo", "usuario": "María López", "fecha": "2026-10-20T12:00:00-06:00" }
  ],
  "ventas_detalle": []
}
```

- En un turno **abierto**: `cierre`, `efectivo.contado`, `efectivo.diferencia` y los campos de `tarjeta` salvo `sistema` son `null`; `efectivo.esperado` se calcula en vivo (`fondo + ventas en efectivo + entradas − retiros`).
- `ventas_detalle` (mismo formato que `GET /ventas`) solo viene en `GET /caja/turno`, `POST` de caja y `GET /caja/cortes/{id}`.
- Tarjeta = todo lo que no es Efectivo (Débito + Crédito).

---

## GET /caja/turno

Turno abierto con sus totales en vivo y sus ventas.

```json
{ "data": { "turno": { "...": "formato de arriba" } } }
```

`turno: null` si la caja está cerrada.

## POST /caja/apertura

```json
{ "fondo_inicial": 1000, "comentario": "Fondo verificado" }
```

Responde `201` con `{ "turno": … }`. `409` si ya hay un turno abierto.

## POST /caja/movimientos

```json
{ "tipo": "retiro", "monto": 80, "concepto": "Compra de hielo" }
```

`tipo`: `entrada` o `retiro`. Responde `201` con el turno actualizado. `409` si no hay caja abierta; `400` si un retiro es mayor al efectivo esperado en gaveta.

## POST /caja/cierre

Corte Z del turno abierto.

```json
{
  "conteo": { "1000": 1, "50": 1, "20": 2, "5": 1 },
  "tarjeta_terminal": 205,
  "vouchers": 1,
  "observaciones": ""
}
```

| Campo | Reglas |
|---|---|
| `conteo` | Denominación → piezas (enteros ≥ 0). Denominaciones válidas: 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1, 0.5 |
| `tarjeta_terminal` | Total del cierre de lote. Obligatorio si hubo ventas con tarjeta |
| `vouchers` | Opcional |
| `observaciones` | Obligatorias si el efectivo o la tarjeta no cuadran |

Responde `201` con `{ "corte": … }` (incluye `ventas_detalle`, para imprimir el ticket). `409` si no hay caja abierta.

## GET /caja/cortes

Cortes **cerrados** cuyo turno se abrió entre `desde` y `hasta` (`AAAA-MM-DD`, default: últimas 8 semanas, máximo 366 días). Sin `ventas_detalle`.

```json
{ "data": { "desde": "2026-08-26", "hasta": "2026-10-20", "cortes": [ { "...": "formato de arriba" } ] } }
```

## GET /caja/cortes/{id}

Un corte con `ventas_detalle` (para reimprimir el ticket). `404` si no existe.

### Errores

| Status | Cuándo |
|---|---|
| 400 | Datos inválidos, retiro mayor al efectivo, denominación inválida, falta el cierre de lote, diferencias sin observaciones |
| 401 / 403 | Sin token, token vencido o rol sin acceso |
| 404 | El corte no existe |
| 409 | Abrir con un turno ya abierto; movimientos o cierre sin caja abierta |
