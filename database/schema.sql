-- =============================================================
--  schema.sql — ERP Financiero
--  Motor: MySQL 8+ / MariaDB 10.5+
--  Charset: utf8mb4 (soporte completo Unicode / emojis)
--
--  Uso:
--    mysql -u root -p < database/schema.sql
--  o desde el cliente:
--    source /ruta/al/proyecto/database/schema.sql
-- =============================================================

SET NAMES utf8mb4;
SET time_zone             = '+01:00';   -- España peninsular
SET foreign_key_checks    = 0;
SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- -------------------------------------------------------------
--  Base de datos
-- -------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS erp_financiero
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE erp_financiero;

-- =============================================================
--  1. PLAN GENERAL CONTABLE — cuentas_contables
--     Catálogo de cuentas del PGC español (simplificado).
--     Los grupos 4x, 57x, 6x, 7x son los mínimos operativos.
-- =============================================================
DROP TABLE IF EXISTS cuentas_contables;
CREATE TABLE cuentas_contables (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    codigo_pgc  VARCHAR(10)     NOT NULL COMMENT 'Código del Plan General Contable',
    descripcion VARCHAR(150)    NOT NULL,
    tipo        ENUM('activo','pasivo','patrimonio','ingreso','gasto') NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_codigo_pgc (codigo_pgc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de cuentas contables (PGC España)';

-- Cuentas mínimas necesarias para el funcionamiento del ERP
INSERT INTO cuentas_contables (codigo_pgc, descripcion, tipo) VALUES
-- Grupo 1 — Financiación básica
('100',  'Capital social',                          'patrimonio'),
('112',  'Reserva legal',                           'patrimonio'),
-- Grupo 4 — Acreedores y deudores
('400',  'Proveedores',                             'pasivo'),
('4000', 'Proveedores (general)',                   'pasivo'),
('4100', 'Acreedores comerciales',                  'pasivo'),
('430',  'Clientes',                                'activo'),
('4300', 'Clientes (general)',                      'activo'),
('460',  'Anticipos de remuneraciones',             'activo'),
('470',  'Hacienda Pública, deudora',               'activo'),
('4700', 'HP deudora por IVA',                      'activo'),
('472',  'HP IVA soportado',                        'activo'),
('4720', 'HP IVA soportado (general)',              'activo'),
('4721', 'HP IVA soportado (importaciones)',        'activo'),
('477',  'HP IVA repercutido',                      'pasivo'),
('4770', 'HP IVA repercutido (general)',             'pasivo'),
('480',  'Gastos anticipados',                      'activo'),
-- Grupo 5 — Cuentas financieras
('521',  'Deudas a corto plazo con entidades',      'pasivo'),
('570',  'Caja, euros',                             'activo'),
('572',  'Bancos e instituciones de crédito',       'activo'),
('5720', 'Banco cuenta corriente',                  'activo'),
('5721', 'Banco cuenta de ahorro',                  'activo'),
-- Grupo 6 — Compras y gastos
('600',  'Compras de mercaderías',                  'gasto'),
('6000', 'Compras (general)',                       'gasto'),
('621',  'Arrendamientos y cánones',                'gasto'),
('622',  'Reparaciones y conservación',             'gasto'),
('623',  'Servicios de profesionales independientes','gasto'),
('624',  'Transportes',                             'gasto'),
('625',  'Primas de seguros',                       'gasto'),
('626',  'Servicios bancarios y similares',         'gasto'),
('627',  'Publicidad, propaganda y RRPP',           'gasto'),
('628',  'Suministros',                             'gasto'),
('629',  'Otros servicios',                         'gasto'),
('640',  'Sueldos y salarios',                      'gasto'),
('642',  'Seguridad Social a cargo empresa',        'gasto'),
('660',  'Gastos financieros',                      'gasto'),
('680',  'Amortización del inmovilizado material',  'gasto'),
-- Grupo 7 — Ventas e ingresos
('700',  'Ventas de mercaderías',                   'ingreso'),
('7000', 'Ventas (general)',                        'ingreso'),
('705',  'Prestaciones de servicios',               'ingreso'),
('740',  'Subvenciones a la explotación',           'ingreso'),
('760',  'Ingresos de participaciones en capital',  'ingreso'),
('769',  'Otros ingresos financieros',              'ingreso');

-- =============================================================
--  2. CLIENTES
-- =============================================================
DROP TABLE IF EXISTS clientes;
CREATE TABLE clientes (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nombre_fiscal   VARCHAR(150)    NOT NULL,
    nif_cif         VARCHAR(20)     NOT NULL,
    email           VARCHAR(100)    NULL DEFAULT NULL,
    telefono        VARCHAR(20)     NULL DEFAULT NULL,
    direccion       VARCHAR(200)    NULL DEFAULT NULL,
    ciudad          VARCHAR(80)     NULL DEFAULT NULL,
    codigo_postal   VARCHAR(10)     NULL DEFAULT NULL,
    provincia       VARCHAR(80)     NULL DEFAULT NULL,
    pais            VARCHAR(60)     NOT NULL DEFAULT 'España',
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nif_cliente (nif_cif),
    KEY idx_nombre_fiscal (nombre_fiscal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Clientes de la empresa';

-- =============================================================
--  3. PROVEEDORES
-- =============================================================
DROP TABLE IF EXISTS proveedores;
CREATE TABLE proveedores (
    id               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    nombre_fiscal    VARCHAR(150)   NOT NULL,
    nif_cif          VARCHAR(20)    NOT NULL,
    email            VARCHAR(100)   NULL DEFAULT NULL,
    telefono         VARCHAR(20)    NULL DEFAULT NULL,
    direccion        VARCHAR(200)   NULL DEFAULT NULL,
    ciudad           VARCHAR(80)    NULL DEFAULT NULL,
    codigo_postal    VARCHAR(10)    NULL DEFAULT NULL,
    provincia        VARCHAR(80)    NULL DEFAULT NULL,
    pais             VARCHAR(60)    NOT NULL DEFAULT 'España',
    cuenta_contable  VARCHAR(10)    NOT NULL DEFAULT '4000'
                         COMMENT 'Código PGC: 4000 Proveedores / 4100 Acreedores',
    created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nif_proveedor (nif_cif),
    KEY idx_nombre_proveedor (nombre_fiscal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Proveedores y acreedores';

-- =============================================================
--  4. LIBRO DIARIO
--     Cada fila es una línea de un asiento contable.
--     Un asiento completo = varias filas con el mismo
--     numero_asiento donde SUM(debe) == SUM(haber).
-- =============================================================
DROP TABLE IF EXISTS libro_diario;
CREATE TABLE libro_diario (
    id              INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    fecha           DATE                NOT NULL,
    numero_asiento  INT UNSIGNED        NOT NULL COMMENT 'Correlativo anual del asiento',
    cuenta_id       INT UNSIGNED        NOT NULL,
    concepto        VARCHAR(300)        NOT NULL,
    debe            DECIMAL(15,2)       NOT NULL DEFAULT 0.00,
    haber           DECIMAL(15,2)       NOT NULL DEFAULT 0.00,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fecha           (fecha),
    KEY idx_numero_asiento  (numero_asiento),
    KEY idx_cuenta_id       (cuenta_id),
    CONSTRAINT fk_diario_cuenta
        FOREIGN KEY (cuenta_id) REFERENCES cuentas_contables(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Libro Diario de asientos contables (partida doble)';

-- =============================================================
--  5. FACTURAS (cabecera)
-- =============================================================
DROP TABLE IF EXISTS facturas;
CREATE TABLE facturas (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    cliente_id      INT UNSIGNED    NOT NULL,
    numero_serie    VARCHAR(10)     NOT NULL COMMENT 'Año de emisión, ej: 2025',
    numero_factura  INT UNSIGNED    NOT NULL COMMENT 'Correlativo dentro de la serie',
    fecha_emision   DATE            NOT NULL,
    base_imponible  DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    cuota_iva       DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    total           DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    numero_asiento  INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Asiento contable generado',
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_serie_numero (numero_serie, numero_factura),
    KEY idx_fecha_emision (fecha_emision),
    KEY idx_cliente_id    (cliente_id),
    CONSTRAINT fk_factura_cliente
        FOREIGN KEY (cliente_id) REFERENCES clientes(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cabeceras de facturas emitidas';

-- =============================================================
--  6. LÍNEAS DE FACTURA
-- =============================================================
DROP TABLE IF EXISTS lineas_factura;
CREATE TABLE lineas_factura (
    id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    factura_id       INT UNSIGNED    NOT NULL,
    descripcion      VARCHAR(300)    NOT NULL,
    cantidad         DECIMAL(10,2)   NOT NULL DEFAULT 1.00,
    precio_unitario  DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    tipo_iva         DECIMAL(5,2)    NOT NULL DEFAULT 21.00 COMMENT 'Porcentaje de IVA aplicado',
    subtotal         DECIMAL(15,2)   NOT NULL DEFAULT 0.00 COMMENT 'cantidad × precio_unitario',
    cuota_iva        DECIMAL(15,2)   NOT NULL DEFAULT 0.00 COMMENT 'subtotal × tipo_iva / 100',
    total            DECIMAL(15,2)   NOT NULL DEFAULT 0.00 COMMENT 'subtotal + cuota_iva',
    PRIMARY KEY (id),
    KEY idx_factura_id (factura_id),
    CONSTRAINT fk_linea_factura
        FOREIGN KEY (factura_id) REFERENCES facturas(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Líneas de detalle de cada factura';

-- =============================================================
--  7. TICKETS DE COMPRA (facturas de proveedor / gastos)
-- =============================================================
DROP TABLE IF EXISTS tickets_compra;
CREATE TABLE tickets_compra (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    proveedor_id    INT UNSIGNED    NULL DEFAULT NULL,
    fecha           DATE            NOT NULL,
    concepto        VARCHAR(300)    NOT NULL,
    base_imponible  DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    cuota_iva       DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    total           DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    archivo         VARCHAR(500)    NULL DEFAULT NULL COMMENT 'Ruta del archivo subido (imagen/PDF)',
    numero_asiento  INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Asiento contable generado',
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fecha_ticket     (fecha),
    KEY idx_proveedor_ticket (proveedor_id),
    CONSTRAINT fk_ticket_proveedor
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tickets y facturas de compra recibidas (con OCR opcional)';

-- =============================================================
--  Restaurar configuración
-- =============================================================
SET foreign_key_checks = 1;

-- =============================================================
--  DATOS DE EJEMPLO
--  Suficientes para arrancar el dashboard con datos reales.
--  Elimina este bloque en producción o crea un archivo separado.
-- =============================================================

-- --- Clientes de ejemplo ---
INSERT INTO clientes (nombre_fiscal, nif_cif, email, telefono, ciudad, codigo_postal, provincia, pais) VALUES
('Acme Soluciones S.L.',     'B12345678', 'contabilidad@acme.es',    '954 111 222', 'Sevilla',   '41001', 'Sevilla',  'España'),
('Distribuciones Norte S.A.','A87654321', 'admin@disnorte.com',      '918 333 444', 'Madrid',    '28001', 'Madrid',   'España'),
('TechPyme Digital S.L.',    'B11223344', 'facturacion@techpyme.io', '932 555 666', 'Barcelona', '08001', 'Barcelona','España');

-- --- Proveedores de ejemplo ---
INSERT INTO proveedores (nombre_fiscal, nif_cif, email, telefono, ciudad, codigo_postal, provincia, pais, cuenta_contable) VALUES
('Suministros Andalucía S.L.', 'B99887766', 'pedidos@suministros-and.es', '955 777 888', 'Sevilla', '41002', 'Sevilla', 'España', '4000'),
('Hosting & Cloud Spain S.A.', 'A55443322', 'facturas@hcs.es',            '916 999 000', 'Madrid',  '28013', 'Madrid',  'España', '4100');

-- --- Asiento de apertura de caja / banco ---
INSERT INTO libro_diario (fecha, numero_asiento, cuenta_id, concepto, debe, haber) VALUES
('2025-01-01', 1,
    (SELECT id FROM cuentas_contables WHERE codigo_pgc = '5720'),
    'Asiento de apertura — Saldo inicial banco', 15000.00, 0.00),
('2025-01-01', 1,
    (SELECT id FROM cuentas_contables WHERE codigo_pgc = '100'),
    'Asiento de apertura — Capital social', 0.00, 15000.00);

-- --- Factura de ejemplo ---
INSERT INTO facturas (cliente_id, numero_serie, numero_factura, fecha_emision, base_imponible, cuota_iva, total, numero_asiento)
VALUES (1, '2025', 1, '2025-03-15', 1000.00, 210.00, 1210.00, 2);

INSERT INTO lineas_factura (factura_id, descripcion, cantidad, precio_unitario, tipo_iva, subtotal, cuota_iva, total)
VALUES (1, 'Consultoría de sistemas ERP', 10.00, 100.00, 21.00, 1000.00, 210.00, 1210.00);

-- Asiento contable generado al crear la factura
INSERT INTO libro_diario (fecha, numero_asiento, cuenta_id, concepto, debe, haber) VALUES
('2025-03-15', 2,
    (SELECT id FROM cuentas_contables WHERE codigo_pgc = '4300'),
    'Fra. 2025/1 — Acme Soluciones S.L.', 1210.00, 0.00),
('2025-03-15', 2,
    (SELECT id FROM cuentas_contables WHERE codigo_pgc = '7000'),
    'Fra. 2025/1 — Acme Soluciones S.L.', 0.00, 1000.00),
('2025-03-15', 2,
    (SELECT id FROM cuentas_contables WHERE codigo_pgc = '4770'),
    'Fra. 2025/1 — IVA repercutido 21%', 0.00, 210.00);

-- --- Gasto de proveedor (suministros) ---
INSERT INTO libro_diario (fecha, numero_asiento, cuenta_id, concepto, debe, haber) VALUES
('2025-03-20', 3,
    (SELECT id FROM cuentas_contables WHERE codigo_pgc = '6000'),
    'Compra material de oficina — Suministros Andalucía', 200.00, 0.00),
('2025-03-20', 3,
    (SELECT id FROM cuentas_contables WHERE codigo_pgc = '4720'),
    'IVA soportado 21% — Suministros Andalucía', 42.00, 0.00),
('2025-03-20', 3,
    (SELECT id FROM cuentas_contables WHERE codigo_pgc = '4000'),
    'Compra material de oficina — proveedor', 0.00, 242.00);

-- =============================================================
--  Verificación rápida de cuadre
-- =============================================================
SELECT
    'VERIFICACIÓN CUADRE LIBRO DIARIO' AS info,
    ROUND(SUM(debe),  2) AS total_debe,
    ROUND(SUM(haber), 2) AS total_haber,
    ROUND(SUM(debe) - SUM(haber), 2) AS diferencia,
    IF(ABS(SUM(debe) - SUM(haber)) < 0.01, '✅ CUADRADO', '❌ DESCUADRE') AS estado
FROM libro_diario;