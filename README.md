# conitos-central-api

Back end del sistema de Conitos Central: API REST en **CodeIgniter 4.7** sobre MySQL/MariaDB.

| Documento | Contenido |
|---|---|
| [docs/README.md](docs/README.md) | Endpoints, autenticación, roles y formato de respuestas |
| [docs/BASE_DE_DATOS.md](docs/BASE_DE_DATOS.md) | Modelo de datos: diagramas, tablas, reglas e historial de migraciones |
| [docs/esquema.sql](docs/esquema.sql) | Estructura completa de la BD en SQL (sin datos) |
| [docs/PENDIENTES.md](docs/PENDIENTES.md) | Pendientes del sistema (API, BD y front), por prioridad |

Front: [conitos-central-app](https://github.com/erikalancc/conitos-central-app)

## Qué hace

| Módulo | Endpoints | Roles |
|---|---|---|
| Sesión | `/auth/login`, `/auth/me` | Todos |
| Catálogo del POS | `GET /catalogo` (solo lo activo) | Todos |
| Checklist de apertura | `/checklist/apertura` | Todos |
| Ventas e historial | `/ventas`, `/ventas/resumen` | Administrador, Cajero |
| Caja (apertura, movimientos, corte Z) | `/caja/...` | Administrador, Cajero |
| Administración: catálogo, sucursales, usuarios | `/admin/...` | Administrador |

Reglas clave:

- **Precios:** el API recalcula cada venta con el catálogo vigente y responde `409` si el total del POS no coincide (precios cambiados a media venta).
- **Caja:** un turno abierto a la vez; el API calcula el efectivo esperado y las diferencias del corte.
- **Desactivar en lugar de borrar:** lo que ya tiene ventas u operaciones no se borra (el API responde `409`).
- **Permisos al día:** en cada petición se revisa que el usuario siga activo y se usa su rol vigente en la BD.

## Requisitos

- PHP **8.2 o mayor** con extensiones `intl`, `mbstring`, `mysqli`, `curl`, `openssl`
- MySQL 5.7+ / MariaDB 10.4+
- Composer 2

## Instalación local

En esta máquina, XAMPP trae PHP 7.4, que no alcanza para CI 4.7. Por eso el API corre con un PHP 8.3 aparte en `C:\php83`. Usa el MySQL de XAMPP.

```bash
# 1. Dependencias
C:\php83\php.exe C:\composer\composer.phar install

# 2. Configuración
cp .env.example .env      # y ajustar BD, jwt.secret, cors.origins, CI_ENVIRONMENT = development

# 3. Levantar el API en http://localhost:8080
C:\php83\php.exe spark serve
```

La base de datos es `conitos_central_pos` (ya existente en el MySQL de XAMPP). Las tablas nuevas se crean con migraciones:

```bash
C:\php83\php.exe spark migrate
C:\php83\php.exe spark db:seed ChecklistItemsSeeder
```

**BD desde cero (otro servidor):** las tablas base no tienen migración. Importa [docs/esquema.sql](docs/esquema.sql) (ya incluye las tablas de las migraciones), carga los catálogos mínimos (`roles`, `metodos_pago`; el checklist con el seeder) e inserta el primer usuario Administrador (`id_rol = 1`); su contraseña se asigna con `php spark usuarios:password <usuario>`. Ver [PENDIENTES.md](docs/PENDIENTES.md).

### Comandos útiles

| Comando | Para qué |
|---|---|
| `php spark routes` | Ver todas las rutas y sus filtros |
| `php spark usuarios:password <usuario>` | Asignar contraseña nueva a un usuario |

## Estructura

```
app/
  Config/Routes.php           Rutas del API (/api/v1/...)
  Config/Jwt.php              Configuración de tokens
  Controllers/Api/
    BaseApiController.php     Formato de respuestas JSON
    V1/
      Auth.php                Login y sesión (registra el último acceso)
      Catalogo.php            Catálogo del POS (solo lo activo)
      CatalogoAdmin.php       Administración de categorías, productos, sabores, extras y promociones
      Checklist.php           Checklist de apertura
      Ventas.php              Cobro (recalcula precios), historial y resumen por día
      Caja.php                Turno de caja: apertura, movimientos, corte Z y consulta de cortes
      Sucursales.php          Sucursales y terminales
      Usuarios.php            Catálogo de usuarios
  Filters/AuthFilter.php      Valida el token, que el usuario siga activo y su rol vigente
  Libraries/Token.php         Emite y valida JWT
  Libraries/Sesion.php        Usuario de la petición actual (service('sesion'))
  Models/                     Un modelo por tabla; VentaModel, CorteModel y SucursalModel con consultas propias
  Commands/
    UsuariosPassword.php      php spark usuarios:password <usuario>
  Database/Migrations/        Cambios a la BD (ver docs/BASE_DE_DATOS.md)
  Database/Seeds/             ChecklistItemsSeeder
docs/                         Endpoints, modelo de BD, esquema SQL y pendientes
```

## Despliegue en Hostinger

1. En hPanel, selecciona **PHP 8.2 o mayor** y activa `intl`.
2. Crea un subdominio para el API (p. ej. `api.tudominio.com`) y apunta su carpeta raíz a **`public/`** del proyecto. Solo `public/` debe quedar expuesta en la web.
   - Si no se puede cambiar la carpeta raíz, sube el proyecto fuera de `public_html` y copia el contenido de `public/` a la carpeta del subdominio. Luego ajusta la ruta en `index.php`: `require FCPATH . '../conitos-central-api/app/Config/Paths.php';`
3. Sube el código y ejecuta `composer install --no-dev --optimize-autoloader`, o sube la carpeta `vendor/` ya generada.
4. Crea `.env` a partir de `.env.example` con:
   - `CI_ENVIRONMENT = production`
   - Datos de la BD de Hostinger
   - Un `jwt.secret` **nuevo** (`php -r "echo bin2hex(random_bytes(32));"`)
   - `cors.origins` con la URL del front en producción
5. Da permisos de escritura a `writable/`.
6. Por SSH, ejecuta `php spark migrate` y los seeders (ver Instalación local).
