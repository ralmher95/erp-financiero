<?php
// src/Controllers/export/pdf_libro_mayor.php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/db_connect.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$cuenta_id = filter_input(INPUT_GET, 'cuenta_id', FILTER_VALIDATE_INT);
$desde     = $_GET['desde'] ?? date('Y-01-01');
$hasta     = $_GET['hasta'] ?? date('Y-12-31');

if (!$cuenta_id) {
    die('<p style="font-family:sans-serif;color:red">Error: indica un <strong>cuenta_id</strong> válido en la URL.<br>Ejemplo: <code>pdf_libro_mayor.php?cuenta_id=5</code></p>');
}

// Datos de la cuenta
$cuenta = $pdo->prepare("SELECT codigo_pgc, descripcion FROM cuentas_contables WHERE id = ?");
$cuenta->execute([$cuenta_id]);
$cuenta = $cuenta->fetch();
if (!$cuenta) die('<p style="font-family:sans-serif;color:red">Cuenta no encontrada.</p>');

// Movimientos
$stmt = $pdo->prepare(
    "SELECT fecha, numero_asiento, concepto, debe, haber
     FROM libro_diario
     WHERE cuenta_id = ? AND fecha BETWEEN ? AND ?
     ORDER BY fecha ASC, numero_asiento ASC"
);
$stmt->execute([$cuenta_id, $desde, $hasta]);
$movimientos = $stmt->fetchAll();

$saldo       = 0;
$total_debe  = 0;
$total_haber = 0;

ob_start(); ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8">
<style>
    body  { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
    h1    { font-size: 15px; color: #2c3e50; margin: 0 0 2px; }
    h2    { font-size: 11px; color: #2980b9; margin: 0 0 12px; }
    .sub  { font-size: 9px; color: #7f8c8d; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; }
    thead th { background: #2c3e50; color: white; padding: 5px 7px; text-align: left; font-size: 8px; text-transform: uppercase; }
    td    { padding: 3px 7px; border-bottom: 1px solid #f0f0f0; }
    .tr   { text-align: right; }
    .pos  { color: #1e8449; font-weight: bold; }
    .neg  { color: #c0392b; font-weight: bold; }
    .tfoot td { background: #2c3e50; color: white; font-weight: bold; padding: 5px 7px; }
    .footer   { font-size: 8px; color: #aaa; text-align: center; margin-top: 14px; }
</style>
</head>
<body>
<h1>📋 Libro Mayor — <?= htmlspecialchars($cuenta['codigo_pgc']) ?></h1>
<h2><?= htmlspecialchars($cuenta['descripcion']) ?></h2>
<p class="sub">Período: <?= date('d/m/Y', strtotime($desde)) ?> — <?= date('d/m/Y', strtotime($hasta)) ?> &nbsp;|&nbsp; Generado: <?= date('d/m/Y H:i') ?></p>

<table>
    <thead>
        <tr>
            <th style="width:10%">Fecha</th>
            <th style="width:8%">Asiento</th>
            <th style="width:44%">Concepto</th>
            <th style="width:12%" class="tr">Debe (€)</th>
            <th style="width:12%" class="tr">Haber (€)</th>
            <th style="width:14%" class="tr">Saldo (€)</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($movimientos)): ?>
        <tr><td colspan="6" style="text-align:center;padding:20px;color:#aaa">Sin movimientos en el período seleccionado.</td></tr>
    <?php else: foreach ($movimientos as $m):
        $saldo       += $m['debe'] - $m['haber'];
        $total_debe  += $m['debe'];
        $total_haber += $m['haber'];
    ?>
        <tr>
            <td><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
            <td>#<?= $m['numero_asiento'] ?></td>
            <td><?= htmlspecialchars($m['concepto']) ?></td>
            <td class="tr"><?= $m['debe']  > 0 ? number_format($m['debe'],  2, ',', '.') : '—' ?></td>
            <td class="tr"><?= $m['haber'] > 0 ? number_format($m['haber'], 2, ',', '.') : '—' ?></td>
            <td class="tr <?= $saldo >= 0 ? 'pos' : 'neg' ?>"><?= number_format($saldo, 2, ',', '.') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3" style="text-align:right">TOTALES</td>
            <td class="tr"><?= number_format($total_debe,  2, ',', '.') ?></td>
            <td class="tr"><?= number_format($total_haber, 2, ',', '.') ?></td>
            <td class="tr <?= $saldo >= 0 ? 'pos' : 'neg' ?>"><?= number_format($saldo, 2, ',', '.') ?></td>
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

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('Libro_Mayor_' . $cuenta['codigo_pgc'] . '_' . date('Y') . '.pdf', ['Attachment' => true]);