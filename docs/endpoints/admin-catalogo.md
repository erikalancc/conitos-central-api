# Administración del catálogo

Alta, edición, activación y borrado de categorías, productos (con sus presentaciones), sabores (con sus recargos), extras y promociones. Lo usa la pestaña **Administración** del front.

**Auth:** token con rol **Administrador** (`id_rol` 1). Otros roles reciben `403`.

**Tablas:** `categorias`, `productos`, `presentaciones`, `sabores`, `recargos_sabores`, `extras_toppings`, `promociones`. Lee también `inventario_sabores` y `unidades_empaque` (solo lectura).

### Activo vs. borrar

- `activo` (`activa` en promociones) en `false` oculta el registro del POS (`GET /catalogo`) sin borrarlo. Así el historial de ventas queda intacto.
- Borrar solo se permite si el registro **nunca se ha vendido**. Borrar un sabor o una presentación ya vendidos arrastraría el detalle de esas ventas (`ON DELETE CASCADE` / `SET NULL`), así que el API responde `409` y hay que desactivarlo.

### Respuesta de las escrituras

`POST`, `PUT` y `DELETE` responden con el id afectado y el **catálogo completo** (mismo formato que `GET /admin/catalogo`), para que el front reemplace su estado sin recalcular nada:

```json
{ "data": { "id": 17, "catalogo": { "categorias": [], "productos": [], "...": [] } } }
```

---

## GET /admin/catalogo

Todo el catálogo, **incluido lo inactivo**.

### Respuesta `200`

```json
{
  "data": {
    "categorias": [
      { "id": 1, "nombre": "Helados", "activo": true, "productos": 3 }
    ],
    "productos": [
      {
        "id": 1,
        "id_categoria": 1,
        "nombre": "Helado Tradicional",
        "requiere_sabor": true,
        "tipo_sabor": "Helado",
        "activo": true,
        "vendido": false,
        "presentaciones": [
          { "id": 1, "nombre": "Chico (1 bola)", "precio_base": 25, "num_sabores": 1, "activo": true, "vendida": false }
        ],
        "extras": [3, 4]
      }
    ],
    "sabores": [
      {
        "id": 3,
        "nombre": "Piñón",
        "tipo": "Helado",
        "es_especial": true,
        "activo": true,
        "vendido": false,
        "recargos": [{ "id_presentacion": 1, "monto": 15 }],
        "inventario": {
          "botes_reserva": 1,
          "stock_minimo": 1,
          "porciones_actual": 20,
          "porciones_max": 40,
          "empaque": "Bote 5 Litros"
        }
      }
    ],
    "tipos_sabor": ["Helado", "Paleta Agua", "Paleta Crema", "Agua Fresca"],
    "extras": [
      { "id": 1, "nombre": "Ingrediente extra crepa", "precio": 10, "activo": true, "vendido": false }
    ],
    "promociones": [
      { "id": 1, "nombre": "Martes Loco (Botana + Agua 1/2L)", "precio_combo": 65, "dia_semana": 3, "activa": true, "vendida": false }
    ]
  }
}
```

| Campo | Descripción |
|---|---|
| `vendido` / `vendida` | `true` si ya aparece en ventas (o en órdenes de producción, para sabores): no se puede borrar |
| `categorias[].productos` | Productos de la categoría (activos o no): con productos no se puede borrar |
| `sabores[].inventario` | `null` si el sabor no tiene registro en `inventario_sabores` |
| `tipos_sabor` | Tipos existentes, para sugerirlos al capturar un sabor |

---

## POST /admin/{recurso}

Crea un registro. `{recurso}`: `categorias`, `productos`, `sabores`, `extras` o `promociones`.

## PUT /admin/{recurso}/{id}

Actualiza un registro. Acepta **cambios parciales**: solo se validan y guardan los campos que llegan. Por ejemplo, para desactivar:

```json
{ "activo": false }
```

### Campos por recurso

**categorias**

| Campo | Tipo | Obligatorio al crear | Reglas |
|---|---|---|---|
| `nombre` | string | sí | Máx. 50, único |
| `activo` | bool | no (default `true`) | |

**productos**

| Campo | Tipo | Obligatorio al crear | Reglas |
|---|---|---|---|
| `nombre` | string | sí | Máx. 100, único dentro de su categoría |
| `id_categoria` | int | sí | Debe existir |
| `requiere_sabor` | bool | no | El POS pide sabor al agregarlo |
| `tipo_sabor` | string \| null | si `requiere_sabor` | Qué sabores se ofrecen (`sabores.tipo_sabor`) |
| `extras` | int[] | no | Ids de extras que se ofrecen; en `PUT` reemplaza la lista actual |
| `activo` | bool | no | |
| `presentaciones` | array | sí | Al menos una, y al menos una activa |

Cada presentación: `{ "id"?, "nombre", "precio_base", "num_sabores"?, "activo"? }`. `num_sabores` (1 a 10, default 1) es cuántos sabores se eligen en ella. En `PUT`, la lista **reemplaza** a la actual:

- con `id`: se actualiza (debe pertenecer al producto);
- sin `id`: se crea;
- las que no vienen se borran, o se desactivan si ya se vendieron.

**sabores**

| Campo | Tipo | Obligatorio al crear | Reglas |
|---|---|---|---|
| `nombre` | string | sí | Máx. 50, único |
| `tipo_sabor` | string | sí | Máx. 30 (p. ej. `Helado`, `Paleta Agua`) |
| `es_especial` | bool | no | |
| `activo` | bool | no | |
| `recargos` | array | no | `[{ "id_presentacion", "monto" }]`, monto > 0, sin presentaciones repetidas. En `PUT` reemplaza los recargos actuales |

**extras**

| Campo | Tipo | Obligatorio al crear | Reglas |
|---|---|---|---|
| `nombre` | string | sí | Máx. 50, único |
| `precio` | decimal | sí | ≥ 0 |
| `activo` | bool | no | |

**promociones**

| Campo | Tipo | Obligatorio al crear | Reglas |
|---|---|---|---|
| `nombre` | string | sí | Máx. 100, único |
| `precio_combo` | decimal | sí | > 0 |
| `dia_semana` | int \| null | no | 1 = domingo … 7 = sábado; `null` = todos los días |
| `activa` | bool | no | |

### Ejemplo: crear producto

```bash
curl -X POST http://localhost:8080/api/v1/admin/productos \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"nombre":"Helado Tradicional","id_categoria":1,"requiere_sabor":true,
       "presentaciones":[{"nombre":"Chico (1 bola)","precio_base":25},{"nombre":"Grande (2 bolas)","precio_base":40}]}'
```

---

## DELETE /admin/{recurso}/{id}

Borra el registro si nunca se ha vendido. Borrar un producto borra sus presentaciones y recargos; borrar un sabor borra su inventario y recargos.

---

### Errores

| Status | Cuándo | `messages` |
|---|---|---|
| 400 | Datos inválidos | Un mensaje por campo; en listas, `presentaciones.0.precio_base`, `recargos.1.monto`, etc. |
| 401 | Sin token o token vencido | `error` |
| 403 | El rol no es Administrador | `error` |
| 404 | El registro no existe | `error` |
| 409 | No se puede borrar: ya tiene ventas, o la categoría tiene productos | `error` con la alternativa (desactivar o mover) |

```json
{
  "status": 409,
  "error": 409,
  "messages": { "error": "El sabor ya tiene ventas u órdenes de producción; desactívalo para ocultarlo del POS." }
}
```
