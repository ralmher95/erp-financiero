<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/db_connect.php';

$anio = (int)($_GET['anio'] ?? date('Y'));

// 1. Obtener todas las cuentas del grupo 2 que han tenido movimientos
$sql_cuentas = "SELECT DISTINCT cc.id, cc.codigo_pgc, cc.nombre 
                FROM cuentas_contables cc
                JOIN libro_diario ld ON ld.cuenta_id = cc.id
                WHERE cc.codigo_pgc LIKE '2%'
                ORDER BY cc.codigo_pgc ASC";
$cuentas = $pdo->query($sql_cuentas)->fetchAll();

$resumen = [];
foreach ($cuentas as $cta) {
    // Saldo Inicial (acumulado hasta el 31 de diciembre del año anterior)
    $stmt_inicial = $pdo->prepare("SELECT SUM(debe) - SUM(haber) FROM libro_diario WHERE cuenta_id = ? AND fecha < ?");
    $stmt_inicial->execute([$cta['id'], "$anio-01-01"]);
    $saldo_inicial = (float)$stmt_inicial->fetchColumn();

    // Altas (Entradas en el Debe durante el año)
    $stmt_altas = $pdo->prepare("SELECT SUM(debe) FROM libro_diario WHERE cuenta_id = ? AND YEAR(fecha) = ? AND debe > 0");
    $stmt_altas->execute([$cta['id'], $anio]);
    $altas = (float)$stmt_altas->fetchColumn();

    // Bajas (Salidas en el Haber durante el año)
    $stmt_bajas = $pdo->prepare("SELECT SUM(haber) FROM libro_diario WHERE cuenta_id = ? AND YEAR(fecha) = ? AND haber > 0");
    $stmt_bajas->execute([$cta['id'], $anio]);
    $bajas = (float)$stmt_bajas->fetchColumn();

    $saldo_final = $saldo_inicial + $altas - $bajas;

    if ($saldo_inicial != 0 || $altas > 0 || $bajas > 0) {
        $resumen[] = [
            'codigo' => $cta['codigo_pgc'],
            'nombre' => $cta['nombre'],
            'inicial' => $saldo_inicial,
            'altas' => $altas,
            'bajas' => $bajas,
            'final' => $saldo_final
        ];
    }
}

// 2. Dotación a la Amortización (Grupo 68)
$stmt_amort = $pdo->prepare("SELECT COALESCE(SUM(ld.debe), 0) 
                             FROM libro_diario ld 
                             JOIN cuentas_contables cc ON ld.cuenta_id = cc.id 
                             WHERE cc.codigo_pgc LIKE '68%' AND YEAR(ld.fecha) = ?");
$stmt_amort->execute([$anio]);
$amortizacion_ejercicio = (float)$stmt_amort->fetchColumn();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cuadro de Variaciones del Inmovilizado - ERP Financiero</title>
    <style>
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?>
        .tabla-inmovilizado { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .tabla-inmovilizado th, .tabla-inmovilizado td { padding: 12px; border: 1px solid #e2e8f0; text-align: right; }
        .tabla-inmovilizado th { background: #f8fafc; text-align: left; }
        .col-nombre { text-align: left !important; min-width: 250px; }
        .total-amort { background: #eff6ff; padding: 1.5rem; border-radius: 8px; margin-top: 2rem; border: 1px solid #bfdbfe; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container">
    <header style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1>🏗️ Variaciones del Inmovilizado</h1>
            <p style="color: #64748b;">Análisis de Activos Fijos (Grupo 2) para la Memoria.</p>
        </div>
        <form method="GET">
            <select name="anio" onchange="this.form.submit()" style="padding: 8px; border-radius: 6px;">
                <?php for($i=date('Y'); $i>=2020; $i--): ?>
                    <option value="<?= $i ?>" <?= $i === $anio ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </header>

    <table class="tabla-inmovilizado">
        <thead>
            <tr>
                <th class="col-nombre">Cuenta / Activo</th>
                <th>Saldo Inicial</th>
                <th style="color: #059669;">(+) Altas</th>
                <th style="color: #e11d48;">(-) Bajas</th>
                <th>Saldo Final</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($resumen)): ?>
                <tr><td colspan="5" style="text-align:center; padding:3rem; color:#94a3b8;">No se han detectado movimientos en el inmovilizado para el año <?= $anio ?>.</td></tr>
            <?php else: foreach($resumen as $r): ?>
                <tr>
                    <td class="col-nombre">
                        <strong><?= $r['codigo'] ?></strong><br>
                        <small><?= htmlspecialchars($r['nombre']) ?></small>
                    </td>
                    <td><?= number_format($r['inicial'], 2, ',', '.') ?> €</td>
                    <td style="color: #059669; font-weight: 600;">+ <?= number_format($r['altas'], 2, ',', '.') ?> €</td>
                    <td style="color: #e11d48; font-weight: 600;">- <?= number_format($r['bajas'], 2, ',', '.') ?> €</td>
                    <td style="background: #f1f5f9; font-weight: bold;"><?= number_format($r['final'], 2, ',', '.') ?> €</td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="total-amort">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; color: #1e40af;">Dotación a la Amortización (Grupo 68)</h3>
                <p style="margin: 0.5rem 0 0; color: #64748b;">Gasto acumulado por depreciación de activos durante el ejercicio <?= $anio ?>.</p>
            </div>
            <div style="font-size: 2rem; font-weight: 800; color: #1e40af;">
                <?= number_format($amortizacion_ejercicio, 2, ',', '.') ?> €
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php'; ?>
</body>
</html>
