# API Conitos Central — Documentación

API REST del POS de Conitos Central. Todas las rutas viven bajo `/api/v1` y hablan JSON.

| Entorno | URL base |
|---|---|
| Local | `http://localhost:8080/api/v1` |
| Producción | `https://<dominio-del-api>/api/v1` |

## Endpoints

| Método | Ruta | Auth | Descripción | Doc |
|---|---|---|---|---|
| POST | `/auth/login` | — | Inicia sesión y devuelve el token | [auth.md](endpoints/auth.md#post-authlogin) |
| GET | `/auth/me` | Token | Usuario de la sesión actual | [auth.md](endpoints/auth.md#get-authme) |
| GET | `/catalogo` | Token | Catálogo completo para el POS | [catalogo.md](endpoints/catalogo.md) |
| GET | `/checklist/apertura` | Token | Puntos del checklist y registro de hoy | [checklist.md](endpoints/checklist.md#get-checklistapertura) |
| POST | `/checklist/apertura` | Token | Guarda el checklist completado de hoy | [checklist.md](endpoints/checklist.md#post-checklistapertura) |
| POST | `/checklist/apertura/omitir` | Token | Registra que hoy se omitió el checklist | [checklist.md](endpoints/checklist.md#post-checklistaperturaomitir) |
| POST | `/ventas` | Admin, Cajero | Registra una venta cobrada (recalcula precios) | [ventas.md](endpoints/ventas.md) |
| GET | `/ventas/resumen` | Admin, Cajero | Totales por día (Historial) | [ventas.md](endpoints/ventas.md#get-ventasresumen) |
| GET | `/ventas?fecha=` | Admin, Cajero | Ventas de un día con su detalle | [ventas.md](endpoints/ventas.md#get-ventas) |
| GET | `/caja/turno` | Admin, Cajero | Turno de caja abierto con totales en vivo | [caja.md](endpoints/caja.md#get-cajaturno) |
| POST | `/caja/apertura` | Admin, Cajero | Abre caja con el fondo inicial | [caja.md](endpoints/caja.md#post-cajaapertura) |
| POST | `/caja/movimientos` | Admin, Cajero | Entrada o retiro de efectivo | [caja.md](endpoints/caja.md#post-cajamovimientos) |
| POST | `/caja/cierre` | Admin, Cajero | Corte Z (calcula esperado y diferencias) | [caja.md](endpoints/caja.md#post-cajacierre) |
| GET | `/caja/cortes` | Admin, Cajero | Cortes cerrados en un rango | [caja.md](endpoints/caja.md#get-cajacortes) |
| GET | `/caja/cortes/{id}` | Admin, Cajero | Un corte con sus ventas | [caja.md](endpoints/caja.md#get-cajacortesid) |
| GET | `/admin/usuarios` | Admin | Usuarios, roles y sucursales | [usuarios.md](endpoints/usuarios.md#get-adminusuarios) |
| POST | `/admin/usuarios` | Admin | Alta de usuario | [usuarios.md](endpoints/usuarios.md#post-adminusuarios) |
| PUT | `/admin/usuarios/{id}` | Admin | Edición (rol, sucursal, contraseña, activo) | [usuarios.md](endpoints/usuarios.md#put-adminusuariosid) |
| DELETE | `/admin/usuarios/{id}` | Admin | Borra si nunca ha operado | [usuarios.md](endpoints/usuarios.md#delete-adminusuariosid) |
| GET | `/admin/sucursales` | Admin | Sucursales, terminales e indicadores | [sucursales.md](endpoints/sucursales.md#get-adminsucursales) |
| POST | `/admin/sucursales` | Admin | Alta de sucursal con sus terminales | [sucursales.md](endpoints/sucursales.md#post-adminsucursales) |
| PUT | `/admin/sucursales/{id}` | Admin | Edición (acepta cambios parciales) | [sucursales.md](endpoints/sucursales.md#put-adminsucursalesid) |
| DELETE | `/admin/sucursales/{id}` | Admin | Borra la sucursal (no la matriz) | [sucursales.md](endpoints/sucursales.md#delete-adminsucursalesid) |
| GET | `/admin/catalogo` | Admin | Catálogo completo, incluido lo inactivo | [admin-catalogo.md](endpoints/admin-catalogo.md#get-admincatalogo) |
| POST | `/admin/{recurso}` | Admin | Alta de categoría, producto, sabor, extra o promoción | [admin-catalogo.md](endpoints/admin-catalogo.md#post-adminrecurso) |
| PUT | `/admin/{recurso}/{id}` | Admin | Edición (acepta cambios parciales, p. ej. activar/desactivar) | [admin-catalogo.md](endpoints/admin-catalogo.md#put-adminrecursoid) |
| DELETE | `/admin/{recurso}/{id}` | Admin | Borra si nunca se ha vendido | [admin-catalogo.md](endpoints/admin-catalogo.md#delete-adminrecursoid) |

## Autenticación

1. Llama a `POST /auth/login` con usuario y contraseña.
2. Guarda `data.token` y mándalo en cada petición protegida:

```
Authorization: Bearer <token>
```

El token dura **12 horas** (un turno; se configura con `jwt.ttl`). Al vencer, el API responde `401` y el front debe mandar a login.

En cada petición el API además revisa que el usuario siga **activo** y usa su **rol vigente en la BD** (no el del token): si un administrador lo desactiva, su siguiente petición recibe `401`; si le cambia el rol, los permisos cambian de inmediato.

### Roles

Algunas rutas se limitan por rol. En la documentación de cada endpoint se indica cuáles.

| id_rol | Rol |
|---|---|
| 1 | Administrador |
| 2 | Vendedor / Cajero |
| 3 | Productor |
| 4 | Encargado Compras |

## Formato de respuestas

**Éxito** — el contenido siempre va dentro de `data`:

```json
{ "data": { ... } }
```

**Error** — mismo formato para todos los errores:

```json
{
  "status": 400,
  "error": 400,
  "messages": {
    "usuario": "El campo usuario es obligatorio."
  }
}
```

En errores de validación, `messages` trae un mensaje por campo. En los demás errores trae una sola llave `error`.

| Código | Cuándo |
|---|---|
| 200 | Petición correcta |
| 201 | Registro creado |
| 400 | Datos inválidos (ver `messages`) |
| 401 | Sin token, token inválido/vencido o credenciales incorrectas |
| 403 | El rol del usuario no tiene acceso |
| 404 | Recurso o ruta inexistente |
| 500 | Error del servidor |

## Convenciones

- Fechas en ISO 8601 con zona de México, p. ej. `2026-10-21T02:56:09-06:00`.
- Montos como número (`25.5`), no como texto.
- Ids como entero; banderas (`requiere_sabor`, `es_especial`) como `true`/`false`.
- Nombres de campos en español y `snake_case`.

## Cómo documentar un endpoint nuevo

1. Agrega la ruta en [app/Config/Routes.php](../app/Config/Routes.php).
2. Agrega una fila en la tabla de **Endpoints** de este archivo.
3. Documéntalo en `docs/endpoints/<recurso>.md` siguiendo la plantilla de [auth.md](endpoints/auth.md): método y ruta, auth/roles, cuerpo, respuesta de ejemplo y errores.
