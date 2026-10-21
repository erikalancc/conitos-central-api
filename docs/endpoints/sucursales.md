# Sucursales

Alta, edición, activación y borrado de sucursales y sus terminales POS (cajas, tablets, kioskos). Lo usa **Administración › Sucursales**.

**Auth:** token con rol **Administrador** (`id_rol` 1).

**Tablas:** `sucursales`, `terminales`.

- La sucursal **matriz** (`es_matriz`) no se puede desactivar ni eliminar.
- Las escrituras responden con el listado completo (mismo formato que `GET`), más el `id` afectado.

> Por ahora las ventas y los turnos de caja no se ligan a una sucursal o terminal: la operación sigue siendo de una sola caja.

---

## GET /admin/sucursales

```json
{
  "data": {
    "sucursales": [
      {
        "id": 1,
        "codigo": "SUC-001",
        "nombre": "Sucursal Centro Histórico",
        "direccion": "Av. 5 de Mayo #42, Col. Centro",
        "telefono": "55 5512 8890",
        "correo": null,
        "encargado": { "id": 2, "nombre": "María López" },
        "hora_apertura": "10:00",
        "hora_cierre": "21:00",
        "modalidades": { "mostrador": true, "para_llevar": true, "apps_delivery": false, "drive_thru": false },
        "es_matriz": true,
        "activo": true,
        "terminales": [{ "id": 1, "nombre": "Caja #01", "tipo": "caja", "activo": true }]
      }
    ],
    "siguiente_codigo": "SUC-002",
    "usuarios": [{ "id": 2, "nombre": "María López", "rol": "Vendedor / Cajero" }],
    "resumen": {
      "sucursales_activas": 1,
      "terminales_activas": 1,
      "ventas_hoy": 3,
      "total_hoy": 330,
      "ticket_promedio_hoy": 110
    }
  }
}
```

| Campo | Descripción |
|---|---|
| `siguiente_codigo` | Siguiente código libre `SUC-###`, para sugerirlo en el alta |
| `usuarios` | Usuarios activos que se pueden asignar como encargado |
| `resumen.ventas_hoy` | Ventas de hoy de todo el sistema (aún no se separan por sucursal) |

## POST /admin/sucursales

```json
{
  "codigo": "SUC-002",
  "nombre": "Sucursal Del Valle Sur",
  "direccion": "Av. División del Norte #1420, Col. Del Valle Sur, Benito Juárez",
  "telefono": "55 5543 9012",
  "correo": "delvalle@conitos.mx",
  "id_encargado": 2,
  "hora_apertura": "10:00",
  "hora_cierre": "22:00",
  "modalidades": { "mostrador": true, "para_llevar": true, "apps_delivery": true, "drive_thru": false },
  "terminales": [{ "nombre": "Caja #01", "tipo": "caja" }, { "nombre": "Caja #02", "tipo": "caja" }]
}
```

| Campo | Obligatorio al crear | Reglas |
|---|---|---|
| `codigo` | sí | 3 a 20 caracteres: mayúsculas, números y guiones. Único |
| `nombre` | sí | Máx. 100. Único |
| `direccion` | sí | Máx. 255 |
| `telefono` | no | 7 a 20 caracteres: números, espacios, `+ ( ) -` |
| `correo` | no | Email válido |
| `id_encargado` | no | Usuario existente; `null` = sin asignar |
| `hora_apertura` / `hora_cierre` | no | `HH:MM`; el cierre debe ser posterior a la apertura |
| `modalidades` | no | `mostrador`, `para_llevar`, `apps_delivery`, `drive_thru` (bool) |
| `activo` | no | Default `true` |
| `terminales` | sí | 1 a 20. Cada una: `{ "id"?, "nombre", "tipo": "caja" \| "tablet" \| "kiosko", "activo"? }`, nombres sin repetir |

## PUT /admin/sucursales/{id}

Cambios parciales (solo se validan los campos que llegan), p. ej. `{ "activo": false }`. Si llega `terminales`, **reemplaza** la lista: con `id` se actualizan, sin `id` se crean y las que no vienen se borran.

## DELETE /admin/sucursales/{id}

Borra la sucursal y sus terminales. `409` si es la matriz.

### Errores

| Status | Cuándo |
|---|---|
| 400 | Datos inválidos, código o nombre repetidos, horario invertido, terminales inválidas o de otra sucursal |
| 401 / 403 | Sin token, token vencido o rol que no es Administrador |
| 404 | La sucursal no existe |
| 409 | Desactivar o eliminar la matriz |
