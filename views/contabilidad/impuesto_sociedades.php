<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/db_connect.php';

$anio = (int)($_GET['anio'] ?? date('Y'));
$tipo_gravamen = 0.25; // 25% por defecto

// 1. Resultado antes de Impuestos (Ingresos - Gastos antes de cuenta 630)
// Ingresos (Grupo 7)
$stmt_ing = $pdo->prepare("SELECT COALESCE(SUM(ld.haber - ld.debe), 0) FROM libro_diario ld JOIN cuentas_contables cc ON ld.cuenta_id = cc.id WHERE cc.codigo_pgc LIKE '7%' AND YEAR(ld.fecha) = ?");
$stmt_ing->execute([$anio]);
$ingresos = (float)$stmt_ing->fetchColumn();

// Gastos (Grupo 6, excepto 630)
$stmt_gst = $pdo->prepare("SELECT COALESCE(SUM(ld.debe - ld.haber), 0) FROM libro_diario ld JOIN cuentas_contables cc ON ld.cuenta_id = cc.id WHERE cc.codigo_pgc LIKE '6%' AND cc.codigo_pgc NOT LIKE '630%' AND YEAR(ld.fecha) = ?");
$stmt_gst->execute([$anio]);
$gastos = (float)$stmt_gst->fetchColumn();

$resultado_contable = $ingresos - $gastos;

// 2. Diferencias Permanentes (Gastos marcados como NO deducibles)
$stmt_dif = $pdo->prepare("SELECT COALESCE(SUM(ld.debe - ld.haber), 0) FROM libro_diario ld JOIN cuentas_contables cc ON ld.cuenta_id = cc.id WHERE cc.codigo_pgc LIKE '6%' AND ld.es_deducible = 0 AND YEAR(ld.fecha) = ?");
$stmt_dif->execute([$anio]);
$gastos_no_deducibles = (float)$stmt_dif->fetchColumn();

// 3. Diferencias Temporarias (Simulación simplificada - se podrían añadir más metadatos)
$diferencias_temporarias = 0; // Por ahora 0, ampliable con ajustes manuales

// 4. Base Imponible
$base_imponible = $resultado_contable + $gastos_no_deducibles + $diferencias_temporarias;

// 5. Cuota Íntegra
$cuota_integra = $base_imponible > 0 ? $base_imponible * $tipo_gravamen : 0;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Impuesto sobre Sociedades - ERP Financiero</title>
    <style>
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?>
        .conciliacion { max-width: 800px; margin: 2rem auto; background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .row-conc { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .row-total { font-weight: 800; font-size: 1.2rem; border-top: 2px solid #1e293b; border-bottom: none; padding-top: 1rem; margin-top: 1rem; }
        .badge-fiscal { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container">
    <header style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1>🏦 Impuesto sobre Sociedades</h1>
            <p style="color: #64748b;">Conciliación del Resultado Contable a la Base Imponible.</p>
        </div>
        <form method="GET">
            <select name="anio" onchange="this.form.submit()" style="padding: 8px; border-radius: 6px;">
                <?php for($i=date('Y'); $i>=2020; $i--): ?>
                    <option value="<?= $i ?>" <?= $i === $anio ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </header>

    <div class="conciliacion">
        <div class="row-conc">
            <span>(+) Ingresos (Grupo 7)</span>
            <span><?= number_format($ingresos, 2, ',', '.') ?> €</span>
        </div>
        <div class="row-conc">
            <span>(-) Gastos (Grupo 6)</span>
            <span>- <?= number_format($gastos, 2, ',', '.') ?> €</span>
        </div>
        <div class="row-conc row-total">
            <span>Resultado antes de Impuestos</span>
            <span><?= number_format($resultado_contable, 2, ',', '.') ?> €</span>
        </div>

        <h3 style="margin-top: 2rem; color: #475569;">Ajustes Extracontables</h3>
        
        <div class="row-conc">
            <span>(+) Diferencias Permanentes (Gastos no deducibles) <span class="badge-fiscal">Multas, liberalidades...</span></span>
            <span style="color: #059669;">+ <?= number_format($gastos_no_deducibles, 2, ',', '.') ?> €</span>
        </div>
        <div class="row-conc">
            <span>(±) Diferencias Temporarias</span>
            <span><?= number_format($diferencias_temporarias, 2, ',', '.') ?> €</span>
        </div>

        <div class="row-conc row-total" style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 1rem;">
            <span>BASE IMPONIBLE FISCAL</span>
            <span style="color: #4f46e5;"><?= number_format($base_imponible, 2, ',', '.') ?> €</span>
        </div>

        <div class="row-conc" style="margin-top: 1rem;">
            <span>Tipo de Gravamen (General 25%)</span>
            <span>25,00 %</span>
        </div>
        
        <div class="row-conc row-total" style="background: #1e293b; color: white; border: none; padding: 1.5rem; border-radius: 8px;">
            <span>CUOTA ÍNTEGRA A PAGAR</span>
            <span style="color: #10b981; font-size: 1.5rem;"><?= number_format($cuota_integra, 2, ',', '.') ?> €</span>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php'; ?>
</body>
</html>
