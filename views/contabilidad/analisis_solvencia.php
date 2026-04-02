<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/db_connect.php';

$anio = (int)($_GET['anio'] ?? date('Y'));

/**
 * Cálculo del Fondo de Maniobra
 * FM = Activo Corriente - Pasivo Corriente
 */

function getSaldoGrupo($pdo, $prefijos, $anio) {
    $where = [];
    foreach ($prefijos as $p) $where[] = "cc.codigo_pgc LIKE '$p%'";
    $where_str = implode(' OR ', $where);
    
    $sql = "SELECT SUM(ld.debe - ld.haber) 
            FROM libro_diario ld 
            JOIN cuentas_contables cc ON ld.cuenta_id = cc.id 
            WHERE ($where_str) AND ld.fecha <= ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(["$anio-12-31"]);
    return (float)$stmt->fetchColumn();
}

// Activo Corriente (Existencias + Deudores + Tesorería) -> Grupos 3, 43, 44, 47, 57
$activo_corriente = getSaldoGrupo($pdo, ['3', '43', '44', '47', '57'], $anio);

// Pasivo Corriente (Acreedores + Deudas CP) -> Grupos 40, 41, 475, 52
$pasivo_corriente = abs(getSaldoGrupo($pdo, ['40', '41', '475', '52'], $anio));

$fondo_maniobra = $activo_corriente - $pasivo_corriente;

/**
 * Estado de Flujos de Efectivo (Método Indirecto - Simplificado)
 */
// 1. Resultado del ejercicio (Ingresos - Gastos)
$stmt_res = $pdo->prepare("SELECT SUM(CASE WHEN cc.codigo_pgc LIKE '7%' THEN (ld.haber - ld.debe) ELSE -(ld.debe - ld.haber) END) 
                           FROM libro_diario ld JOIN cuentas_contables cc ON ld.cuenta_id = cc.id 
                           WHERE (cc.codigo_pgc LIKE '6%' OR cc.codigo_pgc LIKE '7%') AND YEAR(ld.fecha) = ?");
$stmt_res->execute([$anio]);
$resultado_neto = (float)$stmt_res->fetchColumn();

// 2. Ajustes al resultado (Partidas que no suponen salida de caja: Amortizaciones 68)
$stmt_adj = $pdo->prepare("SELECT SUM(ld.debe) FROM libro_diario ld JOIN cuentas_contables cc ON ld.cuenta_id = cc.id 
                           WHERE cc.codigo_pgc LIKE '68%' AND YEAR(ld.fecha) = ?");
$stmt_adj->execute([$anio]);
$amortizaciones = (float)$stmt_adj->fetchColumn();

$flujo_explotacion = $resultado_neto + $amortizaciones;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Análisis de Solvencia y Flujos - ERP Financiero</title>
    <style>
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?>
        .dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2rem; }
        .card-analisis { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .fm-val { font-size: 2.5rem; font-weight: 800; margin: 1rem 0; }
        .positivo { color: #059669; }
        .negativo { color: #e11d48; }
        .alerta-fm { padding: 1rem; border-radius: 8px; font-size: 0.9rem; margin-top: 1rem; line-height: 1.4; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container">
    <header style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1>📊 Solvencia y Flujos de Efectivo</h1>
            <p style="color: #64748b;">Análisis de liquidez a corto plazo y generación de caja.</p>
        </div>
        <form method="GET">
            <select name="anio" onchange="this.form.submit()" style="padding: 8px; border-radius: 6px;">
                <?php for($i=date('Y'); $i>=2020; $i--): ?>
                    <option value="<?= $i ?>" <?= $i === $anio ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </header>

    <div class="dashboard-grid">
        <!-- Fondo de Maniobra -->
        <div class="card-analisis">
            <h3>Fondo de Maniobra</h3>
            <p style="color: #64748b;">Activo Corriente - Pasivo Corriente</p>
            
            <div class="fm-val <?= $fondo_maniobra >= 0 ? 'positivo' : 'negativo' ?>">
                <?= number_format($fondo_maniobra, 2, ',', '.') ?> €
            </div>

            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                <span>Activo Corriente (Liquidez CP)</span>
                <strong><?= number_format($activo_corriente, 2, ',', '.') ?> €</strong>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span>Pasivo Corriente (Exigible CP)</span>
                <strong><?= number_format($pasivo_corriente, 2, ',', '.') ?> €</strong>
            </div>

            <?php if ($fondo_maniobra < 0): ?>
                <div class="alerta-fm" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;">
                    <strong>🚨 ALERTA DE SOLVENCIA:</strong> El fondo de maniobra es negativo. La empresa podría tener dificultades para atender sus pagos a corto plazo. Se recomienda evaluar el principio de "Empresa en Funcionamiento".
                </div>
            <?php else: ?>
                <div class="alerta-fm" style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;">
                    <strong>✅ SOLVENCIA CORRECTA:</strong> La empresa dispone de margen suficiente para cubrir sus deudas a corto plazo con sus activos corrientes.
                </div>
            <?php endif; ?>
        </div>

        <!-- Flujos de Efectivo -->
        <div class="card-analisis">
            <h3>Generación de Caja (EFE)</h3>
            <p style="color: #64748b;">Explicación del flujo de efectivo de explotación.</p>

            <div style="margin-top: 2rem;">
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9;">
                    <span>Resultado Neto del Ejercicio</span>
                    <strong><?= number_format($resultado_neto, 2, ',', '.') ?> €</strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9;">
                    <span>(+) Ajustes: Amortizaciones (No es salida caja)</span>
                    <strong style="color: #059669;">+ <?= number_format($amortizaciones, 2, ',', '.') ?> €</strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 15px 0; font-size: 1.2rem; font-weight: bold;">
                    <span>FLUJO DE CAJA OPERATIVO</span>
                    <span style="color: #4f46e5;"><?= number_format($flujo_explotacion, 2, ',', '.') ?> €</span>
                </div>
            </div>

            <p style="font-size: 0.85rem; color: #94a3b8; margin-top: 1rem;">
                * El flujo de caja operativo indica la capacidad de la empresa para generar tesorería mediante su actividad principal, sin contar inversiones ni financiación.
            </p>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php'; ?>
</body>
</html>
