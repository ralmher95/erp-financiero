<?php
// src/Controllers/export/pdf_compras.php
// MEJORA M-02 — Todos los inputs GET validados con helpers antes de usar en queries.

declare(strict_types=1);
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';  // M-02
require_once __DIR__ . '/../../../includes/logger.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// M-02 — Validar TODOS los inputs GET antes de usarlos
$seccion = validarEnum($_GET['seccion'] ?? 'ambas', ['facturas', 'proveedores', 'ambas'], 'ambas');
$desde   = validarFecha($_GET['desde'] ?? '', date('Y-01-01'));
$hasta   = validarFecha($_GET['hasta'] ?? '', date('Y-12-31'));

// Corregir rango si está invertido
if ($desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}

// --- Facturas recibidas (tickets de compra) ---
$tabla_tickets = false;
try {
    $pdo->query("SELECT 1 FROM tickets_compra LIMIT 1");
    $tabla_tickets = true;
} catch (Exception $e) {
    $tabla_tickets = false;
}

if ($tabla_tickets) {
    $stmt_t = $pdo->prepare(
        "SELECT tc.fecha, tc.concepto,
                COALESCE(p.nombre_fiscal, 'Sin proveedor') AS proveedor,
                COALESCE(p.nif_cif, '—') AS nif_cif,
                tc.base_imponible, tc.cuota_iva, tc.total
         FROM tickets_compra tc
         LEFT JOIN proveedores p ON tc.proveedor_id = p.id
         WHERE tc.fecha BETWEEN ? AND ?
         ORDER BY tc.fecha ASC"
    );
    $stmt_t->execute([$desde, $hasta]);
    $tickets = $stmt_t->fetchAll();
} else {
    $stmt_t = $pdo->prepare(
        "SELECT ld.fecha, ld.concepto,
                'Sin proveedor' AS proveedor, '—' AS nif_cif,
                ld.debe AS base_imponible, 0 AS cuota_iva, ld.debe AS total
         FROM libro_diario ld
         JOIN cuentas_contables cc ON ld.cuenta_id = cc.id
         WHERE cc.codigo_pgc LIKE '6%'
           AND ld.debe > 0
           AND ld.fecha BETWEEN ? AND ?
         ORDER BY ld.fecha ASC"
    );
    $stmt_t->execute([$desde, $hasta]);
    $tickets = $stmt_t->fetchAll();
}

$total_base_comp = array_sum(array_column($tickets, 'base_imponible'));
$total_iva_comp  = array_sum(array_column($tickets, 'cuota_iva'));
$total_comp      = array_sum(array_column($tickets, 'total'));

// M-03: columnas explícitas en proveedores
$proveedores = $pdo->query(
    "SELECT nombre_fiscal, nif_cif, email, telefono, ciudad, codigo_postal, provincia, pais, cuenta_contable
     FROM proveedores ORDER BY nombre_fiscal ASC"
)->fetchAll();

log_erp('INFO', 'pdf_compras', "PDF generado: seccion=$seccion, desde=$desde, hasta=$hasta");

ob_start(); ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8">
<style>
    body  { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
    h1    { font-size: 14px; color: #2c3e50; margin: 0 0 4px; }
    h2    { font-size: 10px; color: white; background: #e67e22; padding: 5px 8px; margin: 0; }
    .sub  { font-size: 9px; color: #7f8c8d; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    thead th { background: #e67e22; color: white; padding: 4px 6px; font-size: 8px; text-transform: uppercase; text-align: left; }
    td { padding: 3px 6px; border-bottom: 1px solid #f0f0f0; }
    .tr { text-align: right; }
    .tfoot td { background: #fef9f0; font-weight: bold; padding: 5px 6px; border-top: 2px solid #f0b27a; }
    .page-break { page-break-before: always; }
    .footer { font-size: 8px; color: #aaa; text-align: center; margin-top: 14px; }
    .badge { font-size: 7px; background: #fdebd0; color: #784212; padding: 1px 5px; border-radius: 3px; }
</style>
</head>
<body>

<?php if ($seccion === 'ambas' || $seccion === 'facturas'): ?>
<h1>🧾 Facturas Recibidas — Compras</h1>
<p class="sub">Período: <?= date('d/m/Y', strtotime($desde)) ?> — <?= date('d/m/Y', strtotime($hasta)) ?> &nbsp;|&nbsp; Generado: <?= date('d/m/Y H:i') ?></p>

<table style="margin-bottom:12px">
    <tr>
        <td style="background:#fef9f0;border:1px solid #f0b27a;padding:8px 14px;text-align:center;width:33%">
            <div style="font-size:8px;color:#7f8c8d;text-transform:uppercase">Nº Facturas</div>
            <div style="font-size:14px;font-weight:bold;color:#e67e22"><?= count($tickets) ?></div>
        </td>
        <td style="background:#fef9f0;border:1px solid #f0b27a;padding:8px 14px;text-align:center;width:33%">
            <div style="font-size:8px;color:#7f8c8d;text-transform:uppercase">Base Imponible</div>
            <div style="font-size:14px;font-weight:bold;color:#e67e22"><?= number_format($total_base_comp, 2, ',', '.') ?> €</div>
        </td>
        <td style="background:#fef9f0;border:1px solid #f0b27a;padding:8px 14px;text-align:center;width:34%">
            <div style="font-size:8px;color:#7f8c8d;text-transform:uppercase">Total Compras</div>
            <div style="font-size:14px;font-weight:bold;color:#e67e22"><?= number_format($total_comp, 2, ',', '.') ?> €</div>
        </td>
    </tr>
</table>

<table>
    <thead>
        <tr>
            <th style="width:10%">Fecha</th>
            <th style="width:30%">Concepto</th>
            <th style="width:24%">Proveedor</th>
            <th style="width:12%">NIF/CIF</th>
            <th style="width:8%"  class="tr">Base (€)</th>
            <th style="width:7%"  class="tr">IVA (€)</th>
            <th style="width:9%"  class="tr">Total (€)</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($tickets)): ?>
        <tr><td colspan="7" style="text-align:center;padding:16px;color:#aaa">Sin facturas de compra en el período.</td></tr>
    <?php else: foreach ($tickets as $t): ?>
        <tr>
            <td><?= date('d/m/Y', strtotime($t['fecha'])) ?></td>
            <td><?= htmlspecialchars($t['concepto']) ?></td>
            <td style="font-weight:bold"><?= htmlspecialchars($t['proveedor']) ?></td>
            <td><?= htmlspecialchars($t['nif_cif']) ?></td>
            <td class="tr"><?= number_format($t['base_imponible'], 2, ',', '.') ?></td>
            <td class="tr"><?= number_format($t['cuota_iva'],      2, ',', '.') ?></td>
            <td class="tr" style="font-weight:bold"><?= number_format($t['total'], 2, ',', '.') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4" style="text-align:right">TOTALES</td>
            <td class="tr"><?= number_format($total_base_comp, 2, ',', '.') ?></td>
            <td class="tr"><?= number_format($total_iva_comp,  2, ',', '.') ?></td>
            <td class="tr"><?= number_format($total_comp,      2, ',', '.') ?></td>
        </tr>
    </tfoot>
</table>
<?php endif; ?>

<?php if ($seccion === 'ambas'): ?>
<div class="page-break"></div>
<?php endif; ?>

<?php if ($seccion === 'ambas' || $seccion === 'proveedores'): ?>
<h1>🏭 Listado de Proveedores</h1>
<p class="sub">Total: <?= count($proveedores) ?> proveedores &nbsp;|&nbsp; Generado: <?= date('d/m/Y H:i') ?></p>
<table>
    <thead>
        <tr>
            <th style="width:26%">Nombre fiscal</th>
            <th style="width:12%">NIF/CIF</th>
            <th style="width:21%">Email</th>
            <th style="width:11%">Teléfono</th>
            <th style="width:13%">Ciudad</th>
            <th style="width:10%">País</th>
            <th style="width:7%">Cuenta</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($proveedores)): ?>
        <tr><td colspan="7" style="text-align:center;padding:16px;color:#aaa">No hay proveedores.</td></tr>
    <?php else: foreach ($proveedores as $p): ?>
        <tr>
            <td style="font-weight:bold"><?= htmlspecialchars($p['nombre_fiscal']) ?></td>
            <td><?= htmlspecialchars($p['nif_cif']) ?></td>
            <td><?= htmlspecialchars($p['email'] ?? '—') ?></td>
            <td><?= htmlspecialchars($p['telefono'] ?? '—') ?></td>
            <td><?= htmlspecialchars($p['ciudad'] ?? '—') ?></td>
            <td><?= htmlspecialchars($p['pais']) ?></td>
            <td><span class="badge"><?= htmlspecialchars($p['cuenta_contable']) ?></span></td>
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
$options->set('isRemoteEnabled', false);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$nombre = match($seccion) {
    'facturas'    => 'Facturas_Recibidas_' . date('Y') . '.pdf',
    'proveedores' => 'Listado_Proveedores_' . date('Y') . '.pdf',
    default       => 'Compras_y_Proveedores_' . date('Y') . '.pdf',
};
$dompdf->stream($nombre, ['Attachment' => true]);
