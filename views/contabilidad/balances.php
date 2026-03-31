<?php
require_once __DIR__ . '/../../config/db_connect.php';

$desde = $_GET['desde'] ?? date('Y-01-01');
$hasta = $_GET['hasta'] ?? date('Y-12-31');

// Balance de sumas y saldos por cuenta
$stmt = $pdo->prepare(
    "SELECT cc.codigo_pgc, cc.descripcion, cc.tipo,
            COALESCE(SUM(ld.debe),  0) AS total_debe,
            COALESCE(SUM(ld.haber), 0) AS total_haber,
            COALESCE(SUM(ld.debe) - SUM(ld.haber), 0) AS saldo
     FROM cuentas_contables cc
     LEFT JOIN libro_diario ld ON ld.cuenta_id = cc.id
         AND ld.fecha BETWEEN ? AND ?
     GROUP BY cc.id, cc.codigo_pgc, cc.descripcion, cc.tipo
     HAVING total_debe > 0 OR total_haber > 0
     ORDER BY cc.codigo_pgc ASC"
);
$stmt->execute([$desde, $hasta]);
$cuentas = $stmt->fetchAll();

$gran_debe  = array_sum(array_column($cuentas, 'total_debe'));
$gran_haber = array_sum(array_column($cuentas, 'total_haber'));
$cuadrado   = abs($gran_debe - $gran_haber) < 0.01;

$grupos = [
    'activo'     => ['label' => 'Activo',         'color' => '#2980b9', 'emoji' => '🏦'],
    'pasivo'     => ['label' => 'Pasivo',          'color' => '#e67e22', 'emoji' => '📤'],
    'patrimonio' => ['label' => 'Patrimonio Neto', 'color' => '#8e44ad', 'emoji' => '🏛️'],
    'ingreso'    => ['label' => 'Ingresos',        'color' => '#27ae60', 'emoji' => '📈'],
    'gasto'      => ['label' => 'Gastos',          'color' => '#e74c3c', 'emoji' => '📉'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balances - ERP Financiero</title>
    <style><?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?></style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container">
    <div class="page-header">
        <h1>⚖️ Balance de Sumas y Saldos</h1>
        <div class="acciones">
            <a href="<?= URL_BASE ?>src/Controllers/export/pdf_balances.php?desde=<?= $desde ?>&hasta=<?= $hasta ?>"
               target="_blank" class="btn btn-pdf">
               ⬇️ Descargar PDF
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <form method="GET" class="filtros">
        <div class="campo">
            <label>Desde</label>
            <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>">
        </div>
        <div class="campo">
            <label>Hasta</label>
            <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
        </div>
        <button type="submit" class="btn btn-filtrar">Aplicar filtro</button>
    </form>

    <!-- KPIs globales -->
    <div class="kpi-grid">
        <div class="kpi kpi-debe">
            <h3>Total Debe</h3>
            <div class="val"><?= number_format($gran_debe, 2, ',', '.') ?> €</div>
        </div>
        <div class="kpi kpi-haber">
            <h3>Total Haber</h3>
            <div class="val"><?= number_format($gran_haber, 2, ',', '.') ?> €</div>
        </div>
        <div class="kpi kpi-cuadre <?= $cuadrado ? '' : 'err' ?>">
            <h3>Cuadre</h3>
            <div class="val"><?= $cuadrado ? '✔ Cuadrado' : '✘ Descuadre' ?></div>
        </div>
    </div>

    <!-- Aviso cuadre -->
    <div class="<?= $cuadrado ? 'cuadre-ok' : 'cuadre-err' ?>" style="margin-bottom:20px">
        <?php if ($cuadrado): ?>
            ✅ El libro diario está perfectamente cuadrado en el período seleccionado.
        <?php else: ?>
            ⚠️ Hay un descuadre de <?= number_format(abs($gran_debe - $gran_haber), 2, ',', '.') ?> € — revisa los asientos del período.
        <?php endif; ?>
    </div>

    <?php if (empty($cuentas)): ?>
        <div class="seccion">
            <p class="empty-msg">No hay movimientos contables en el período seleccionado.</p>
        </div>
    <?php else: ?>

    <!-- Tabla por grupo contable -->
    <?php foreach ($grupos as $tipo => $info):
        $filas = array_filter($cuentas, fn($c) => $c['tipo'] === $tipo);
        if (empty($filas)) continue;
        $sub_debe  = array_sum(array_column($filas, 'total_debe'));
        $sub_haber = array_sum(array_column($filas, 'total_haber'));
        $sub_saldo = array_sum(array_column($filas, 'saldo'));
        $seccion_id = 'sec-' . $tipo;
    ?>
    <div class="seccion">
        <div class="seccion-header" style="background:<?= $info['color'] ?>"
             onclick="toggleSeccion('<?= $seccion_id ?>', this)">
            <h2><?= $info['emoji'] ?> <?= $info['label'] ?></h2>
            <div style="display:flex;gap:20px;align-items:center">
                <span class="subtotales">
                    D: <?= number_format($sub_debe,  2, ',', '.') ?> €
                    &nbsp;|&nbsp;
                    H: <?= number_format($sub_haber, 2, ',', '.') ?> €
                    &nbsp;|&nbsp;
                    Saldo: <?= number_format($sub_saldo, 2, ',', '.') ?> €
                </span>
                <span class="toggle">▼</span>
            </div>
        </div>
        <div id="<?= $seccion_id ?>">
            <table>
                <thead>
                    <tr>
                        <th style="width:10%">Código</th>
                        <th style="width:46%">Cuenta</th>
                        <th style="width:14%" class="tr">Debe (€)</th>
                        <th style="width:14%" class="tr">Haber (€)</th>
                        <th style="width:16%" class="tr">Saldo (€)</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($filas as $c): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($c['codigo_pgc']) ?></strong></td>
                        <td><?= htmlspecialchars($c['descripcion']) ?></td>
                        <td class="tr"><?= number_format($c['total_debe'],  2, ',', '.') ?></td>
                        <td class="tr"><?= number_format($c['total_haber'], 2, ',', '.') ?></td>
                        <td class="tr <?= $c['saldo'] >= 0 ? 'pos' : 'neg' ?>">
                            <?= number_format($c['saldo'], 2, ',', '.') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" style="text-align:right">Subtotal <?= $info['label'] ?></td>
                        <td class="tr"><?= number_format($sub_debe,  2, ',', '.') ?></td>
                        <td class="tr"><?= number_format($sub_haber, 2, ',', '.') ?></td>
                        <td class="tr <?= $sub_saldo >= 0 ? 'pos' : 'neg' ?>">
                            <?= number_format($sub_saldo, 2, ',', '.') ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<script>
function toggleSeccion(id, header) {
    const el = document.getElementById(id);
    const visible = el.style.display !== 'none';
    el.style.display = visible ? 'none' : '';
    header.classList.toggle('collapsed', visible);
}
</script>
</body>
<?php // Busca la carpeta 'includes' partiendo de la base de Laragon de forma absoluta  
require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php';  ?>
</html>