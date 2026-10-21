# Catálogo

## GET /catalogo

Devuelve en una sola llamada todo lo que el POS necesita para armar pedidos: categorías, productos con presentaciones y precios, sabores, extras, promociones activas y métodos de pago.

Solo incluye lo **activo** (`activo = 1`). Un producto también se omite si su categoría está inactiva o si no tiene presentaciones activas. Para ver y editar todo, ver [admin-catalogo.md](admin-catalogo.md).

**Auth:** token (cualquier rol)

### Respuesta `200`

```json
{
  "data": {
    "categorias": [
      { "id": 1, "nombre": "Helados" }
    ],
    "productos": [
      {
        "id": 1,
        "id_categoria": 1,
        "nombre": "Helado Tradicional",
        "requiere_sabor": true,
        "tipo_sabor": "Helado",
        "presentaciones": [
          {
            "id": 1,
            "nombre": "Chico (1 bola)",
            "precio_base": 25,
            "num_sabores": 1,
            "recargos": [
              { "id_sabor": 3, "monto": 15 }
            ]
          },
          {
            "id": 3,
            "nombre": "1/2 Litro",
            "precio_base": 80,
            "num_sabores": 2,
            "recargos": []
          }
        ],
        "extras": [3, 4]
      }
    ],
    "sabores": [
      { "id": 3, "nombre": "Piñón", "tipo": "Helado", "es_especial": true }
    ],
    "extras": [
      { "id": 3, "nombre": "Bola extra helado regular", "precio": 20 }
    ],
    "promociones": [
      { "id": 1, "nombre": "Martes Loco (Botana + Agua 1/2L)", "precio_combo": 65, "dia_semana": 3 }
    ],
    "metodos_pago": [
      { "id": 1, "nombre": "Efectivo" }
    ]
  }
}
```

### Campos

**productos[]**

| Campo | Tipo | Descripción |
|---|---|---|
| `requiere_sabor` | bool | El vendedor debe elegir sabor al agregarlo |
| `tipo_sabor` | string \| null | Qué sabores se ofrecen: los de `sabores[]` con ese `tipo` |
| `presentaciones` | array | Tamaños o variantes con su `precio_base`. Los productos de un solo precio traen una presentación "Única" |
| `extras` | int[] | Ids de `extras[]` que se ofrecen en este producto (solo extras activos) |

**presentaciones[].num_sabores** — cuántos sabores se eligen en esa presentación (Chico 1 bola = 1, Grande 2 bolas = 2).

**presentaciones[].recargos[]** — cuánto se suma al `precio_base` si el cliente elige ese sabor en esa presentación. Ejemplo: Piñón en Chico suma $15.

Precio de una línea = `precio_base` de la presentación + recargo de cada sabor elegido en esa presentación (si existe) + extras.

**sabores[]**

| Campo | Tipo | Descripción |
|---|---|---|
| `tipo` | string | Familia del sabor: `Helado`, `Paleta Agua`, `Paleta Crema`, `Agua Fresca` |
| `es_especial` | bool | Sabor premium; suele tener recargo |

**promociones[]** — solo se devuelven las activas.

| Campo | Tipo | Descripción |
|---|---|---|
| `dia_semana` | int \| null | Día en que aplica (1 = domingo … 7 = sábado, como `DAYOFWEEK` de MySQL). `null` = todos los días |

### Errores

| Código | Cuándo |
|---|---|
| 401 | Falta el token, o es inválido o vencido |

### Ejemplo

```bash
curl http://localhost:8080/api/v1/catalogo \
  -H "Authorization: Bearer <token>"
```

### Limitaciones actuales del modelo de datos

- La BD no indica qué **tipo de sabor** usa cada producto. Por ahora el front decide con `sabores[].tipo` (p. ej. "Paleta de Agua" → `Paleta Agua`).
- La BD no indica qué **extras** aplican a cada producto ni qué productos forman cada **promoción**.
