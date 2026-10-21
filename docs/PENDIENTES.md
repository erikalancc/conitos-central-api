# Pendientes — Conitos Central POS

Estado al **20 de octubre de 2026**. Cubre API, base de datos y front ([conitos-central-app](https://github.com/erikalancc/conitos-central-app)). Ordenado por prioridad.

## 🔴 Atender primero

| # | Pendiente | Por qué importa | Propuesta |
|---|---|---|---|
| 1 | **Versionar el código en git** | En el API, toda la carpeta `app/` está sin versionar (`git status` la marca como nueva) y el front tiene muchos cambios sin commit. Durante el desarrollo se borró por error el comando `usuarios:password` y no hubo copia de dónde recuperarlo (se reconstruyó). | Hacer commit de ambos repos y subirlos. |
| 2 | **Respaldos de la BD** | MySQL no tiene `log_bin` activo y no hay respaldos: un borrado no se puede revertir. | `mysqldump` programado (diario) y, en producción, los respaldos de Hostinger. |
| 3 | **Cambiar la contraseña temporal de María López** (`vendedor1`) | Se restauró con una contraseña temporal tras borrarse por error en una prueba. | Administración › Usuarios › María López › Nueva contraseña, o `php spark usuarios:password vendedor1`. |
| 4 | **Descuentos sin catálogo en BD** | La lista está fija en el front (`data/catalogo.js`) y el API acepta cualquier descuento que mande el POS (con límites: ≤ 100 % o ≤ subtotal). | Tabla `descuentos` administrable, que el API valide por id y, si aplica, que pida autorización. |
| 5 | **El número de orden se repite** | `folio_orden` sale de un contador del navegador que se reinicia al recargar (varias ventas reales quedaron como #48). | Que el API asigne el folio (consecutivo por día y terminal). |
| 6 | **Instalación desde cero** | Las tablas base no tienen migración: solo existen en la BD local. | Usar [esquema.sql](esquema.sql) + seeders de catálogos mínimos (`roles`, `metodos_pago`, checklist), o crear una migración base. |

## 🟠 Operación del negocio

| # | Pendiente | Detalle |
|---|---|---|
| 7 | **Multi-sucursal real** | Hoy hay **una sola caja**: un solo turno abierto a la vez y las ventas no saben de qué sucursal o terminal salieron. Falta: `ventas.id_terminal`, `cortes_caja.id_terminal`, un turno abierto **por terminal**, configurar en cada equipo qué terminal es (p. ej. al iniciar sesión) y filtrar historial y cortes por sucursal. |
| 8 | **Cancelaciones y devoluciones** | No se puede cancelar una venta ni registrar devoluciones; el corte no las contempla. |
| 9 | **Redondeo del efectivo** | Un descuento del 10 % deja centavos (p. ej. $72.90) que no se pueden contar en gaveta (la moneda menor es 50¢). Redondear los cobros en efectivo. |
| 10 | **Impresión y hardware** | Pre-cuenta, ticket de venta, apertura de gaveta y "Test Gaveta" son simulados. Los cortes X/Z se generan en PDF (80 mm), no en la impresora térmica. |
| 11 | **Órdenes en espera** | "Guardar orden" solo vive en el navegador; no se pueden retomar desde otro equipo ni hay pantalla para recuperarlas. |
| 12 | **Inventario no se mueve con las ventas** | `inventario_sabores` (vitrina y botes) no se descuenta al vender; Administración solo lo muestra. |
| 13 | **Revisar valores deducidos del catálogo** | Al conectar el POS a la BD se dedujeron de los nombres: qué sabores ofrece cada producto (`tipo_sabor`; dudoso: Paleta Mini = Paleta Agua), cuántos sabores lleva cada presentación (1/2 L = 2, 1 L = 3) y qué extras ofrece cada producto. Ajustables en Administración › Productos. |
| 14 | **Combos de promoción** | La BD no define qué lleva cada combo; el cajero lo escribe en notas. |
| 15 | **Métodos de pago** | SPEI / QR está oculto (`activo: false` en el front) y no existe en `metodos_pago`. El corte agrupa Débito y Crédito como "Tarjeta". |

## 🟡 Módulos por construir

| # | Módulo | Estado |
|---|---|---|
| 16 | Recepción de Inventario (`/inventario`) | Pantalla "En construcción"; solo Administrador la ve. |
| 17 | Consulta de Precios (`/precios`) | Pantalla "En construcción"; solo Administrador la ve. |
| 18 | Producción | Tablas `ordenes_produccion`, `estados_produccion`, `aperturas_botes_reserva` sin uso. El rol **Productor** hoy solo ve Inicio. |
| 19 | Compras / proveedores | El rol **Encargado Compras** no tiene módulo (solo ve Inicio). |
| 20 | Reportes | Descarga de reportes (ventas por periodo, por producto, por sucursal); botón "Descargar reporte" del diseño. |
| 21 | Checklist administrable | Los puntos del checklist se cargan con seeder; no hay pantalla para editarlos ni para autorizar omisiones. |

## 🟢 Datos de ejemplo que siguen en pantalla

Vienen de `src/data/dummy.js` en el front y deben conectarse a la BD:

| Dónde | Dato fijo |
|---|---|
| Encabezado y ticket del corte | `Terminal #01 · Sucursal Centro Histórico` (la matriz real ya se llama distinto) y la versión `v3.2.0-cloud` |
| Inicio | Etiqueta "Apertura Requerida" (sale aunque la caja esté abierta), "Turno asignado Matutino 09:00 – 17:00", "Promo de la tarde", "Top ventas ayer", "Protocolo de mostrador", "Corte anterior ($14,280.00)" y "8 lotes" |
| Login | "Latencia: 18ms", "POS v2.4", texto de RFID (no hay lector integrado) |
| Fondo sugerido | $1,000 fijo (`FONDO_SUGERIDO`) |

## Catálogo: campos del diseño que no están en BD

SKU, costo de producción y margen, foto del producto, descripción, color en vitrina, etiquetas (vegano, frutos secos), producto destacado/favorito, orden de categorías en la barra de caja y herencia de catálogo por sucursal (hoy el catálogo es uno solo para todas).

## Seguridad e infraestructura

| # | Pendiente |
|---|---|
| 22 | Limitar intentos de login (fuerza bruta). |
| 23 | "Bloquear" solo borra la sesión del navegador: el token sigue válido hasta 12 h (salvo que se desactive al usuario). Evaluar lista de tokens revocados o tokens más cortos con renovación. |
| 24 | `writable/debugbar/` guarda las consultas en desarrollo (incluye hashes de contraseñas en los `UPDATE`). Limpiarla y confirmar `CI_ENVIRONMENT = production` en el servidor. |
| 25 | En producción: HTTPS, `cors.origins` con el dominio real, `jwt.secret` nuevo y `VITE_API_URL` del front apuntando al dominio del API. |
| 26 | Pruebas automáticas: no hay pruebas (PHPUnit en el API, Vitest en el front). Los flujos de venta, caja y permisos se probaron a mano. |

## Front (técnico)

| # | Pendiente |
|---|---|
| 27 | El bundle pesa más de 500 kB; dividir por rutas (`import()` dinámico). |
| 28 | Quedan llaves viejas en `localStorage` de versiones anteriores (`aperturaCaja`, `cortesCaja`, `movimientosCaja`, `ordenesGuardadas`) y una copia local de las ventas que solo se usa para "Más vendidos" / "Favoritos". Calcular popularidad desde el API y limpiar. |
| 29 | En desarrollo, Vite a veces sirvió versiones viejas de archivos tras varios cambios seguidos; si algo se ve raro, recargar con Ctrl + F5. |
