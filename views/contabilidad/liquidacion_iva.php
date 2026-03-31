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

// --- IVA REPERCUTIDO (facturas emitidas en el período) ---
$rep = $pdo->prepare(
    "SELECT COALESCE(SUM(base_imponible),0) AS base,
            COALESCE(SUM(cuota_iva),0)      AS iva,
            COALESCE(SUM(total),0)          AS total,
            COUNT(id)                        AS num
     FROM facturas
     WHERE fecha_emision BETWEEN ? AND ?"
);
$rep->execute([$desde, $hasta]);
$rep = $rep->fetch();

// --- IVA SOPORTADO (compras en el período) desde cuentas 472x ---
$sop = $pdo->prepare(
    "SELECT COALESCE(SUM(ld.debe), 0) AS iva_soportado
     FROM libro_diario ld
     JOIN cuentas_contables cc ON ld.cuenta_id = cc.id
     WHERE cc.codigo_pgc IN ('4720','4721')
       AND ld.fecha BETWEEN ? AND ?"
);
$sop->execute([$desde, $hasta]);
$sop = $sop->fetch();

$iva_repercutido = (float)$rep['iva'];
$iva_soportado   = (float)$sop['iva_soportado'];
$resultado_iva   = $iva_repercutido - $iva_soportado;
$hay_datos       = $rep['num'] > 0;

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
            <label>Año</label>
            <select name="anio">
                <?php foreach ($anios as $a): ?>
                    <option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label>Trimestre</label>
            <select name="trimestre">
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
            <p>No se han registrado facturas en el período <strong><?= htmlspecialchars($periodo_label) ?></strong>.
               La liquidación de IVA se calculará automáticamente en cuanto generes facturas.</p>
            <!-- MEJORA #7: URL usando URL_BASE en lugar de hardcodeada -->
            <a href="<?= URL_BASE ?>views/facturacion/crear_factura.php" class="btn-accion">
                + Crear primera factura
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
                <h3>Base Imponible</h3>
                <p><?= number_format($rep['base'], 2, ',', '.') ?> €</p>
            </div>
            <div class="kpi kpi-rep">
                <h3>IVA Repercutido</h3>
                <p><?= number_format($iva_repercutido, 2, ',', '.') ?> €</p>
            </div>
            <div class="kpi kpi-sop">
                <h3>IVA Soportado</h3>
                <p><?= number_format($iva_soportado, 2, ',', '.') ?> €</p>
            </div>
            <div class="kpi kpi-res <?= $clase ?>">
                <h3>Resultado IVA</h3>
                <p><?= number_format($resultado_iva, 2, ',', '.') ?> €</p>
            </div>
        </div>

        <h2>📑 Detalle de Facturas Emitidas — <?= htmlspecialchars($periodo_label) ?></h2>
        <table>
            <thead>
                <tr>
                    <th>Factura</th><th>Cliente</th><th>Fecha</th>
                    <th class="text-right">Base Imponible</th>
                    <th class="text-right">IVA</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($detalle)): ?>
                <tr><td colspan="6" style="text-align:center;padding:20px;color:#aaa">Sin facturas en este período.</td></tr>
            <?php else: foreach ($detalle as $row): ?>
                <tr>
                    <td class="bold"><?= htmlspecialchars($row['numero_serie']) ?>/<?= $row['numero_factura'] ?></td>
                    <td><?= htmlspecialchars($row['nombre_fiscal']) ?></td>
                    <td><?= date('d/m/Y', strtotime($row['fecha_emision'])) ?></td>
                    <td class="text-right"><?= number_format($row['base_imponible'], 2, ',', '.') ?> €</td>
                    <td class="text-right"><?= number_format($row['cuota_iva'],      2, ',', '.') ?> €</td>
                    <td class="text-right bold"><?= number_format($row['total'],     2, ',', '.') ?> €</td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>
</body>
<?php // Busca la carpeta 'includes' partiendo de la base de Laragon de forma absoluta  
require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php';  ?>
</html>
