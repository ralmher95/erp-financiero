<?php
// src/Controllers/export/pdf_libro_diario.php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/db_connect.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// --- Filtros opcionales por URL: ?desde=2026-01-01&hasta=2026-12-31
$desde = $_GET['desde'] ?? date('Y-01-01');
$hasta = $_GET['hasta'] ?? date('Y-12-31');

$stmt = $pdo->prepare(
    "SELECT ld.fecha, ld.numero_asiento, cc.codigo_pgc, cc.descripcion AS cuenta,
            ld.concepto, ld.debe, ld.haber
     FROM libro_diario ld
     JOIN cuentas_contables cc ON ld.cuenta_id = cc.id
     WHERE ld.fecha BETWEEN ? AND ?
     ORDER BY ld.fecha ASC, ld.numero_asiento ASC, ld.id ASC"
);
$stmt->execute([$desde, $hasta]);
$asientos = $stmt->fetchAll();

$total_debe  = array_sum(array_column($asientos, 'debe'));
$total_haber = array_sum(array_column($asientos, 'haber'));

// Agrupar por número de asiento para trazar separadores visuales
$grupos = [];
foreach ($asientos as $fila) {
    $grupos[$fila['numero_asiento']][] = $fila;
}

ob_start(); ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; margin: 0; }
    h1   { font-size: 15px; color: #2c3e50; margin: 0 0 4px; }
    .sub { font-size: 9px; color: #7f8c8d; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    thead th { background: #2c3e50; color: white; padding: 5px 6px; text-align: left; font-size: 8px; text-transform: uppercase; }
    .asiento-header td { background: #eaf2fb; font-weight: bold; color: #1a5276; padding: 4px 6px; border-top: 1px solid #aed6f1; }
    td { padding: 3px 6px; border-bottom: 1px solid #f0f0f0; }
    .tr { text-align: right; }
    .tfoot td { background: #2c3e50; color: white; font-weight: bold; padding: 5px 6px; }
    .ok  { color: #1e8449; }
    .err { color: #c0392b; }
    .footer { font-size: 8px; color: #aaa; text-align: center; margin-top: 12px; }
</style>
</head>
<body>
<h1>📖 Libro Diario — Contabilidad <?= date('Y') ?></h1>
<p class="sub">Período: <?= date('d/m/Y', strtotime($desde)) ?> — <?= date('d/m/Y', strtotime($hasta)) ?> &nbsp;|&nbsp; Generado: <?= date('d/m/Y H:i') ?></p>

<table>
    <thead>
        <tr>
            <th style="width:7%">Fecha</th>
            <th style="width:5%">Asiento</th>
            <th style="width:10%">Cuenta</th>
            <th style="width:35%">Descripción cuenta</th>
            <th style="width:25%">Concepto</th>
            <th style="width:9%" class="tr">Debe (€)</th>
            <th style="width:9%" class="tr">Haber (€)</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($grupos as $num => $lineas): ?>
        <tr class="asiento-header">
            <td><?= date('d/m/Y', strtotime($lineas[0]['fecha'])) ?></td>
            <td colspan="6">Asiento #<?= $num ?> — <?= htmlspecialchars($lineas[0]['concepto']) ?></td>
        </tr>
        <?php foreach ($lineas as $l): ?>
        <tr>
            <td></td>
            <td></td>
            <td><?= htmlspecialchars($l['codigo_pgc']) ?></td>
            <td><?= htmlspecialchars($l['cuenta']) ?></td>
            <td><?= htmlspecialchars($l['concepto']) ?></td>
            <td class="tr"><?= $l['debe']  > 0 ? number_format($l['debe'],  2, ',', '.') : '' ?></td>
            <td class="tr"><?= $l['haber'] > 0 ? number_format($l['haber'], 2, ',', '.') : '' ?></td>
        </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" style="text-align:right">TOTALES</td>
            <td class="tr"><?= number_format($total_debe,  2, ',', '.') ?></td>
            <td class="tr"><?= number_format($total_haber, 2, ',', '.') ?></td>
        </tr>
        <tr>
            <td colspan="5" style="text-align:right">CUADRE</td>
            <td colspan="2" style="text-align:center" class="<?= abs($total_debe - $total_haber) < 0.01 ? 'ok' : 'err' ?>">
                <?= abs($total_debe - $total_haber) < 0.01 ? '✔ Cuadrado' : '✘ Descuadre: ' . number_format(abs($total_debe - $total_haber), 2, ',', '.') . ' €' ?>
            </td>
        </tr>
    </tfoot>
</table>
<p class="footer">ERP Financiero — Documento generado automáticamente el <?= date('d/m/Y \a \l\a\s H:i') ?></p>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('Libro_Diario_' . date('Y') . '.pdf', ['Attachment' => true]);