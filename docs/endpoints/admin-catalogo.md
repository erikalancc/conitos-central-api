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
        "tipos": ["Helado", "Paleta Crema"],
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
| `tipos_sabor` | Tipos en uso (de sabores y productos), en orden alfabético, para sugerirlos al capturar |

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
| `tipo_sabor` | string \| null | si `requiere_sabor` | Qué sabores se ofrecen (sabores que tengan ese tipo en `tipos`) |
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
| `tipos` | string[] | sí (al crear) | Tipos a los que aplica el sabor: al menos uno, máx. 30 caracteres cada uno (p. ej. `["Helado", "Paleta Crema"]`). Se quitan vacíos y repetidos. Al editar reemplaza la lista completa |
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

Borra el registro si nunca se ha vendido. Borrar un producto borra sus presentaciones, recargos y su archivo de imagen; borrar un sabor borra su inventario y recargos.

---

## POST /admin/productos/{id}/imagen

Sube o reemplaza la imagen del producto. Petición `multipart/form-data` con el campo **`imagen`**.

- Formatos: **JPG, JPEG, PNG o WEBP** (se revisan extensión, tipo MIME y que sea una imagen real).
- Tamaño máximo: **5 MB**. PHP debe permitirlo: `upload_max_filesize` ≥ 6M y `post_max_size` ≥ 8M.
- El archivo se guarda en `public/uploads/productos/producto-{id}-{aleatorio}.{ext}` y en `productos.imagen` queda la ruta relativa (`uploads/productos/...`). La imagen anterior se borra.
- Responde `{ id, catalogo }` como las demás escrituras.
- `GET /catalogo` y `GET /admin/catalogo` devuelven `imagen` (ruta relativa o `null`) en cada producto. La URL pública es `{raíz del API}/{imagen}`, p. ej. `http://localhost:8080/uploads/productos/producto-1-a1b2c3.jpg`.

```bash
curl -X POST http://localhost:8080/api/v1/admin/productos/1/imagen \
  -H "Authorization: Bearer <token>" -F "imagen=@helado.jpg"
```

Errores `400` en `messages.imagen`: *La imagen debe ser JPG, JPEG, PNG o WEBP.* · *La imagen pesa más de 5 MB.* · *El archivo no es una imagen válida.* · *No se recibió la imagen…*

## DELETE /admin/productos/{id}/imagen

Quita la imagen del producto (`imagen = null`) y borra el archivo. Responde `{ id, catalogo }`.

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

## Productos por sucursal

El producto se registra **una vez**; por sucursal solo se decide si se vende y, si cambia, su precio.

- `GET /admin/catalogo` trae `sucursales: [{ id, nombre, es_matriz, activo }]`, en cada producto `sucursales: [id, …]` (dónde se vende) y en cada presentación `precios_sucursal: { "id_sucursal": precio }` (solo los precios distintos al general).
- En `POST/PUT /admin/productos`:
  - `sucursales`: `int[]`, al menos una. Reemplaza la lista. Si se crea un producto sin este campo, se vende en todas.
  - `presentaciones[i].precios_sucursal`: `{ "2": 30 }`. Reemplaza los precios propios de esa presentación; un precio vacío o igual al general no se guarda (usa el general).
- Errores: `sucursales` (*Elige al menos una sucursal…*), `presentaciones.{i}.precios_sucursal.{id_sucursal}` (precio inválido).
- Una sucursal nueva (`POST /admin/sucursales`) arranca vendiendo todos los productos con su precio general.

```json
{
  "sucursales": [1, 2],
  "presentaciones": [
    { "id": 7, "nombre": "Única", "precio_base": 25, "precios_sucursal": { "2": 28 } }
  ]
}
```

