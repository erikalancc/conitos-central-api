-- Esquema de conitos_central_pos (solo estructura, sin datos)
-- Generado con: mysqldump --no-data --skip-comments --skip-add-drop-table conitos_central_pos
-- Incluye las tablas base y las que agregan las migraciones de app/Database/Migrations.


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `aperturas_botes_reserva` (
  `id_apertura` int(11) NOT NULL AUTO_INCREMENT,
  `id_sabor` int(11) NOT NULL,
  `fecha_hora` datetime DEFAULT current_timestamp(),
  `observaciones` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'Apertura de bote de reserva para vitrina',
  PRIMARY KEY (`id_apertura`),
  KEY `id_sabor` (`id_sabor`),
  CONSTRAINT `aperturas_botes_reserva_ibfk_1` FOREIGN KEY (`id_sabor`) REFERENCES `sabores` (`id_sabor`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categorias` (
  `id_categoria` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = oculto en el POS (se conserva para el historial de ventas)',
  PRIMARY KEY (`id_categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `checklist_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `orden` int(10) unsigned DEFAULT 0,
  `titulo` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `etiqueta_ok` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Texto del chip cuando el punto está cumplido',
  `etiqueta` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Tag opcional junto al título',
  `tipo_campo` enum('BOOLEAN','SELECT','TEMPERATURE_PAIR','TEXT') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BOOLEAN',
  `config` text COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'JSON: opciones (SELECT) o sensores con rango (TEMPERATURE_PAIR)',
  `es_obligatorio` tinyint(1) DEFAULT 1,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `checklist_registros` (
  `id_registro` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `estado` enum('COMPLETADO','OMITIDO') NOT NULL,
  `con_incidencia` tinyint(1) NOT NULL DEFAULT 0,
  `comentarios` text DEFAULT NULL,
  `id_usuario_autoriza` int(11) DEFAULT NULL COMMENT 'Supervisor que autoriza una omisión',
  `fecha_autorizacion` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id_registro`),
  UNIQUE KEY `fecha` (`fecha`),
  KEY `checklist_registros_id_usuario_foreign` (`id_usuario`),
  KEY `checklist_registros_id_usuario_autoriza_foreign` (`id_usuario_autoriza`),
  CONSTRAINT `checklist_registros_id_usuario_autoriza_foreign` FOREIGN KEY (`id_usuario_autoriza`) REFERENCES `usuarios` (`id_usuario`),
  CONSTRAINT `checklist_registros_id_usuario_foreign` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `checklist_respuestas` (
  `id_respuesta` int(11) NOT NULL AUTO_INCREMENT,
  `id_registro` int(11) NOT NULL,
  `id_item` int(11) NOT NULL,
  `cumplido` tinyint(1) NOT NULL DEFAULT 0,
  `valor` varchar(255) DEFAULT NULL COMMENT 'Opción elegida (SELECT) o texto (TEXT)',
  PRIMARY KEY (`id_respuesta`),
  UNIQUE KEY `id_registro_id_item` (`id_registro`,`id_item`),
  KEY `checklist_respuestas_id_item_foreign` (`id_item`),
  CONSTRAINT `checklist_respuestas_id_item_foreign` FOREIGN KEY (`id_item`) REFERENCES `checklist_items` (`id`),
  CONSTRAINT `checklist_respuestas_id_registro_foreign` FOREIGN KEY (`id_registro`) REFERENCES `checklist_registros` (`id_registro`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `checklist_temperaturas` (
  `id_temperatura` int(11) NOT NULL AUTO_INCREMENT,
  `id_respuesta` int(11) NOT NULL,
  `sensor` varchar(30) NOT NULL COMMENT 'Clave del sensor en config (congelador, refrigerador)',
  `grados` decimal(5,2) NOT NULL,
  `en_rango` tinyint(1) NOT NULL,
  PRIMARY KEY (`id_temperatura`),
  KEY `checklist_temperaturas_id_respuesta_foreign` (`id_respuesta`),
  CONSTRAINT `checklist_temperaturas_id_respuesta_foreign` FOREIGN KEY (`id_respuesta`) REFERENCES `checklist_respuestas` (`id_respuesta`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cortes_caja` (
  `id_corte` int(11) NOT NULL AUTO_INCREMENT,
  `estado` enum('ABIERTO','CERRADO') NOT NULL DEFAULT 'ABIERTO',
  `id_usuario_apertura` int(11) NOT NULL,
  `fecha_apertura` datetime NOT NULL,
  `fondo_inicial` decimal(10,2) NOT NULL,
  `comentario_apertura` varchar(255) DEFAULT NULL,
  `id_usuario_cierre` int(11) DEFAULT NULL,
  `fecha_cierre` datetime DEFAULT NULL,
  `tickets` int(11) DEFAULT NULL,
  `ventas_brutas` decimal(10,2) DEFAULT NULL,
  `descuentos` decimal(10,2) DEFAULT NULL,
  `ventas_netas` decimal(10,2) DEFAULT NULL,
  `ventas_efectivo` decimal(10,2) DEFAULT NULL,
  `ventas_tarjeta` decimal(10,2) DEFAULT NULL,
  `entradas` decimal(10,2) DEFAULT NULL,
  `retiros` decimal(10,2) DEFAULT NULL,
  `efectivo_esperado` decimal(10,2) DEFAULT NULL,
  `efectivo_contado` decimal(10,2) DEFAULT NULL,
  `diferencia_efectivo` decimal(10,2) DEFAULT NULL,
  `tarjeta_terminal` decimal(10,2) DEFAULT NULL COMMENT 'Total del cierre de lote de la terminal bancaria',
  `vouchers` int(11) DEFAULT NULL,
  `diferencia_tarjeta` decimal(10,2) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  PRIMARY KEY (`id_corte`),
  KEY `cortes_caja_id_usuario_apertura_foreign` (`id_usuario_apertura`),
  KEY `cortes_caja_id_usuario_cierre_foreign` (`id_usuario_cierre`),
  KEY `estado` (`estado`),
  KEY `fecha_apertura` (`fecha_apertura`),
  CONSTRAINT `cortes_caja_id_usuario_apertura_foreign` FOREIGN KEY (`id_usuario_apertura`) REFERENCES `usuarios` (`id_usuario`),
  CONSTRAINT `cortes_caja_id_usuario_cierre_foreign` FOREIGN KEY (`id_usuario_cierre`) REFERENCES `usuarios` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cortes_caja_conteo` (
  `id_corte` int(11) NOT NULL,
  `denominacion` decimal(8,2) NOT NULL,
  `piezas` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_corte`,`denominacion`),
  CONSTRAINT `cortes_caja_conteo_id_corte_foreign` FOREIGN KEY (`id_corte`) REFERENCES `cortes_caja` (`id_corte`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `detalle_venta_extras` (
  `id_detalle_extra` int(11) NOT NULL AUTO_INCREMENT,
  `id_detalle` int(11) NOT NULL,
  `id_extra` int(11) NOT NULL,
  `cantidad` int(11) DEFAULT 1,
  `precio_unitario` decimal(8,2) NOT NULL,
  PRIMARY KEY (`id_detalle_extra`),
  KEY `id_detalle` (`id_detalle`),
  KEY `id_extra` (`id_extra`),
  CONSTRAINT `detalle_venta_extras_ibfk_1` FOREIGN KEY (`id_detalle`) REFERENCES `detalle_ventas` (`id_detalle`) ON DELETE CASCADE,
  CONSTRAINT `detalle_venta_extras_ibfk_2` FOREIGN KEY (`id_extra`) REFERENCES `extras_toppings` (`id_extra`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `detalle_venta_sabores` (
  `id_detalle_sabor` int(11) NOT NULL AUTO_INCREMENT,
  `id_detalle` int(11) NOT NULL,
  `id_sabor` int(11) NOT NULL,
  PRIMARY KEY (`id_detalle_sabor`),
  KEY `id_detalle` (`id_detalle`),
  KEY `id_sabor` (`id_sabor`),
  CONSTRAINT `detalle_venta_sabores_ibfk_1` FOREIGN KEY (`id_detalle`) REFERENCES `detalle_ventas` (`id_detalle`) ON DELETE CASCADE,
  CONSTRAINT `detalle_venta_sabores_ibfk_2` FOREIGN KEY (`id_sabor`) REFERENCES `sabores` (`id_sabor`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `detalle_ventas` (
  `id_detalle` int(11) NOT NULL AUTO_INCREMENT,
  `id_venta` int(11) NOT NULL,
  `id_presentacion` int(11) DEFAULT NULL,
  `id_promocion` int(11) DEFAULT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 1,
  `precio_unitario` decimal(8,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `notas` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Indicaciones de preparación o contenido del combo',
  PRIMARY KEY (`id_detalle`),
  KEY `id_venta` (`id_venta`),
  KEY `id_presentacion` (`id_presentacion`),
  KEY `id_promocion` (`id_promocion`),
  CONSTRAINT `detalle_ventas_ibfk_1` FOREIGN KEY (`id_venta`) REFERENCES `ventas` (`id_venta`) ON DELETE CASCADE,
  CONSTRAINT `detalle_ventas_ibfk_2` FOREIGN KEY (`id_presentacion`) REFERENCES `presentaciones` (`id_presentacion`) ON DELETE SET NULL,
  CONSTRAINT `detalle_ventas_ibfk_3` FOREIGN KEY (`id_promocion`) REFERENCES `promociones` (`id_promocion`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estados_produccion` (
  `id_estado` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `extras_toppings` (
  `id_extra` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `precio` decimal(8,2) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = oculto en el POS (se conserva para el historial de ventas)',
  PRIMARY KEY (`id_extra`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventario_sabores` (
  `id_inventario` int(11) NOT NULL AUTO_INCREMENT,
  `id_sabor` int(11) NOT NULL,
  `id_empaque` int(11) DEFAULT NULL,
  `botes_reserva` int(11) NOT NULL DEFAULT 0,
  `porciones_vitrina_actual` decimal(8,2) NOT NULL DEFAULT 0.00,
  `porciones_vitrina_max` decimal(8,2) NOT NULL DEFAULT 40.00,
  `stock_minimo_reserva` int(11) DEFAULT 1,
  PRIMARY KEY (`id_inventario`),
  KEY `id_sabor` (`id_sabor`),
  KEY `id_empaque` (`id_empaque`),
  CONSTRAINT `inventario_sabores_ibfk_1` FOREIGN KEY (`id_sabor`) REFERENCES `sabores` (`id_sabor`) ON DELETE CASCADE,
  CONSTRAINT `inventario_sabores_ibfk_2` FOREIGN KEY (`id_empaque`) REFERENCES `unidades_empaque` (`id_empaque`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `metodos_pago` (
  `id_metodo` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_metodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(255) NOT NULL,
  `class` varchar(255) NOT NULL,
  `group` varchar(255) NOT NULL,
  `namespace` varchar(255) NOT NULL,
  `time` int(11) NOT NULL,
  `batch` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `movimientos_caja` (
  `id_movimiento` int(11) NOT NULL AUTO_INCREMENT,
  `id_corte` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `tipo` enum('ENTRADA','RETIRO') NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `concepto` varchar(150) NOT NULL,
  `fecha_hora` datetime NOT NULL,
  PRIMARY KEY (`id_movimiento`),
  KEY `movimientos_caja_id_corte_foreign` (`id_corte`),
  KEY `movimientos_caja_id_usuario_foreign` (`id_usuario`),
  CONSTRAINT `movimientos_caja_id_corte_foreign` FOREIGN KEY (`id_corte`) REFERENCES `cortes_caja` (`id_corte`) ON DELETE CASCADE,
  CONSTRAINT `movimientos_caja_id_usuario_foreign` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ordenes_produccion` (
  `id_orden` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario_productor` int(11) NOT NULL,
  `id_sabor` int(11) NOT NULL,
  `id_empaque` int(11) NOT NULL,
  `botes_producidos` int(11) NOT NULL,
  `fecha_inicio` datetime DEFAULT current_timestamp(),
  `fecha_fin` datetime DEFAULT NULL,
  `id_estado` int(11) NOT NULL DEFAULT 1,
  `observaciones` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_orden`),
  KEY `id_usuario_productor` (`id_usuario_productor`),
  KEY `id_sabor` (`id_sabor`),
  KEY `id_empaque` (`id_empaque`),
  KEY `id_estado` (`id_estado`),
  CONSTRAINT `ordenes_produccion_ibfk_1` FOREIGN KEY (`id_usuario_productor`) REFERENCES `usuarios` (`id_usuario`),
  CONSTRAINT `ordenes_produccion_ibfk_2` FOREIGN KEY (`id_sabor`) REFERENCES `sabores` (`id_sabor`),
  CONSTRAINT `ordenes_produccion_ibfk_3` FOREIGN KEY (`id_empaque`) REFERENCES `unidades_empaque` (`id_empaque`),
  CONSTRAINT `ordenes_produccion_ibfk_4` FOREIGN KEY (`id_estado`) REFERENCES `estados_produccion` (`id_estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentaciones` (
  `id_presentacion` int(11) NOT NULL AUTO_INCREMENT,
  `id_producto` int(11) NOT NULL,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `precio_base` decimal(8,2) NOT NULL,
  `num_sabores` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT 'Sabores a elegir en esta presentación (si el producto requiere sabor)',
  `activo` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = oculto en el POS (se conserva para el historial de ventas)',
  PRIMARY KEY (`id_presentacion`),
  KEY `id_producto` (`id_producto`),
  CONSTRAINT `presentaciones_ibfk_1` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos` (
  `id_producto` int(11) NOT NULL AUTO_INCREMENT,
  `id_categoria` int(11) NOT NULL,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requiere_sabor` tinyint(1) DEFAULT 0,
  `tipo_sabor` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Tipo de sabores que se pueden elegir (sabores.tipo_sabor)',
  `activo` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = oculto en el POS (se conserva para el historial de ventas)',
  PRIMARY KEY (`id_producto`),
  KEY `id_categoria` (`id_categoria`),
  CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`id_categoria`) REFERENCES `categorias` (`id_categoria`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos_extras` (
  `id_producto` int(11) NOT NULL,
  `id_extra` int(11) NOT NULL,
  PRIMARY KEY (`id_producto`,`id_extra`),
  KEY `productos_extras_id_extra_foreign` (`id_extra`),
  CONSTRAINT `productos_extras_id_extra_foreign` FOREIGN KEY (`id_extra`) REFERENCES `extras_toppings` (`id_extra`) ON DELETE CASCADE,
  CONSTRAINT `productos_extras_id_producto_foreign` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Extras/toppings que se ofrecen en cada producto';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promociones` (
  `id_promocion` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `precio_combo` decimal(8,2) NOT NULL,
  `dia_semana` int(11) DEFAULT NULL,
  `activa` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id_promocion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recargos_sabores` (
  `id_recargo` int(11) NOT NULL AUTO_INCREMENT,
  `id_sabor` int(11) NOT NULL,
  `id_presentacion` int(11) NOT NULL,
  `monto_recargo` decimal(8,2) NOT NULL,
  PRIMARY KEY (`id_recargo`),
  KEY `id_sabor` (`id_sabor`),
  KEY `id_presentacion` (`id_presentacion`),
  CONSTRAINT `recargos_sabores_ibfk_1` FOREIGN KEY (`id_sabor`) REFERENCES `sabores` (`id_sabor`) ON DELETE CASCADE,
  CONSTRAINT `recargos_sabores_ibfk_2` FOREIGN KEY (`id_presentacion`) REFERENCES `presentaciones` (`id_presentacion`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id_rol` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_rol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sabores` (
  `id_sabor` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_sabor` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `es_especial` tinyint(1) DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = oculto en el POS (se conserva para el historial de ventas)',
  PRIMARY KEY (`id_sabor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sucursales` (
  `id_sucursal` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(20) NOT NULL COMMENT 'Clave visible, p. ej. SUC-001',
  `nombre` varchar(100) NOT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `correo` varchar(120) DEFAULT NULL,
  `id_encargado` int(11) DEFAULT NULL,
  `hora_apertura` time DEFAULT NULL,
  `hora_cierre` time DEFAULT NULL,
  `venta_mostrador` tinyint(1) NOT NULL DEFAULT 1,
  `para_llevar` tinyint(1) NOT NULL DEFAULT 1,
  `apps_delivery` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Rappi / Uber Eats',
  `drive_thru` tinyint(1) NOT NULL DEFAULT 0,
  `es_matriz` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id_sucursal`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `sucursales_id_encargado_foreign` (`id_encargado`),
  CONSTRAINT `sucursales_id_encargado_foreign` FOREIGN KEY (`id_encargado`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `terminales` (
  `id_terminal` int(11) NOT NULL AUTO_INCREMENT,
  `id_sucursal` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL COMMENT 'p. ej. Caja #01 Principal',
  `tipo` enum('CAJA','TABLET','KIOSKO') NOT NULL DEFAULT 'CAJA',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_terminal`),
  KEY `terminales_id_sucursal_foreign` (`id_sucursal`),
  CONSTRAINT `terminales_id_sucursal_foreign` FOREIGN KEY (`id_sucursal`) REFERENCES `sucursales` (`id_sucursal`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `unidades_empaque` (
  `id_empaque` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `equivalencia_porciones` int(11) NOT NULL,
  PRIMARY KEY (`id_empaque`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuarios` (
  `id_usuario` int(11) NOT NULL AUTO_INCREMENT,
  `id_rol` int(11) NOT NULL,
  `id_sucursal` int(11) DEFAULT NULL COMMENT 'Sucursal donde trabaja',
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `usuario` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `ultimo_acceso` datetime DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id_usuario`),
  UNIQUE KEY `usuario` (`usuario`),
  KEY `id_rol` (`id_rol`),
  KEY `fk_usuarios_sucursal` (`id_sucursal`),
  CONSTRAINT `fk_usuarios_sucursal` FOREIGN KEY (`id_sucursal`) REFERENCES `sucursales` (`id_sucursal`) ON DELETE SET NULL,
  CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`id_rol`) REFERENCES `roles` (`id_rol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ventas` (
  `id_venta` int(11) NOT NULL AUTO_INCREMENT,
  `folio_orden` int(10) unsigned DEFAULT NULL COMMENT 'Número de orden que ve el cliente en el POS',
  `id_corte` int(11) DEFAULT NULL COMMENT 'Turno de caja (cortes_caja)',
  `id_usuario_vendedor` int(11) NOT NULL,
  `fecha_hora` datetime DEFAULT current_timestamp(),
  `cliente` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_servicio` enum('AQUI','LLEVAR') COLLATE utf8mb4_unicode_ci DEFAULT 'LLEVAR',
  `id_metodo_pago` int(11) NOT NULL,
  `subtotal` decimal(10,2) DEFAULT 0.00,
  `descuento` decimal(10,2) DEFAULT 0.00,
  `descuento_concepto` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total` decimal(10,2) NOT NULL,
  `monto_recibido` decimal(10,2) DEFAULT NULL COMMENT 'Efectivo entregado por el cliente',
  `cambio` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id_venta`),
  KEY `id_metodo_pago` (`id_metodo_pago`),
  KEY `fk_ventas_usuario` (`id_usuario_vendedor`),
  KEY `fk_ventas_corte` (`id_corte`),
  CONSTRAINT `fk_ventas_corte` FOREIGN KEY (`id_corte`) REFERENCES `cortes_caja` (`id_corte`) ON DELETE SET NULL,
  CONSTRAINT `fk_ventas_usuario` FOREIGN KEY (`id_usuario_vendedor`) REFERENCES `usuarios` (`id_usuario`),
  CONSTRAINT `ventas_ibfk_1` FOREIGN KEY (`id_metodo_pago`) REFERENCES `metodos_pago` (`id_metodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

