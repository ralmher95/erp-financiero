<?php
// src/Controllers/export/pdf_iva.php
// Genera: Liquidación IVA + Libro de IVA repercutido + Libro de IVA soportado
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/db_connect.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$anio      = (int)($_GET['anio'] ?? date('Y'));
$trimestre = (int)($_GET['trimestre'] ?? 0); // 0 = anual, 1-4 = trimestral

if ($trimestre >= 1 && $trimestre <= 4) {
    $mes_inicio = ($trimestre - 1) * 3 + 1;
    $mes_fin    = $mes_inicio + 2;
    $desde = sprintf('%04d-%02d-01', $anio, $mes_inicio);
    $hasta = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $anio, $mes_fin)));
    $periodo_label = "T{$trimestre}/{$anio}";
} else {
    $desde = "{$anio}-01-01";
    $hasta = "{$anio}-12-31";
    $periodo_label = "Ejercicio {$anio}";
}

// --- IVA Repercutido (ventas) ---
$rep = $pdo->prepare(
    "SELECT f.numero_serie, f.numero_factura, f.fecha_emision,
            c.nombre_fiscal, c.nif_cif,
            f.base_imponible, f.cuota_iva, f.total
     FROM facturas f
     JOIN clientes c ON f.cliente_id = c.id
     WHERE f.fecha_emision BETWEEN ? AND ?
     ORDER BY f.fecha_emision ASC, f.numero_factura ASC"
);
$rep->execute([$desde, $hasta]);
$facturas_rep = $rep->fetchAll();

$total_base_rep = array_sum(array_column($facturas_rep, 'base_imponible'));
$total_iva_rep  = array_sum(array_column($facturas_rep, 'cuota_iva'));
$total_rep      = array_sum(array_column($facturas_rep, 'total'));

// --- IVA Soportado (compras desde Libro Diario cuentas 472x) ---
$sop = $pdo->prepare(
    "SELECT ld.fecha, ld.numero_asiento, ld.concepto,
            ld.debe AS cuota_iva
     FROM libro_diario ld
     JOIN cuentas_contables cc ON ld.cuenta_id = cc.id
     WHERE cc.codigo_pgc IN ('472','4720','4721')
       AND ld.fecha BETWEEN ? AND ?
       AND ld.debe > 0
     ORDER BY ld.fecha ASC"
);
$sop->execute([$desde, $hasta]);
$asientos_sop = $sop->fetchAll();
$total_iva_sop = array_sum(array_column($asientos_sop, 'cuota_iva'));

$resultado_iva = $total_iva_rep - $total_iva_sop;
$clase_res     = $resultado_iva > 0 ? 'pagar' : ($resultado_iva < 0 ? 'devolver' : 'cero');

ob_start(); ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8">
<style>
    body  { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
    h1    { font-size: 14px; color: #2c3e50; margin: 0 0 4px; }
    h2    { font-size: 10px; color: white; background: #2c3e50; padding: 5px 8px; margin: 16px 0 0; }
    .sub  { font-size: 9px; color: #7f8c8d; margin-bottom: 10px; }
    .resumen { width:100%; border-collapse:collapse; margin-bottom:12px; }
    .resumen td { padding: 5px 10px; border: 1px solid #ddd; }
    .resumen .lbl { background:#f2f3f4; font-weight:bold; width:55%; }
    .resumen .val { text-align:right; font-size:11px; font-weight:bold; }
    .pagar    { background:#fadbd8; color:#922b21; }
    .devolver { background:#d5f5e3; color:#145a32; }
    .cero     { background:#eaf2ff; color:#1a5276; }
    table { width: 100%; border-collapse: collapse; }
    thead th { background: #34495e; color: white; padding: 4px 6px; font-size: 8px; text-transform: uppercase; text-align:left; }
    td { padding: 3px 6px; border-bottom: 1px solid #f0f0f0; }
    .tr { text-align: right; }
    .tfoot td { background: #ecf0f1; font-weight: bold; padding: 4px 6px; border-top: 2px solid #bdc3c7; }
    .footer { font-size: 8px; color: #aaa; text-align: center; margin-top: 16px; }
    .page-break { page-break-before: always; }
</style>
</head>
<body>

<!-- ═══════════════ RESUMEN LIQUIDACIÓN ═══════════════ -->
<h1>🧾 Liquidación de IVA — <?= $periodo_label ?></h1>
<p class="sub">Generado: <?= date('d/m/Y H:i') ?> &nbsp;|&nbsp; Período: <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?></p>

<table class="resumen">
    <tr><td class="lbl">IVA Repercutido (ventas)</td>    <td class="val"><?= number_format($total_iva_rep,  2, ',', '.') ?> €</td></tr>
    <tr><td class="lbl">IVA Soportado (compras)</td>     <td class="val"><?= number_format($total_iva_sop,  2, ',', '.') ?> €</td></tr>
    <tr><td class="lbl <?= $clase_res ?>" colspan="2" style="text-align:center;font-size:11px">
        <?php if ($resultado_iva > 0): ?>
            ⚠️ RESULTADO: A PAGAR a Hacienda <?= number_format($resultado_iva, 2, ',', '.') ?> €
        <?php elseif ($resultado_iva < 0): ?>
            ✅ RESULTADO: A DEVOLVER por Hacienda <?= number_format(abs($resultado_iva), 2, ',', '.') ?> €
        <?php else: ?>
            ✅ IVA LIQUIDADO: cuadrado a cero
        <?php endif; ?>
    </td></tr>
</table>

<!-- ═══════════════ LIBRO IVA REPERCUTIDO ═══════════════ -->
<h2>📘 Libro de IVA Repercutido (Facturas Emitidas)</h2>
<table>
    <thead>
        <tr>
            <th style="width:10%">Fecha</th>
            <th style="width:12%">Nº Factura</th>
            <th style="width:30%">Cliente</th>
            <th style="width:13%">NIF/CIF</th>
            <th style="width:11%" class="tr">Base (€)</th>
            <th style="width:10%" class="tr">IVA (€)</th>
            <th style="width:14%" class="tr">Total (€)</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($facturas_rep)): ?>
        <tr><td colspan="7" style="text-align:center;padding:12px;color:#aaa">Sin facturas en el período.</td></tr>
    <?php else: foreach ($facturas_rep as $f): ?>
        <tr>
            <td><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></td>
            <td><?= htmlspecialchars($f['numero_serie']) ?>/<?= $f['numero_factura'] ?></td>
            <td><?= htmlspecialchars($f['nombre_fiscal']) ?></td>
            <td><?= htmlspecialchars($f['nif_cif']) ?></td>
            <td class="tr"><?= number_format($f['base_imponible'], 2, ',', '.') ?></td>
            <td class="tr"><?= number_format($f['cuota_iva'],      2, ',', '.') ?></td>
            <td class="tr"><?= number_format($f['total'],          2, ',', '.') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4" style="text-align:right">TOTALES</td>
            <td class="tr"><?= number_format($total_base_rep, 2, ',', '.') ?></td>
            <td class="tr"><?= number_format($total_iva_rep,  2, ',', '.') ?></td>
            <td class="tr"><?= number_format($total_rep,      2, ',', '.') ?></td>
        </tr>
    </tfoot>
</table>

<!-- ═══════════════ LIBRO IVA SOPORTADO ═══════════════ -->
<div class="page-break"></div>
<h1>🧾 Liquidación de IVA — <?= $periodo_label ?></h1>
<h2>📗 Libro de IVA Soportado (Compras / Gastos)</h2>
<table>
    <thead>
        <tr>
            <th style="width:12%">Fecha</th>
            <th style="width:10%">Asiento</th>
            <th style="width:58%">Concepto</th>
            <th style="width:20%" class="tr">Cuota IVA (€)</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($asientos_sop)): ?>
        <tr><td colspan="4" style="text-align:center;padding:12px;color:#aaa">Sin IVA soportado en el período.</td></tr>
    <?php else: foreach ($asientos_sop as $a): ?>
        <tr>
            <td><?= date('d/m/Y', strtotime($a['fecha'])) ?></td>
            <td>#<?= $a['numero_asiento'] ?></td>
            <td><?= htmlspecialchars($a['concepto']) ?></td>
            <td class="tr"><?= number_format($a['cuota_iva'], 2, ',', '.') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3" style="text-align:right">TOTAL IVA SOPORTADO</td>
            <td class="tr"><?= number_format($total_iva_sop, 2, ',', '.') ?></td>
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
$dompdf->stream("IVA_{$periodo_label}.pdf", ['Attachment' => true]);