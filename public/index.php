<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db_connect.php';

use App\Services\DashboardService;

// --- Inicialización del Servicio ---
$dashboardService = new DashboardService($pdo);

// --- Obtención de Datos (KPIs, Gráfico, Últimos Movimientos) ---
$kpis      = $dashboardService->getKpis();
$chartData = $dashboardService->getChartData();
$ultimos   = $dashboardService->getUltimosMovimientos(5);

// --- Formateo de Datos para el Gráfico ---
// CORRECCIÓN #4: Flags JSON_HEX_TAG | JSON_HEX_AMP para prevenir XSS en variables JS
$chartLabels   = json_encode(array_column($chartData, 'mes'),      JSON_HEX_TAG | JSON_HEX_AMP);
$chartIngresos = json_encode(array_column($chartData, 'ingresos'), JSON_HEX_TAG | JSON_HEX_AMP);
$chartGastos   = json_encode(array_column($chartData, 'gastos'),   JSON_HEX_TAG | JSON_HEX_AMP);

$beneficio = $kpis['beneficio'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - ERP Financiero</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style><?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?></style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container">
    <div class="header-section">
        <h1>Resumen Financiero</h1>
        <a href="<?= URL_BASE ?>views/contabilidad/libro_diario.php" class="btn-nuevo">+ Nuevo Asiento</a>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card kpi-bancos">
            <h3>Tesorería (Bancos/Caja)</h3>
            <p class="monto"><?= number_format($kpis['total_bancos'], 2, ',', '.') ?> €</p>
        </div>
        <div class="kpi-card kpi-ingresos">
            <h3>Ingresos (Grupo 7)</h3>
            <p class="monto"><?= number_format($kpis['total_ingresos'], 2, ',', '.') ?> €</p>
        </div>
        <div class="kpi-card kpi-gastos">
            <h3>Gastos (Grupo 6)</h3>
            <p class="monto"><?= number_format($kpis['total_gastos'], 2, ',', '.') ?> €</p>
        </div>
        <div class="kpi-card kpi-beneficio">
            <h3>Beneficio Neto</h3>
            <p class="monto <?= $beneficio < 0 ? 'negativo' : '' ?>"><?= number_format($beneficio, 2, ',', '.') ?> €</p>
        </div>
    </div>

    <div class="bottom-grid">
        <div class="panel">
            <h3>Ingresos vs Gastos — Últimos 6 meses</h3>
            <?php if (empty($chartData)): ?>
                <p class="empty-msg">No hay datos suficientes para mostrar el gráfico.</p>
            <?php else: ?>
                <canvas id="graficoMensual" height="110"></canvas>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h3>Última Actividad</h3>
            <table>
                <tr><th>Fecha</th><th>Concepto</th><th class="text-right">Importe</th></tr>
                <?php if (empty($ultimos)): ?>
                    <tr><td colspan="3" class="empty-msg">No hay asientos aún</td></tr>
                <?php else: foreach ($ultimos as $mov):
                    $importe  = max($mov['debe'], $mov['haber']);
                    $concepto = mb_strlen($mov['concepto']) > 22
                        ? mb_substr($mov['concepto'], 0, 22) . '…' : $mov['concepto'];
                ?>
                    <tr>
                        <td style="color:#7f8c8d;white-space:nowrap"><?= date('d/m/y', strtotime($mov['fecha'])) ?></td>
                        <td><?= htmlspecialchars($concepto) ?></td>
                        <td class="text-right"><strong><?= number_format((float)$importe, 2, ',', '.') ?> €</strong></td>
                    </tr>
                <?php endforeach; endif; ?>
            </table>
            <div class="ver-todo">
                <a href="<?= URL_BASE ?>views/contabilidad/libro_diario.php">Ver todo el diario →</a>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($chartData)): ?>
<script>
new Chart(document.getElementById('graficoMensual').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= $chartLabels ?>,
        datasets: [
            { label:'Ingresos', data:<?= $chartIngresos ?>, backgroundColor:'rgba(46,204,113,0.75)', borderColor:'rgba(46,204,113,1)', borderWidth:1, borderRadius:4 },
            { label:'Gastos',   data:<?= $chartGastos ?>,   backgroundColor:'rgba(231,76,60,0.75)',  borderColor:'rgba(231,76,60,1)',  borderWidth:1, borderRadius:4 }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position:'top' } },
        scales: { y: { beginAtZero:true, ticks: { callback: v => v.toLocaleString('es-ES') + ' €' } } }
    }
});
</script>   
<?php endif; ?>
</body>
<?php require_once ROOT_PATH . 'includes/layout_footer.php'; ?>
</html>
