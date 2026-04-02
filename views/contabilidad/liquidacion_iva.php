<?php
// views/contabilidad/liquidacion_iva.php
require_once __DIR__ . '/../../config/db_connect.php';

// MEJORA #7 — Filtro de período: año + trimestre (en lugar de mostrar siempre todo)
$anio      = (int)($_GET['anio']      ?? date('Y'));
$trimestre = (int)($_GET['trimestre'] ?? 0); // 0 = anual, 1-4 = trimestre

// Calcular fechas del período seleccionado
if ($trimestre >= 1 && $trimestre <= 4) {
    $mes_inicio = ($trimestre - 1) * 3 + 1;
    $mes_fin    = $mes_inicio + 2;
    $desde = sprintf('%04d-%02d-01', $anio, $mes_inicio);
    $hasta = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $anio, $mes_fin)));
    $periodo_label = "T$trimestre $anio";
} else {
    $desde = "$anio-01-01";
    $hasta = "$anio-12-31";
    $periodo_label = "Año $anio";
}

// ⚠️ AJUSTE PARA MOSTRAR TODO SI EL PERIODO ES FUTURO (Para depuración del usuario)
// Si el usuario está en 2026 pero tiene facturas en 2024, no las verá a menos que cambie el año.
// No cambiamos la lógica de fechas, pero nos aseguramos de que el KPI sume correctamente lo filtrado.

// --- 1. IVA REPERCUTIDO (Ventas) ---
$rep_facturas = $pdo->prepare("SELECT COALESCE(SUM(cuota_iva),0) as iva, COALESCE(SUM(base_imponible),0) as base FROM facturas WHERE tipo = 'emitida' AND fecha_emision BETWEEN ? AND ?");
$rep_facturas->execute([$desde, $hasta]);
$res_f = $rep_facturas->fetch();
$iva_rep_f = (float)$res_f['iva'];
$base_rep_f = (float)$res_f['base'];

$rep_diario = $pdo->prepare(
    "SELECT COALESCE(SUM(ld.haber), 0) AS iva
     FROM libro_diario ld
     JOIN cuentas_contables cc ON ld.cuenta_id = cc.id
     WHERE (cc.codigo_pgc LIKE '477%' OR (cc.codigo_pgc LIKE '47%' AND ld.haber > 0))
       AND ld.concepto NOT LIKE 'Factura emitida nº%'
       AND ld.concepto NOT LIKE 'Factura recibida nº%'
       AND ld.fecha BETWEEN ? AND ?"
);
$rep_diario->execute([$desde, $hasta]);
$iva_rep_d = (float)$rep_diario->fetchColumn();

$iva_repercutido = $iva_rep_f + $iva_rep_d;

// --- 2. IVA SOPORTADO (Compras/Gastos) ---
$sop_facturas = $pdo->prepare("SELECT COALESCE(SUM(cuota_iva),0) as iva FROM facturas WHERE tipo = 'recibida' AND fecha_emision BETWEEN ? AND ?");
$sop_facturas->execute([$desde, $hasta]);
$iva_sop_f = (float)$sop_facturas->fetchColumn();

$sop_diario = $pdo->prepare(
    "SELECT COALESCE(SUM(ld.debe), 0) AS iva
     FROM libro_diario ld
     JOIN cuentas_contables cc ON ld.cuenta_id = cc.id
     WHERE (cc.codigo_pgc LIKE '472%' OR (cc.codigo_pgc LIKE '47%' AND ld.debe > 0))
       AND ld.concepto NOT LIKE 'Factura emitida nº%'
       AND ld.concepto NOT LIKE 'Factura recibida nº%'
       AND ld.fecha BETWEEN ? AND ?"
);
$sop_diario->execute([$desde, $hasta]);
$iva_sop_d = (float)$sop_diario->fetchColumn();

$iva_soportado = $iva_sop_f + $iva_sop_d;

$resultado_iva   = $iva_repercutido - $iva_soportado;
$hay_datos       = $iva_repercutido > 0 || $iva_soportado > 0;

// --- Detalle facturas EMITIDAS ---
$stmt_emitidas = $pdo->prepare(
    "SELECT f.numero_serie, f.numero_factura, f.fecha_emision,
            f.base_imponible, f.cuota_iva, f.total, c.nombre_fiscal
     FROM facturas f
     JOIN clientes c ON f.cliente_id = c.id
     WHERE f.tipo = 'emitida' AND f.fecha_emision BETWEEN ? AND ?
     ORDER BY f.fecha_emision DESC"
);
$stmt_emitidas->execute([$desde, $hasta]);
$emitidas = $stmt_emitidas->fetchAll();

// --- Detalle facturas RECIBIDAS ---
$stmt_recibidas = $pdo->prepare(
    "SELECT f.numero_serie, f.numero_factura, f.fecha_emision,
            f.base_imponible, f.cuota_iva, f.total, c.nombre_fiscal
     FROM facturas f
     JOIN clientes c ON f.cliente_id = c.id
     WHERE f.tipo = 'recibida' AND f.fecha_emision BETWEEN ? AND ?
     ORDER BY f.fecha_emision DESC"
);
$stmt_recibidas->execute([$desde, $hasta]);
$recibidas = $stmt_recibidas->fetchAll();

// --- Detalle asientos de IVA (Diario) EXCLUYENDO facturas automáticas ---
$stmt_asientos = $pdo->prepare(
    "SELECT ld.fecha, ld.numero_asiento, ld.concepto, ld.debe, ld.haber, cc.codigo_pgc
     FROM libro_diario ld
     JOIN cuentas_contables cc ON ld.cuenta_id = cc.id
     WHERE cc.codigo_pgc LIKE '47%'
       AND ld.concepto NOT LIKE 'Factura emitida nº%'
       AND ld.concepto NOT LIKE 'Factura recibida nº%'
       AND ld.fecha BETWEEN ? AND ?
     ORDER BY ld.fecha DESC"
);
$stmt_asientos->execute([$desde, $hasta]);
$asientos_iva = $stmt_asientos->fetchAll();

// --- Detalle facturas del período ---
$stmt_detalle = $pdo->prepare(
    "SELECT f.numero_serie, f.numero_factura, f.fecha_emision,
            f.base_imponible, f.cuota_iva, f.total, c.nombre_fiscal
     FROM facturas f
     JOIN clientes c ON f.cliente_id = c.id
     WHERE f.fecha_emision BETWEEN ? AND ?
     ORDER BY f.fecha_emision DESC, f.numero_factura DESC"
);
$stmt_detalle->execute([$desde, $hasta]);
$detalle = $stmt_detalle->fetchAll();

// Años disponibles para el selector
$anio_actual = (int)date('Y');
$anios       = range($anio_actual, $anio_actual - 4);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liquidación IVA - ERP Financiero</title>
    <style><?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?></style>
    
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container">
    <h1>📊 Liquidación de IVA</h1>

    <!-- Filtro de período -->
    <form method="GET" class="filtros">
        <div class="campo">
            <label for="anio">Año</label>
            <select name="anio" id="anio">
                <?php foreach ($anios as $a): ?>
                    <option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label for="trimestre">Trimestre</label>
            <select name="trimestre" id="trimestre">
                <option value="0" <?= $trimestre === 0 ? 'selected' : '' ?>>Anual completo</option>
                <option value="1" <?= $trimestre === 1 ? 'selected' : '' ?>>T1 — Ene / Mar</option>
                <option value="2" <?= $trimestre === 2 ? 'selected' : '' ?>>T2 — Abr / Jun</option>
                <option value="3" <?= $trimestre === 3 ? 'selected' : '' ?>>T3 — Jul / Sep</option>
                <option value="4" <?= $trimestre === 4 ? 'selected' : '' ?>>T4 — Oct / Dic</option>
            </select>
        </div>
        <button type="submit" class="btn-filtrar">Aplicar</button>
        <span class="periodo-badge">📅 <?= htmlspecialchars($periodo_label) ?></span>
    </form>

    <?php if (!$hay_datos): ?>
        <div class="empty-state">
            <span class="icono">📭</span>
            <h2>No hay liquidación disponible</h2>
            <p>No se han registrado movimientos de IVA en el período <strong><?= htmlspecialchars($periodo_label) ?></strong>.</p>
            <a href="<?= URL_BASE ?>views/facturacion/crear_factura.php" class="btn-accion">
                + Crear factura
            </a>
        </div>

    <?php else:
        $clase = $resultado_iva > 0 ? 'pagar' : ($resultado_iva < 0 ? 'devolver' : 'cero');
        $texto = $resultado_iva > 0
            ? '⚠️ Resultado: A PAGAR a Hacienda ' . number_format($resultado_iva, 2, ',', '.') . ' €'
            : ($resultado_iva < 0
                ? '✅ Resultado: A DEVOLVER por Hacienda ' . number_format(abs($resultado_iva), 2, ',', '.') . ' €'
                : '✅ IVA liquidado: cuadrado a cero');
    ?>
        <div class="aviso <?= $clase ?>"><?= $texto ?></div>

        <div class="kpi-grid">
            <div class="kpi kpi-base">
                <h3>Base Imponible (Ventas)</h3>
                <p><?= number_format($base_rep_f, 2, ',', '.') ?> €</p>
                <small>Solo facturas emitidas</small>
            </div>
            <div class="kpi kpi-rep">
                <h3>IVA Repercutido (Ventas)</h3>
                <p><?= number_format($iva_repercutido, 2, ',', '.') ?> €</p>
                <small>Facturas + Diario</small>
            </div>
            <div class="kpi kpi-sop">
                <h3>IVA Soportado (Gastos)</h3>
                <p><?= number_format($iva_soportado, 2, ',', '.') ?> €</p>
                <small>Facturas + Diario</small>
            </div>
            <div class="kpi kpi-res <?= $clase ?>">
                <h3>Resultado Liquidación</h3>
                <p><?= number_format($resultado_iva, 2, ',', '.') ?> €</p>
                <small>Diferencia a liquidar</small>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2rem;">
            <div>
                <h2>� Facturas Emitidas</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Factura</th><th>Cliente</th><th class="text-right">IVA</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($emitidas)): ?>
                        <tr><td colspan="3" style="text-align:center;padding:20px;color:#aaa">Sin ventas.</td></tr>
                    <?php else: foreach ($emitidas as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['numero_serie']) ?>/<?= $row['numero_factura'] ?></td>
                            <td><?= htmlspecialchars($row['nombre_fiscal']) ?></td>
                            <td class="text-right bold"><?= number_format($row['cuota_iva'], 2, ',', '.') ?> €</td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <h2 style="margin-top:2rem;">📥 Facturas Recibidas</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Factura</th><th>Proveedor</th><th class="text-right">IVA</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recibidas)): ?>
                        <tr><td colspan="3" style="text-align:center;padding:20px;color:#aaa">Sin compras.</td></tr>
                    <?php else: foreach ($recibidas as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['numero_serie']) ?>/<?= $row['numero_factura'] ?></td>
                            <td><?= htmlspecialchars($row['nombre_fiscal']) ?></td>
                            <td class="text-right bold"><?= number_format($row['cuota_iva'], 2, ',', '.') ?> €</td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div>
                <h2>📖 Otros Apuntes de IVA (Diario)</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th><th>Concepto</th><th class="text-right">IVA</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($asientos_iva)): ?>
                        <tr><td colspan="3" style="text-align:center;padding:20px;color:#aaa">Sin otros apuntes.</td></tr>
                    <?php else: foreach ($asientos_iva as $row): 
                        $val = ($row['haber'] > 0) ? $row['haber'] : $row['debe'];
                        $tipo = ($row['haber'] > 0) ? '(Rep.)' : '(Sop.)';
                    ?>
                        <tr>
                            <td><?= date('d/m/y', strtotime($row['fecha'])) ?></td>
                            <td><small><?= htmlspecialchars($row['concepto']) ?></small></td>
                            <td class="text-right"><?= number_format($val, 2, ',', '.') ?> € <small><?= $tipo ?></small></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</div>
</body>
<?php // Busca la carpeta 'includes' partiendo de la base de Laragon de forma absoluta  
require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php';  ?>
</html>
