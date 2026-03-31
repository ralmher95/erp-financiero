-- database/seeders/cuentas_pgc.sql
USE `erp_financiero`;

INSERT INTO `cuentas_contables` (`codigo_pgc`, `descripcion`, `tipo`) VALUES
-- GRUPO 1 — Financiación básica (Patrimonio Neto y Pasivo)
('1000', 'Capital social, nominal emitido', 'patrimonio'),
('1001', 'Capital social, llamado pero no cobrado', 'patrimonio'),
('1020', 'Capital suscrito no desembolsado', 'patrimonio'),
('1030', 'Capital suscrito pendiente de inscripción', 'patrimonio'),
('1100', 'Prima de emisión', 'patrimonio'),
('1120', 'Reserva legal', 'patrimonio'),
('1130', 'Reservas voluntarias', 'patrimonio'),
('1140', 'Reservas estatutarias', 'patrimonio'),
('1200', 'Remanente de reservas', 'patrimonio'),
('1290', 'Resultado del ejercicio', 'patrimonio'),
('1300', 'Subvenciones oficiales de capital', 'patrimonio'),

-- GRUPO 2 — Inmovilizado (Activo no corriente)
('2000', 'Terrenos y bienes naturales', 'activo'),
('2010', 'Construcciones', 'activo'),
('2180', 'Maquinaria', 'activo'),
('2185', 'Utillaje', 'activo'),
('2200', 'Elementos de transporte', 'activo'),
('2280', 'Mobiliario', 'activo'),
('2290', 'Equipos informáticos', 'activo'),
('2810', 'Amortización acumulada inmovilizado material', 'pasivo'), -- Cuenta compensatoria

-- GRUPO 3 — Existencias (Activo corriente)
('3000', 'Mercaderías', 'activo'),
('3100', 'Materias primas', 'activo'),
('3200', 'Productos acabados', 'activo'),

-- GRUPO 4 — Acreedores y deudores (Pasivo y Activo circulante)
('4000', 'Proveedores', 'pasivo'),
('4001', 'Efectos comerciales a pagar - Proveedores', 'pasivo'),
('4100', 'Acreedores comerciales por servicios', 'pasivo'),
('4300', 'Clientes', 'activo'),
('4301', 'Efectos comerciales a cobrar - Clientes', 'activo'),
('4360', 'Clientes dudoso cobro', 'activo'),
('4400', 'Deudores varios', 'activo'),
('4700', 'Hacienda Pública, acreedora', 'pasivo'),
('4701', 'Hacienda Pública, IVA repercutido', 'pasivo'),
('4720', 'Hacienda Pública, IVA soportado', 'activo'),
('4721', 'Hacienda Pública, IVA deducible', 'activo'),
('4770', 'Hacienda Pública, retenciones y pagos a cuenta', 'pasivo'),

-- GRUPO 5 — Cuentas financieras
('5200', 'Deudas con entidades de crédito', 'pasivo'),
('5700', 'Caja', 'activo'),
('5720', 'Bancos c/c vista', 'activo'),
('5721', 'Bancos, euros', 'activo'),
('5730', 'Bancos a plazo fijo', 'activo'),

-- GRUPO 6 — Gastos
('6000', 'Compras de mercaderías', 'gasto'),
('6010', 'Compras de materias primas', 'gasto'),
('6060', 'Descuentos sobre compras', 'ingreso'), -- Cuenta compensatoria de gasto
('6090', 'Otros aprovisionamientos', 'gasto'),
('6400', 'Sueldos y salarios', 'gasto'),
('6420', 'Seguridad Social a cargo empresa', 'gasto'),
('6440', 'Gastos de personal no retribuidos', 'gasto'),
('6490', 'Otros gastos de gestión corriente', 'gasto'),
('6800', 'Amortización del inmovilizado intangible', 'gasto'),
('6810', 'Amortización del inmovilizado material', 'gasto'),

-- GRUPO 7 — Ingresos
('7000', 'Ventas de mercaderías', 'ingreso'),
('7060', 'Descuentos sobre ventas', 'gasto'), -- Cuenta compensatoria de ingreso
('7080', 'Devoluciones de ventas', 'gasto'), -- Cuenta compensatoria de ingreso
('7090', 'Prestaciones de servicios', 'ingreso'),
('7700', 'Producción vendida bienes propios', 'ingreso'),
('7800', 'Servicios ejecutados para terceros', 'ingreso')
ON DUPLICATE KEY UPDATE `descripcion` = VALUES(`descripcion`), `tipo` = VALUES(`tipo`);