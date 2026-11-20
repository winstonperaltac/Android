-- =========================================================
--  Sistema de Préstamos - Esquema de base de datos (MySQL 5.7+ / MariaDB 10.3+)
-- =========================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Configuración general (nombre de empresa, logo, tasas) ----------
CREATE TABLE IF NOT EXISTS configuracion (
  clave VARCHAR(60) NOT NULL PRIMARY KEY,
  valor TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Usuarios y privilegios ----------
CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  nombre VARCHAR(120) NOT NULL,
  email VARCHAR(120) NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol ENUM('admin','supervisor','cajero','consulta') NOT NULL DEFAULT 'cajero',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  intentos_fallidos INT NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL,
  ultimo_acceso DATETIME NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuario_permisos (
  usuario_id INT UNSIGNED NOT NULL,
  permiso VARCHAR(60) NOT NULL,
  PRIMARY KEY (usuario_id, permiso),
  CONSTRAINT fk_up_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Clientes ----------
CREATE TABLE IF NOT EXISTS clientes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cedula VARCHAR(20) NOT NULL UNIQUE,
  nombres VARCHAR(100) NOT NULL,
  apellidos VARCHAR(100) NOT NULL,
  foto VARCHAR(255) NULL,
  direccion VARCHAR(255) NULL,
  telefono_trabajo VARCHAR(30) NULL,
  telefono_casa VARCHAR(30) NULL,
  celular VARCHAR(30) NULL,
  garante_nombre VARCHAR(150) NULL,
  garante_telefono VARCHAR(30) NULL,
  notas TEXT NULL,
  indice_credito SMALLINT NULL,
  indice_actualizado DATETIME NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_por INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cli_nombre (apellidos, nombres)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Préstamos ----------
-- tipo: 'redito' (sin cuotas, se paga rédito por periodo) | 'san' (cuotas fijas)
-- frecuencia: 'semanal' | 'quincenal'
CREATE TABLE IF NOT EXISTS prestamos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(20) NOT NULL UNIQUE,
  cliente_id INT UNSIGNED NOT NULL,
  tipo ENUM('redito','san') NOT NULL,
  frecuencia ENUM('semanal','quincenal') NOT NULL,
  monto DECIMAL(14,2) NOT NULL,              -- capital prestado
  capital_pendiente DECIMAL(14,2) NOT NULL,  -- capital aún adeudado
  tasa DECIMAL(6,2) NOT NULL,                -- rédito: % por periodo / san: % total aplicado
  num_cuotas INT NULL,                       -- san
  meses_cobrados INT NULL,                   -- san: meses usados para el interés
  interes_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  monto_cuota DECIMAL(14,2) NULL,            -- san
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NULL,
  estado ENUM('activo','saldado','cancelado','incobrable') NOT NULL DEFAULT 'activo',
  notas TEXT NULL,
  creado_por INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cerrado_en DATETIME NULL,
  INDEX idx_pre_estado (estado),
  CONSTRAINT fk_pre_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cuotas (san) y periodos de rédito (redito)
CREATE TABLE IF NOT EXISTS cuotas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  prestamo_id INT UNSIGNED NOT NULL,
  numero INT NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  monto DECIMAL(14,2) NOT NULL,          -- total de la cuota / rédito del periodo
  capital DECIMAL(14,2) NOT NULL DEFAULT 0,
  interes DECIMAL(14,2) NOT NULL DEFAULT 0,
  pagado DECIMAL(14,2) NOT NULL DEFAULT 0,
  estado ENUM('pendiente','pagada','pospuesta') NOT NULL DEFAULT 'pendiente',
  fecha_pago DATE NULL,
  dias_atraso INT NOT NULL DEFAULT 0,
  cuota_origen_id INT UNSIGNED NULL,     -- san: cuota que se movió al final tras pagar rédito
  INDEX idx_cuo_venc (fecha_vencimiento, estado),
  CONSTRAINT fk_cuo_prestamo FOREIGN KEY (prestamo_id) REFERENCES prestamos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pagos recibidos
-- tipo: cuota | redito_san | redito | abono_capital | saldo_total
CREATE TABLE IF NOT EXISTS pagos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recibo VARCHAR(20) NOT NULL UNIQUE,
  prestamo_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  monto DECIMAL(14,2) NOT NULL,
  capital DECIMAL(14,2) NOT NULL DEFAULT 0,       -- parte que recupera capital prestado
  interes DECIMAL(14,2) NOT NULL DEFAULT 0,       -- interés de cuota (50% ganancia / 50% reinversión)
  redito DECIMAL(14,2) NOT NULL DEFAULT 0,        -- rédito (100% ganancia)
  tipo VARCHAR(20) NOT NULL,
  detalle VARCHAR(255) NULL,
  aplicacion MEDIUMTEXT NULL,                     -- JSON: cómo se aplicó (permite anular)
  anulado TINYINT(1) NOT NULL DEFAULT 0,
  anulado_por INT UNSIGNED NULL,
  anulado_en DATETIME NULL,
  motivo_anulacion VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pag_fecha (fecha),
  CONSTRAINT fk_pag_prestamo FOREIGN KEY (prestamo_id) REFERENCES prestamos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Libro de movimientos financieros ----------
-- tipo: ganancia | reinversion | capital_recuperado | desembolso | gasto | sueldo | aporte | retiro
-- origen: redito | interes | ajuste ...
CREATE TABLE IF NOT EXISTS movimientos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  tipo VARCHAR(30) NOT NULL,
  origen VARCHAR(30) NULL,
  monto DECIMAL(14,2) NOT NULL,
  descripcion VARCHAR(255) NULL,
  prestamo_id INT UNSIGNED NULL,
  pago_id INT UNSIGNED NULL,
  gasto_id INT UNSIGNED NULL,
  nomina_id INT UNSIGNED NULL,
  usuario_id INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mov_tipo_fecha (tipo, fecha),
  INDEX idx_mov_prestamo (prestamo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Historial crediticio (alimenta el índice de crédito) ----------
-- evento: pago_puntual | pago_tardio | redito_parcial | prestamo_saldado | prestamo_incobrable
CREATE TABLE IF NOT EXISTS historial_crediticio (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  prestamo_id INT UNSIGNED NULL,
  cuota_id INT UNSIGNED NULL,
  pago_id INT UNSIGNED NULL,
  fecha DATE NOT NULL,
  evento VARCHAR(30) NOT NULL,
  dias_atraso INT NOT NULL DEFAULT 0,
  monto DECIMAL(14,2) NOT NULL DEFAULT 0,
  descripcion VARCHAR(255) NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hc_cliente (cliente_id, fecha),
  CONSTRAINT fk_hc_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Empleados y nómina ----------
CREATE TABLE IF NOT EXISTS empleados (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cedula VARCHAR(20) NULL,
  nombre VARCHAR(150) NOT NULL,
  cargo VARCHAR(100) NULL,
  telefono VARCHAR(30) NULL,
  sueldo DECIMAL(14,2) NOT NULL DEFAULT 0,
  frecuencia_pago ENUM('semanal','quincenal','mensual') NOT NULL DEFAULT 'quincenal',
  fecha_ingreso DATE NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nomina_pagos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empleado_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  periodo VARCHAR(60) NULL,
  monto DECIMAL(14,2) NOT NULL,
  notas VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_nom_emp FOREIGN KEY (empleado_id) REFERENCES empleados(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Gastos operativos ----------
CREATE TABLE IF NOT EXISTS gasto_categorias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conceptos prefijados: monto fijo o variable
CREATE TABLE IF NOT EXISTS gasto_conceptos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  categoria_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  es_variable TINYINT(1) NOT NULL DEFAULT 1,
  monto_fijo DECIMAL(14,2) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_gc_cat FOREIGN KEY (categoria_id) REFERENCES gasto_categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gastos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  categoria_id INT UNSIGNED NOT NULL,
  concepto_id INT UNSIGNED NULL,
  descripcion VARCHAR(255) NULL,
  monto DECIMAL(14,2) NOT NULL,
  usuario_id INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_gas_fecha (fecha),
  CONSTRAINT fk_gas_cat FOREIGN KEY (categoria_id) REFERENCES gasto_categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Auditoría (solo administrador) ----------
CREATE TABLE IF NOT EXISTS auditoria (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usuario_id INT UNSIGNED NULL,
  usuario VARCHAR(50) NULL,
  accion VARCHAR(40) NOT NULL,
  modulo VARCHAR(40) NOT NULL,
  registro_id VARCHAR(40) NULL,
  descripcion VARCHAR(255) NULL,
  datos_antes MEDIUMTEXT NULL,
  datos_despues MEDIUMTEXT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  INDEX idx_aud_fecha (fecha),
  INDEX idx_aud_usuario (usuario_id),
  INDEX idx_aud_modulo (modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- Datos iniciales ----------
INSERT IGNORE INTO configuracion (clave, valor) VALUES
 ('empresa_nombre', 'Mi Financiera'),
 ('empresa_rnc', ''),
 ('empresa_telefono', ''),
 ('empresa_direccion', ''),
 ('empresa_logo', ''),
 ('moneda', 'RD$'),
 ('redito_tasa', '10'),             -- % de rédito por periodo (semana/quincena)
 ('san_tasa_mensual', '10'),        -- % de interés mensual en san
 ('san_interes_minimo', '30'),      -- % mínimo total en san
 ('san_meses_minimo', '3'),         -- meses debajo de los cuales aplica el mínimo
 ('semanas_por_mes', '4'),          -- semanas que componen un mes para el cálculo
 ('porcentaje_reinversion', '50'),  -- % del interés de cuotas que va a capital
 ('dias_gracia', '0');              -- días de gracia antes de considerar atraso

INSERT IGNORE INTO gasto_categorias (nombre) VALUES
 ('Local'), ('Servicios'), ('Transporte'), ('Papelería'), ('Otros');
