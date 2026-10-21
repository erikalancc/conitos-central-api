# Auth

## POST /auth/login

Valida usuario y contraseña. Devuelve el token de acceso y los datos del usuario.

**Auth:** pública

### Cuerpo

| Campo | Tipo | Requerido | Notas |
|---|---|---|---|
| `usuario` | string | sí | Máx. 50 caracteres |
| `password` | string | sí | Máx. 72 caracteres |

```json
{
  "usuario": "vendedor1",
  "password": "********"
}
```

### Respuesta `200`

```json
{
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expira": "2026-10-21T02:56:09-06:00",
    "usuario": {
      "id": 2,
      "nombre": "María López",
      "usuario": "vendedor1",
      "id_rol": 2,
      "rol": "Vendedor / Cajero"
    }
  }
}
```

| Campo | Descripción |
|---|---|
| `token` | Se manda en `Authorization: Bearer <token>` |
| `expira` | Momento en que el token deja de servir |
| `usuario` | Datos para mostrar en el front (nunca incluye la contraseña) |

### Errores

| Código | Cuándo | `messages` |
|---|---|---|
| 400 | Falta `usuario` o `password` | Un mensaje por campo |
| 401 | Usuario inexistente, inactivo o contraseña incorrecta | `{ "error": "Usuario o contraseña incorrectos." }` |

Por seguridad, el mensaje es el mismo si el usuario no existe o si la contraseña está mal.

### Ejemplo

```bash
curl -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"usuario":"vendedor1","password":"********"}'
```

---

## GET /auth/me

Devuelve el usuario dueño del token. Sirve para validar la sesión guardada al abrir la app.

**Auth:** token (cualquier rol)

### Respuesta `200`

```json
{
  "data": {
    "id": 2,
    "nombre": "María López",
    "usuario": "vendedor1",
    "id_rol": 2,
    "rol": "Vendedor / Cajero"
  }
}
```

### Errores

| Código | Cuándo | `messages` |
|---|---|---|
| 401 | Falta el token | `{ "error": "Falta el token de acceso." }` |
| 401 | Token inválido o vencido | `{ "error": "Token inválido o expirado." }` |
| 401 | El usuario se desactivó después del login | `{ "error": "El usuario ya no está activo." }` |

### Ejemplo

```bash
curl http://localhost:8080/api/v1/auth/me \
  -H "Authorization: Bearer <token>"
```
