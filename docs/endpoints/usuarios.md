# Usuarios

Catálogo de usuarios: alta, edición (rol, sucursal, contraseña), activación y borrado. Lo usa **Administración › Usuarios**.

**Auth:** token con rol **Administrador** (`id_rol` 1).

**Tablas:** `usuarios` (con `id_sucursal` y `ultimo_acceso`), `roles`, `sucursales`.

### Reglas

- Nadie puede **desactivarse** ni **quitarse el rol de Administrador** a sí mismo, ni eliminarse.
- Siempre debe quedar **al menos un Administrador activo**.
- Un usuario que ya operó (ventas, turnos de caja, movimientos, checklist, producción) **no se borra**: se desactiva (`409`).
- Desactivar a alguien o cambiarle el rol **aplica de inmediato**: el filtro `auth` revisa en cada petición que el usuario siga activo y usa su rol vigente en la BD (no el del token). Un usuario desactivado recibe `401` y el front lo manda a login.
- Las contraseñas se guardan con bcrypt; el API nunca las devuelve.

Las escrituras responden con el listado completo (mismo formato que `GET`), más el `id` afectado.

---

## GET /admin/usuarios

```json
{
  "data": {
    "usuarios": [
      {
        "id": 2,
        "nombre": "María López",
        "usuario": "vendedor1",
        "id_rol": 2,
        "rol": "Vendedor / Cajero",
        "sucursal": { "id": 1, "nombre": "Sucursal Centro Histórico" },
        "activo": true,
        "ultimo_acceso": "2026-10-20T16:47:00-06:00",
        "fecha_creacion": "2026-10-20T10:27:06-06:00",
        "en_uso": false
      }
    ],
    "yo": 1,
    "roles": [{ "id": 1, "nombre": "Administrador", "descripcion": "Acceso total…", "usuarios": 1 }],
    "sucursales": [{ "id": 1, "codigo": "SUC-001", "nombre": "Sucursal Centro Histórico", "activo": true }]
  }
}
```

| Campo | Descripción |
|---|---|
| `en_uso` | Ya tiene operaciones registradas: no se puede borrar |
| `yo` | Id del administrador que consulta (para bloquear acciones sobre sí mismo) |
| `roles[].usuarios` | Usuarios activos con ese rol |
| `ultimo_acceso` | Último login; `null` si nunca ha entrado |

## POST /admin/usuarios

```json
{ "nombre": "Sofía Méndez", "usuario": "sofia.mendez", "password": "********", "id_rol": 2, "id_sucursal": 1, "activo": true }
```

| Campo | Obligatorio al crear | Reglas |
|---|---|---|
| `nombre` | sí | Máx. 100 |
| `usuario` | sí | 3 a 50: minúsculas, números, `.`, `-`, `_`. Único |
| `password` | sí | 8 a 72 caracteres |
| `id_rol` | sí | Rol existente |
| `id_sucursal` | no | Sucursal existente; `null` = sin asignar |
| `activo` | no | Default `true` |

## PUT /admin/usuarios/{id}

Cambios parciales. `password` vacío o ausente conserva la contraseña actual.

## DELETE /admin/usuarios/{id}

Solo si `en_uso` es `false` y no es tu propio usuario.

### Errores

| Status | Cuándo |
|---|---|
| 400 | Datos inválidos, usuario repetido, contraseña corta |
| 401 / 403 | Sin token, token vencido, usuario desactivado o rol que no es Administrador |
| 404 | El usuario no existe |
| 409 | Acciones sobre ti mismo, dejar sin administradores activos, borrar a alguien que ya operó |
