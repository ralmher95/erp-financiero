<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/db_connect.php';

$anio = (int)($_GET['anio'] ?? date('Y'));

/**
 * Cálculo del Periodo Medio de Pago (PMP)
 * PMP = sum(días_retraso * importe) / sum(importe_total)
 */

// 1. Obtener facturas recibidas (pagos a proveedores) y sus pagos vinculados en el diario
$sql = "SELECT 
            f.id, f.numero_serie, f.numero_factura, f.fecha_emision, f.total,
            ld.fecha as fecha_pago,
            DATEDIFF(ld.fecha, f.fecha_emision) as dias_pago,
            p.nombre_fiscal as entidad
        FROM facturas f
        JOIN proveedores p ON f.proveedor_id = p.id
        JOIN libro_diario ld ON ld.factura_id = f.id
        JOIN cuentas_contables cc ON ld.cuenta_id = cc.id
        WHERE f.tipo = 'recibida' 
          AND cc.codigo_pgc LIKE '57%' -- Cuentas de tesorería (pago real)
          AND YEAR(ld.fecha) = ?
        ORDER BY ld.fecha DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$anio]);
$pagos_proveedores = $stmt->fetchAll();

// Calcular PMP Proveedores
$sum_producto_p = 0;
$sum_importe_p = 0;
foreach ($pagos_proveedores as $p) {
    $sum_producto_p += ($p['dias_pago'] * $p['total']);
    $sum_importe_p += $p['total'];
}
$pmp_proveedores = $sum_importe_p > 0 ? round($sum_producto_p / $sum_importe_p, 2) : 0;

// 2. Lo mismo para Clientes (Periodo Medio de Cobro)
$sql_c = "SELECT 
            f.id, f.numero_serie, f.numero_factura, f.fecha_emision, f.total,
            ld.fecha as fecha_cobro,
            DATEDIFF(ld.fecha, f.fecha_emision) as dias_cobro,
            c.nombre_fiscal as entidad
        FROM facturas f
        JOIN clientes c ON f.cliente_id = c.id
        JOIN libro_diario ld ON ld.factura_id = f.id
        JOIN cuentas_contables cc ON ld.cuenta_id = cc.id
        WHERE f.tipo = 'emitida' 
          AND cc.codigo_pgc LIKE '57%' 
          AND YEAR(ld.fecha) = ?
        ORDER BY ld.fecha DESC";

$stmt_c = $pdo->prepare($sql_c);
$stmt_c->execute([$anio]);
$cobros_clientes = $stmt_c->fetchAll();

$sum_producto_c = 0;
$sum_importe_c = 0;
foreach ($cobros_clientes as $c) {
    $sum_producto_c += ($c['dias_cobro'] * $c['total']);
    $sum_importe_c += $c['total'];
}
$pmc_clientes = $sum_importe_c > 0 ? round($sum_producto_c / $sum_importe_c, 2) : 0;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Informe de Morosidad y PMP - ERP Financiero</title>
    <style>
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?>
        .kpi-morosidad { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem; }
        .pmp-box { padding: 2rem; border-radius: 12px; text-align: center; color: white; }
        .pmp-proveedores { background: #e11d48; }
        .pmp-clientes { background: #059669; }
        .pmp-val { font-size: 3rem; font-weight: 800; display: block; }
        .pmp-label { font-size: 1.1rem; opacity: 0.9; }
        .alerta-morosidad { padding: 1rem; border-radius: 8px; margin-bottom: 2rem; border: 1px solid; }
        .alerta-roja { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .alerta-verde { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container">
    <header style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1>⚖️ Test de Morosidad (PMP)</h1>
            <p style="color: #64748b;">Cumplimiento de plazos de pago según Ley 15/2010.</p>
        </div>
        <form method="GET">
            <select name="anio" onchange="this.form.submit()" style="padding: 8px; border-radius: 6px;">
                <?php for($i=date('Y'); $i>=2020; $i--): ?>
                    <option value="<?= $i ?>" <?= $i === $anio ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </header>

    <div class="kpi-morosidad">
        <div class="pmp-box pmp-proveedores">
            <span class="pmp-val"><?= $pmp_proveedores ?> días</span>
            <span class="pmp-label">Periodo Medio de Pago (PMP)</span>
        </div>
        <div class="pmp-box pmp-clientes">
            <span class="pmp-val"><?= $pmc_clientes ?> días</span>
            <span class="pmp-label">Periodo Medio de Cobro (PMC)</span>
        </div>
    </div>

    <?php if ($pmp_proveedores > 60): ?>
        <div class="alerta-morosidad alerta-roja">
            <strong>⚠️ ALERTA DE MOROSIDAD:</strong> Tu periodo medio de pago (<?= $pmp_proveedores ?> días) supera el límite legal de 60 días establecido por la Ley de Morosidad. Esto debe ser reportado en la Memoria de las Cuentas Anuales.
        </div>
    <?php else: ?>
        <div class="alerta-morosidad alerta-verde">
            <strong>✅ CUMPLIMIENTO LEGAL:</strong> Tu periodo medio de pago está dentro de los límites legales (< 60 días).
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
        <section>
            <h2>📉 Detalle de Pagos a Proveedores</h2>
            <table>
                <thead>
                    <tr>
                        <th>Factura</th><th>Proveedor</th><th>Días</th><th class="text-right">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pagos_proveedores)): ?>
                        <tr><td colspan="4" style="text-align:center; padding:2rem; color:#94a3b8;">No hay pagos vinculados registrados este año.</td></tr>
                    <?php else: foreach($pagos_proveedores as $p): ?>
                        <tr>
                            <td><?= $p['numero_serie'] ?>/<?= $p['numero_factura'] ?></td>
                            <td><small><?= htmlspecialchars($p['entidad']) ?></small></td>
                            <td><span class="badge <?= $p['dias_pago'] > 60 ? 'badge-cancelada' : 'tipo-emitida' ?>"><?= $p['dias_pago'] ?> d</span></td>
                            <td class="text-right"><?= number_format($p['total'], 2, ',', '.') ?> €</td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </section>

        <section>
            <h2>📈 Detalle de Cobros de Clientes</h2>
            <table>
                <thead>
                    <tr>
                        <th>Factura</th><th>Cliente</th><th>Días</th><th class="text-right">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cobros_clientes)): ?>
                        <tr><td colspan="4" style="text-align:center; padding:2rem; color:#94a3b8;">No hay cobros vinculados registrados este año.</td></tr>
                    <?php else: foreach($cobros_clientes as $c): ?>
                        <tr>
                            <td><?= $c['numero_serie'] ?>/<?= $c['numero_factura'] ?></td>
                            <td><small><?= htmlspecialchars($c['entidad']) ?></small></td>
                            <td><span class="badge tipo-emitida"><?= $c['dias_cobro'] ?> d</span></td>
                            <td class="text-right"><?= number_format($c['total'], 2, ',', '.') ?> €</td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </section>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php'; ?>
</body>
</html>
