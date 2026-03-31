<?php
// src/Controllers/export/pdf_ventas.php
// Genera: Facturas Emitidas + Listado de Clientes (en un solo PDF, 2 secciones)
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/db_connect.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$seccion = $_GET['seccion'] ?? 'ambas'; // 'facturas' | 'clientes' | 'ambas'
$desde   = $_GET['desde']   ?? date('Y-01-01');
$hasta   = $_GET['hasta']   ?? date('Y-12-31');

// --- Facturas emitidas ---
$stmt_f = $pdo->prepare(
    "SELECT f.numero_serie, f.numero_factura, f.fecha_emision,
            c.nombre_fiscal, c.nif_cif,
            f.base_imponible, f.cuota_iva, f.total
     FROM facturas f
     JOIN clientes c ON f.cliente_id = c.id
     WHERE f.fecha_emision BETWEEN ? AND ?
     ORDER BY f.fecha_emision ASC, f.numero_factura ASC"
);
$stmt_f->execute([$desde, $hasta]);
$facturas = $stmt_f->fetchAll();

$total_base = array_sum(array_column($facturas, 'base_imponible'));
$total_iva  = array_sum(array_column($facturas, 'cuota_iva'));
$total_fac  = array_sum(array_column($facturas, 'total'));

// --- Listado clientes ---
$clientes = $pdo->query(
    "SELECT nombre_fiscal, nif_cif, email, telefono, ciudad, codigo_postal, provincia, pais
     FROM clientes ORDER BY nombre_fiscal ASC"
)->fetchAll();

ob_start(); ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8">
<style>
    body  { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
    h1    { font-size: 14px; color: #2c3e50; margin: 0 0 4px; }
    h2    { font-size: 10px; color: white; background: #27ae60; padding: 5px 8px; margin: 0 0 0; }
    .sub  { font-size: 9px; color: #7f8c8d; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    thead th { background: #27ae60; color: white; padding: 4px 6px; font-size: 8px; text-transform: uppercase; text-align: left; }
    td { padding: 3px 6px; border-bottom: 1px solid #f0f0f0; }
    .tr { text-align: right; }
    .tfoot td { background: #eafaf1; font-weight: bold; padding: 5px 6px; border-top: 2px solid #a9dfbf; }
    .page-break { page-break-before: always; }
    .footer { font-size: 8px; color: #aaa; text-align: center; margin-top: 14px; }
    .kpi-row { display: table; width: 100%; margin-bottom: 12px; }
    .kpi { display: table-cell; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px; padding: 7px 12px; text-align: center; width: 33%; }
    .kpi .lbl { font-size: 8px; color: #7f8c8d; text-transform: uppercase; }
    .kpi .val { font-size: 13px; font-weight: bold; color: #27ae60; }
</style>
</head>
<body>

<?php if ($seccion === 'ambas' || $seccion === 'facturas'): ?>

<!-- ═══════════════ FACTURAS EMITIDAS ═══════════════ -->
<h1>📑 Facturas Emitidas — Ventas</h1>
<p class="sub">Período: <?= date('d/m/Y', strtotime($desde)) ?> — <?= date('d/m/Y', strtotime($hasta)) ?> &nbsp;|&nbsp; Generado: <?= date('d/m/Y H:i') ?></p>

<!-- KPIs resumen -->
<table style="margin-bottom:12px">
    <tr>
        <td style="background:#eafaf1;border:1px solid #a9dfbf;padding:8px 14px;text-align:center;width:33%">
            <div style="font-size:8px;color:#7f8c8d;text-transform:uppercase">Nº Facturas</div>
            <div style="font-size:14px;font-weight:bold;color:#27ae60"><?= count($facturas) ?></div>
        </td>
        <td style="background:#eafaf1;border:1px solid #a9dfbf;padding:8px 14px;text-align:center;width:33%">
            <div style="font-size:8px;color:#7f8c8d;text-transform:uppercase">Base Imponible</div>
            <div style="font-size:14px;font-weight:bold;color:#27ae60"><?= number_format($total_base, 2, ',', '.') ?> €</div>
        </td>
        <td style="background:#eafaf1;border:1px solid #a9dfbf;padding:8px 14px;text-align:center;width:34%">
            <div style="font-size:8px;color:#7f8c8d;text-transform:uppercase">Total Facturado</div>
            <div style="font-size:14px;font-weight:bold;color:#27ae60"><?= number_format($total_fac, 2, ',', '.') ?> €</div>
        </td>
    </tr>
</table>

<table>
    <thead>
        <tr>
            <th style="width:10%">Fecha</th>
            <th style="width:12%">Nº Factura</th>
            <th style="width:34%">Cliente</th>
            <th style="width:14%">NIF/CIF</th>
            <th style="width:10%" class="tr">Base (€)</th>
            <th style="width:8%"  class="tr">IVA (€)</th>
            <th style="width:12%" class="tr">Total (€)</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($facturas)): ?>
        <tr><td colspan="7" style="text-align:center;padding:16px;color:#aaa">Sin facturas en el período seleccionado.</td></tr>
    <?php else: foreach ($facturas as $f): ?>
        <tr>
            <td><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></td>
            <td style="color:#27ae60;font-weight:bold"><?= htmlspecialchars($f['numero_serie']) ?>/<?= $f['numero_factura'] ?></td>
            <td><?= htmlspecialchars($f['nombre_fiscal']) ?></td>
            <td><?= htmlspecialchars($f['nif_cif']) ?></td>
            <td class="tr"><?= number_format($f['base_imponible'], 2, ',', '.') ?></td>
            <td class="tr"><?= number_format($f['cuota_iva'],      2, ',', '.') ?></td>
            <td class="tr" style="font-weight:bold"><?= number_format($f['total'], 2, ',', '.') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4" style="text-align:right">TOTALES</td>
            <td class="tr"><?= number_format($total_base, 2, ',', '.') ?></td>
            <td class="tr"><?= number_format($total_iva,  2, ',', '.') ?></td>
            <td class="tr"><?= number_format($total_fac,  2, ',', '.') ?></td>
        </tr>
    </tfoot>
</table>

<?php endif; ?>

<?php if ($seccion === 'ambas'): ?>
<div class="page-break"></div>
<?php endif; ?>

<?php if ($seccion === 'ambas' || $seccion === 'clientes'): ?>

<!-- ═══════════════ LISTADO DE CLIENTES ═══════════════ -->
<h1>👥 Listado de Clientes</h1>
<p class="sub">Total: <?= count($clientes) ?> clientes &nbsp;|&nbsp; Generado: <?= date('d/m/Y H:i') ?></p>

<table>
    <thead>
        <tr>
            <th style="width:28%">Nombre fiscal</th>
            <th style="width:13%">NIF/CIF</th>
            <th style="width:22%">Email</th>
            <th style="width:12%">Teléfono</th>
            <th style="width:14%">Ciudad</th>
            <th style="width:11%">País</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($clientes)): ?>
        <tr><td colspan="6" style="text-align:center;padding:16px;color:#aaa">No hay clientes registrados.</td></tr>
    <?php else: foreach ($clientes as $c): ?>
        <tr>
            <td style="font-weight:bold"><?= htmlspecialchars($c['nombre_fiscal']) ?></td>
            <td><?= htmlspecialchars($c['nif_cif']) ?></td>
            <td><?= htmlspecialchars($c['email'] ?? '—') ?></td>
            <td><?= htmlspecialchars($c['telefono'] ?? '—') ?></td>
            <td><?= htmlspecialchars($c['ciudad'] ?? '—') ?></td>
            <td><?= htmlspecialchars($c['pais']) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<?php endif; ?>

<p class="footer">ERP Financiero — Documento generado automáticamente el <?= date('d/m/Y \a \l\a\s H:i') ?></p>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$nombre = match($seccion) {
    'facturas' => 'Facturas_Emitidas_' . date('Y') . '.pdf',
    'clientes' => 'Listado_Clientes_' . date('Y') . '.pdf',
    default    => 'Ventas_y_Clientes_' . date('Y') . '.pdf',
};
$dompdf->stream($nombre, ['Attachment' => true]);