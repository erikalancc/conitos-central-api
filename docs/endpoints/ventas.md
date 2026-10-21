# Ventas

## POST /ventas

Registra una venta cobrada en el POS con su detalle (presentaciones o combos, sabores y extras). Si hay un turno de caja abierto, la venta queda ligada a él (`ventas.id_corte`); si no, se asigna al turno que se abra ese mismo día (ver [caja.md](caja.md)).

**Auth:** token con rol **Administrador** (1) o **Vendedor / Cajero** (2).

**Tablas:** `ventas`, `detalle_ventas`, `detalle_venta_sabores` (un renglón por porción de sabor), `detalle_venta_extras`.

### Precios

El API **recalcula** cada línea con el catálogo vigente; los precios del POS no se guardan:

- presentación: `precio_base` + recargo de cada sabor en esa presentación + extras;
- combo: `precio_combo` de la promoción.

El POS manda el `total` que calculó. Si no coincide con el del API (alguien cambió precios en Administración después de que el POS cargó el catálogo), responde `409` con el total vigente; el POS recarga el catálogo, vuelve a tasar la orden y el cajero cobra de nuevo.

### Petición

```json
{
  "folio_orden": 48,
  "cliente": "Ana",
  "servicio": "aqui",
  "id_metodo_pago": 1,
  "monto_recibido": 300,
  "descuento": null,
  "total": 240,
  "lineas": [
    { "id_presentacion": 2, "cantidad": 1, "sabores": [1, 3], "extras": [4], "notas": "sin cono" },
    { "id_presentacion": 14, "cantidad": 2 },
    { "id_promocion": 1, "cantidad": 1, "notas": "Papas locas + agua de horchata" }
  ]
}
```

| Campo | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `id_metodo_pago` | int | sí | `metodos_pago.id_metodo` (1 Efectivo, 2 Débito, 3 Crédito) |
| `total` | decimal | sí | Total que calculó el POS (para detectar precios desactualizados) |
| `lineas` | array | sí | Al menos una |
| `monto_recibido` | decimal | solo efectivo | Debe cubrir el total; si no viene, se toma el total exacto |
| `folio_orden` | int | no | Número de orden que ve el cliente |
| `cliente` | string | no | Máx. 100 |
| `servicio` | string | no | `aqui` o `llevar` (default) |
| `descuento` | object \| null | no | `{ "nombre", "tipo": "porcentaje" \| "monto", "valor" }`. Los descuentos aún no están en la BD; el API acepta el del POS (porcentaje ≤ 100, monto ≤ subtotal) |

**lineas[]** — `id_presentacion` **o** `id_promocion`:

| Campo | Tipo | Descripción |
|---|---|---|
| `cantidad` | int | 1 a 99 |
| `sabores` | int[] | Un id por porción (`[1, 1]` = 2× Vainilla). Deben ser tantos como `num_sabores` de la presentación, activos y del `tipo_sabor` del producto. Vacío si el producto no lleva sabor |
| `extras` | int[] | Extras activos que el producto ofrece (`productos_extras`) |
| `notas` | string | Máx. 255. En combos, qué lleva |

Una promoción solo se acepta si está activa y aplica hoy (`dia_semana`).

### Respuesta `201`

```json
{
  "data": {
    "id": 1,
    "folio_orden": 48,
    "fecha": "2026-10-20T15:49:25-06:00",
    "metodo_pago": "Efectivo",
    "subtotal": 240,
    "descuento": 0,
    "total": 240,
    "monto_recibido": 300,
    "cambio": 60
  }
}
```

`monto_recibido` y `cambio` son `null` si no se pagó en efectivo.

### Errores

| Status | Cuándo | `messages` |
|---|---|---|
| 400 | Datos inválidos, sabores incompletos, producto o extra no disponible, efectivo insuficiente | Por campo; las líneas como `lineas.0` |
| 401 | Sin token o token vencido | `error` |
| 403 | Rol sin permiso para cobrar | `error` |
| 409 | El total no coincide con los precios vigentes | `error` y `total` (el total vigente) |

---

## GET /ventas/resumen

Totales por día para el Historial, del día más reciente al más antiguo. Solo incluye días con ventas.

**Auth:** Administrador (1) o Vendedor / Cajero (2).

| Parámetro | Default | Descripción |
|---|---|---|
| `hasta` | hoy | `AAAA-MM-DD` |
| `desde` | `hasta` − 55 días (8 semanas) | `AAAA-MM-DD`. Rango máximo: 366 días |

La respuesta incluye `hay_anteriores` (bool): si existen ventas antes de `desde`.

### Respuesta `200`

```json
{
  "data": {
    "desde": "2026-08-26",
    "hasta": "2026-10-20",
    "dias": [
      {
        "fecha": "2026-10-20",
        "tickets": 5,
        "articulos": 10,
        "subtotal": 465,
        "descuento": 5,
        "total": 460,
        "por_metodo": [
          { "id_metodo": 1, "nombre": "Efectivo", "tickets": 3, "total": 365 },
          { "id_metodo": 2, "nombre": "Tarjeta de Débito", "tickets": 1, "total": 50 },
          { "id_metodo": 3, "nombre": "Tarjeta de Crédito", "tickets": 1, "total": 45 }
        ],
        "vendedores": ["Erik Cárdenas", "María López"]
      }
    ]
  }
}
```

---

## GET /ventas

Ventas de un día con todo su detalle, en orden de hora.

**Auth:** Administrador (1) o Vendedor / Cajero (2).

| Parámetro | Default | Descripción |
|---|---|---|
| `fecha` | hoy | `AAAA-MM-DD` |

### Respuesta `200`

```json
{
  "data": {
    "fecha": "2026-10-20",
    "ventas": [
      {
        "id": 3,
        "folio_orden": 48,
        "fecha": "2026-10-20T16:05:52-06:00",
        "vendedor": "María López",
        "cliente": "Ana",
        "servicio": "aqui",
        "metodo_pago": { "id": 1, "nombre": "Efectivo" },
        "subtotal": 240,
        "descuento": 0,
        "descuento_concepto": null,
        "total": 240,
        "monto_recibido": 300,
        "cambio": 60,
        "lineas": [
          {
            "id": 4,
            "id_producto": 1,
            "id_promocion": null,
            "nombre": "Helado Tradicional",
            "presentacion": "Grande (2 bolas)",
            "cantidad": 1,
            "precio_unitario": 75,
            "subtotal": 75,
            "notas": "sin cono",
            "sabores": [{ "id": 1, "nombre": "Vainilla", "cantidad": 1 }, { "id": 3, "nombre": "Piñón", "cantidad": 1 }],
            "extras": [{ "id": 4, "nombre": "Bola extra Piñón", "precio": 15, "cantidad": 1 }]
          }
        ]
      }
    ]
  }
}
```

| Campo | Descripción |
|---|---|
| `lineas[].nombre` | Producto o promoción. Si la presentación se borró del catálogo: "Producto eliminado del catálogo" |
| `lineas[].presentacion` | `null` si el producto solo tiene una presentación ("Única") |
| `lineas[].precio_unitario` | Precio cobrado por pieza: incluye recargos de sabor y extras |

### Errores (ambos)

| Status | Cuándo |
|---|---|
| 400 | Fecha con formato inválido, `desde` posterior a `hasta` o rango mayor a 366 días |
| 401 / 403 | Sin token, token vencido o rol sin acceso |
