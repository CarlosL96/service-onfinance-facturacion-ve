-- =====================================================================
-- TABLAS DE INTEGRACIÓN Y REGISTRO FISCAL - VENEZUELA (TFHKA / SENIAT)
-- =====================================================================

-- 1. Tabla de Encabezado Fiscal (offve001)
CREATE TABLE IF NOT EXISTS `offve001` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `factura_id` INT UNSIGNED NOT NULL COMMENT 'Relación con la tabla principal de facturas de tu ERP',
  `tipo_documento` VARCHAR(2) NOT NULL COMMENT '01 = Factura, 02 = Nota de Crédito, 03 = Nota de Débito',
  `numero_documento` VARCHAR(30) NOT NULL COMMENT 'Número correlativo enviado a la API',
  `numero_control` VARCHAR(30) NULL DEFAULT NULL COMMENT 'Número de control asignado por el SENIAT',
  `estatus_fiscal` VARCHAR(20) NOT NULL DEFAULT 'Procesado' COMMENT 'Procesado, Anulado, Error',
  `fecha_asignacion` DATETIME NULL DEFAULT NULL COMMENT 'Fecha y hora de registro de la firma fiscal',
  `url_consulta` TEXT NULL DEFAULT NULL COMMENT 'URL devuelta por TFHKA para consultar el documento en línea',
  `mensaje_fiscal` TEXT NULL DEFAULT NULL COMMENT 'Mensaje o error detallado retornado por la API',
  `pdf_base64` LONGTEXT NULL DEFAULT NULL COMMENT 'Copia del archivo PDF digitalizado en formato Base64',
  `creado_el` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  -- Índices de relación y optimización
  UNIQUE KEY `idx_offve001_factura_doc` (`factura_id`, `tipo_documento`),
  INDEX `idx_offve001_numero_control` (`numero_control`),
  INDEX `idx_offve001_estatus_fiscal` (`estatus_fiscal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Encabezado Fiscal de Facturación';

-- 2. Tabla de Detalle Fiscal (offve011)
CREATE TABLE IF NOT EXISTS `offve011` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `factura_fiscal_id` INT UNSIGNED NOT NULL COMMENT 'Relación con el encabezado fiscal offve001',
  `numero_linea` INT NOT NULL COMMENT 'Correlativo del ítem ("1", "2")',
  `indicador_bien_servicio` CHAR(1) NOT NULL COMMENT '1 = Bien, 2 = Servicio',
  `descripcion` VARCHAR(250) NOT NULL COMMENT 'Descripción física enviada',
  `cantidad` DECIMAL(12,4) NOT NULL COMMENT 'Cantidad de productos vendidos',
  `unidad_medida` VARCHAR(10) NOT NULL DEFAULT 'UNI' COMMENT 'Unidad de medida',
  `precio_unitario` DECIMAL(16,4) NOT NULL COMMENT 'Precio unitario base',
  `precio_item` DECIMAL(16,4) NOT NULL COMMENT 'Total base neto del ítem (Cantidad * Precio - Descuento)',
  `codigo_impuesto` VARCHAR(2) NOT NULL COMMENT 'Código de alícuota del IVA aplicado (ej: G)',
  `tasa_iva` DECIMAL(5,2) NOT NULL COMMENT 'Porcentaje de alícuota aplicado (ej: 16.00)',
  `valor_iva` DECIMAL(16,4) NOT NULL COMMENT 'Monto de IVA cobrado por este ítem',
  `valor_total_item` DECIMAL(16,4) NOT NULL COMMENT 'Monto total con IVA cobrado (precio_item + valor_iva)',
  
  -- Relaciones y llaves foráneas
  CONSTRAINT `fk_offve011_encabezado` 
    FOREIGN KEY (`factura_fiscal_id`) 
    REFERENCES `offve001` (`id`) 
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Detalle de Ítems Emitidos Fiscalmente';

-- 3. Tabla de Logs de Integración (ofint001)
CREATE TABLE IF NOT EXISTS `ofint001` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `fecha` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha del log',
  `origen` VARCHAR(50) NOT NULL DEFAULT 'Scriptcase' COMMENT 'Origen del evento (ej: Scriptcase, API_Node)',
  `endpoint` VARCHAR(100) NOT NULL COMMENT 'Ruta llamada (ej: /api/v1/emitir/factura)',
  `tipo_documento` VARCHAR(2) NULL DEFAULT NULL COMMENT '01, 02, 03, etc.',
  `numero_documento` VARCHAR(30) NULL DEFAULT NULL COMMENT 'Número identificador del documento',
  `referencia_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID de la factura en el ERP principal',
  `peticion_json` LONGTEXT NULL DEFAULT NULL COMMENT 'Cuerpo de la petición enviada',
  `respuesta_json` LONGTEXT NULL DEFAULT NULL COMMENT 'Respuesta devuelta por la API',
  `codigo_respuesta` VARCHAR(10) NULL DEFAULT NULL COMMENT 'Código HTTP o de negocio (ej: 200, 203, 400)',
  `exito` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Éxito, 0 = Fallo',
  
  -- Índices de consulta rápida
  INDEX `idx_ofint001_fecha` (`fecha`),
  INDEX `idx_ofint001_documento` (`tipo_documento`, `numero_documento`),
  INDEX `idx_ofint001_referencia` (`referencia_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Logs de Integración del Sistema de Facturación';
