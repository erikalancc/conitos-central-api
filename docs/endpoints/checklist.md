# Checklist de apertura

Control diario de calidad que el cajero llena antes de abrir caja. Hay **uno por día**: se completa o se omite (la omisión queda pendiente de autorización de un supervisor).

El front lo dispara al entrar a Inicio cuando `GET /checklist/apertura` trae `registro: null`.

**Tablas:** `checklist_items` (puntos a revisar), `checklist_registros` (uno por día), `checklist_respuestas` (una por punto) y `checklist_temperaturas` (lecturas de los puntos de temperatura).

---

## GET /checklist/apertura

Devuelve los puntos a revisar y el registro de hoy, si ya existe.

**Auth:** token (cualquier rol)

### Respuesta `200`

```json
{
  "data": {
    "fecha": "2026-10-20",
    "items": [
      {
        "id": 1,
        "orden": 1,
        "titulo": "Revisión de productos en vitrina",
        "descripcion": "Caducidad, consistencia cremosa, rotulación y frescura de helados",
        "etiqueta_ok": "Conforme",
        "etiqueta": null,
        "tipo_campo": "SELECT",
        "config": {
          "etiqueta": "Estado de lote",
          "opciones": [
            { "valor": "conforme", "texto": "Conforme y Aprobado", "nivel": "ok" },
            { "valor": "observaciones", "texto": "Con observaciones", "nivel": "aviso" },
            { "valor": "rechazado", "texto": "Rechazado", "nivel": "error" }
          ]
        },
        "es_obligatorio": true
      },
      {
        "id": 3,
        "orden": 3,
        "titulo": "Control de Refrigeración y Congeladores",
        "descripcion": "Revisar temperatura adecuada antes de comenzar el despacho",
        "etiqueta_ok": "En rango",
        "etiqueta": null,
        "tipo_campo": "TEMPERATURE_PAIR",
        "config": {
          "sensores": [
            { "clave": "congelador", "nombre": "Congelador", "min": -30, "max": -15 },
            { "clave": "refrigerador", "nombre": "Refrigerador", "min": 0, "max": 5 }
          ]
        },
        "es_obligatorio": true
      }
    ],
    "registro": null
  }
}
```

### Campos de `items[]`

| Campo | Descripción |
|---|---|
| `etiqueta_ok` | Texto del chip cuando el punto está cumplido |
| `etiqueta` | Tag opcional junto al título (p. ej. "Rappi & Uber Eats") |
| `tipo_campo` | Qué se captura además del check (ver tabla siguiente) |
| `config` | Configuración según `tipo_campo`; `null` en `BOOLEAN` |

| `tipo_campo` | Qué se captura | `config` |
|---|---|---|
| `BOOLEAN` | Solo el check | `null` |
| `SELECT` | Una opción | `{ etiqueta, opciones: [{ valor, texto, nivel }] }`. `nivel`: `ok`, `aviso` o `error`. Las opciones que no son `ok` cuentan como incidencia |
| `TEMPERATURE_PAIR` | Una lectura en °C por sensor | `{ sensores: [{ clave, nombre, min, max }] }`. Una lectura fuera de `min`–`max` cuenta como incidencia |
| `TEXT` | Texto libre (máx. 255) | `null` |

`registro` tiene el mismo formato que la respuesta de `POST /checklist/apertura`, o es `null` si hoy no se ha hecho.

---

## POST /checklist/apertura

Guarda el checklist completado de hoy. Si hoy ya se había omitido, lo reemplaza.

**Auth:** token (cualquier rol). El registro queda a nombre del usuario del token.

### Cuerpo

```json
{
  "respuestas": [
    { "id_item": 1, "cumplido": true, "valor": "conforme" },
    { "id_item": 2, "cumplido": true },
    { "id_item": 3, "cumplido": true, "temperaturas": { "congelador": -18, "refrigerador": 3.5 } },
    { "id_item": 4, "cumplido": true },
    { "id_item": 5, "cumplido": true }
  ],
  "comentarios": ""
}
```

| Campo | Tipo | Requerido | Notas |
|---|---|---|---|
| `respuestas[].id_item` | int | sí | Id del punto (`items[].id`) |
| `respuestas[].cumplido` | bool | sí | Debe ser `true` en los puntos obligatorios |
| `respuestas[].valor` | string | en `SELECT` | Uno de `config.opciones[].valor`. En `TEXT`, el texto capturado |
| `respuestas[].temperaturas` | objeto | en `TEMPERATURE_PAIR` | `{ <clave del sensor>: grados }`, un número por sensor |
| `comentarios` | string | si hay incidencia | Obligatorio si alguna opción no es `ok` o alguna temperatura está fuera de rango |

### Respuesta `201`

```json
{
  "data": {
    "id": 2,
    "fecha": "2026-10-20",
    "estado": "COMPLETADO",
    "con_incidencia": false,
    "comentarios": null,
    "usuario": { "id": 2, "nombre": "María López" },
    "autorizado": false,
    "registrado_en": "2026-10-20T15:09:13-06:00"
  }
}
```

| Campo | Descripción |
|---|---|
| `estado` | `COMPLETADO` u `OMITIDO` |
| `con_incidencia` | `true` si hubo una opción con nivel distinto de `ok` o una temperatura fuera de rango |
| `autorizado` | En omisiones: si ya la autorizó un supervisor |

### Errores

| Código | Cuándo | `messages` |
|---|---|---|
| 400 | Falta marcar un punto obligatorio | `{ "item_<id>": "Falta marcar: <título>." }` |
| 400 | Opción inválida en un `SELECT` | `{ "item_<id>": "Elige una opción válida en: <título>." }` |
| 400 | Falta una temperatura | `{ "item_<id>": "Captura la temperatura de <sensor>." }` |
| 400 | Hay incidencia y no hay comentarios | `{ "comentarios": "Describe la incidencia (lote o temperatura fuera de rango)." }` |
| 409 | Hoy ya se completó el checklist | `{ "error": "El checklist de apertura de hoy ya fue registrado." }` |

---

## POST /checklist/apertura/omitir

Registra que hoy se omitió el checklist. Queda pendiente de autorización de un supervisor. Después se puede completar con `POST /checklist/apertura`.

**Auth:** token (cualquier rol)

**Cuerpo:** ninguno

### Respuestas

| Código | Cuándo |
|---|---|
| 201 | Se registró la omisión. `data` = registro con `estado: "OMITIDO"` |
| 200 | Hoy ya estaba omitido. `data` = el registro existente |
| 409 | Hoy ya se completó el checklist |

---

## Administrar los puntos del checklist

Por ahora los puntos se cargan con un seeder (solo si la tabla está vacía):

```bash
php spark db:seed ChecklistItemsSeeder
```

Para agregar, quitar o cambiar un punto hay que editarlo en `checklist_items`: `activo = 0` lo oculta y `orden` define la posición. Más adelante se puede agregar un CRUD para el administrador.

## Checklist por sucursal y equipos de frío (2026-11-07)

- El checklist es **uno por día por sucursal** (la del usuario de la sesión). `GET /checklist/apertura` agrega `sucursal: { id, nombre }`.
- En el punto `TEMPERATURE_PAIR`, `config.sensores` ya no es fijo: son los **equipos activos de la sucursal**: `{ clave: "equipo-{id}", id_equipo, nombre, tipo, min, max }`. Si la sucursal no tiene equipos, la lista viene vacía y no se piden temperaturas.
- En `POST /checklist/apertura`, `temperaturas` se envía por clave: `{ "equipo-1": -18.5, "equipo-4": 3 }` (se aceptan negativos).
- Cada lectura se guarda con `id_equipo`, nombre del equipo y el rango vigente (`temp_min`, `temp_max`), para que el historial no cambie si después se edita el equipo.

## Checklist configurable por momento (2026-11-08)

Los puntos ya no son fijos: se configuran en Administración. Hay tres **momentos** y cada uno tiene su registro diario por sucursal:

| Momento (URL) | Cuándo se pide |
|---|---|
| `apertura` | Al iniciar el día |
| `medio-turno` | Desde la mitad del horario de la sucursal (`hora_apertura`–`hora_cierre`; sin horario, 10:00–21:00) |
| `cierre` | Antes del corte de caja |

- `GET /checklist/{momento}` → `{ fecha, momento, sucursal, disponible, disponible_desde, items, registro }`. Solo trae los puntos activos del momento que aplican a la sucursal del usuario. Si `items` viene vacío, no hay nada que llenar.
- `POST /checklist/{momento}` guarda el checklist del momento (mismo cuerpo que antes). `POST /checklist/{momento}/omitir`: `403` para el vendedor.
- `POST /caja/cierre`: al Vendedor / Cajero le responde `409` (*Completa el checklist de antes del corte…*) si el momento `cierre` tiene puntos y hoy no se completó.
- El punto de **refrigeradores y congeladores** es fijo (`es_fijo`): siempre está en la apertura y no se edita ni se borra.

## Administración: `/admin/checklist` (solo Administrador)

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/admin/checklist` | `{ items, sucursales }`. Cada punto trae `momento`, `tipo_campo`, `config`, `es_obligatorio`, `es_fijo`, `activo`, `sucursales` (vacío = todas) y `usado` |
| POST | `/admin/checklist` | Alta de punto |
| PUT | `/admin/checklist/{id}` | Edición (cuerpo completo). `409` si es el punto fijo |
| DELETE | `/admin/checklist/{id}` | `409` si es fijo o si ya tiene respuestas (hay que desactivarlo) |

Cuerpo: `{ titulo*, descripcion, etiqueta_ok, momento* (apertura|medio-turno|cierre), tipo_campo* (BOOLEAN|SELECT|TEXT), opciones (solo SELECT, mínimo 2: [{ texto, nivel: ok|aviso|error }]), sucursales: [ids] (vacío = todas), es_obligatorio, activo }`. Un punto nuevo, o uno que cambia de momento, va al final de su momento.

