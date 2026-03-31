<?php
// =============================================================================
// src/Controllers/export/guardar_pdf.php
// Genera y envía al navegador el PDF de una factura concreta.
//
// CORRECCIONES aplicadas:
//   FIX #2 — Tabla 'factura_lineas' → 'lineas_factura' (nombre correcto en schema.sql)
//   FIX #2 — Columna 'nif' → 'nif_cif' en la query de clientes
//   FIX #2 — isRemoteEnabled cambiado a false (seguridad en producción)
//
// FLUJO:
//   1. Recibe ?id=N por GET
//   2. Carga la factura y sus líneas desde BD
//   3. Construye un HTML con los datos
//   4. Dompdf lo convierte a PDF y lo envía al navegador para visualizar
// =============================================================================

// Cargamos el autoloader de Composer (incluye Dompdf)
require_once __DIR__ . '/../../vendor/autoload.php';

// Conexión PDO (proporciona $pdo)
require_once __DIR__ . '/../../config/db_connect.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// ── 1. VALIDACIÓN DEL ID DE FACTURA ─────────────────────────────────────────
// filter_input con FILTER_VALIDATE_INT devuelve false/null si no es entero válido
$id_factura = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_factura) {
    // Si el ID no es válido, cortamos con un mensaje de error claro
    http_response_code(400);
    die("Error: ID de factura no válido o inexistente.");
}

// ── 2. CARGA DE DATOS DE LA FACTURA ─────────────────────────────────────────
// FIX #2: columna corregida nif_cif (la tabla clientes usa nif_cif, no nif)
$query = "SELECT
              f.*,
              c.nombre_fiscal AS cliente_nombre,
              c.nif_cif       AS nif,
              c.direccion
          FROM facturas f
          JOIN clientes c ON f.cliente_id = c.id
          WHERE f.id = ?";

$stmt = $pdo->prepare($query);
$stmt->execute([$id_factura]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);

// Si no existe la factura, cortamos
if (!$factura) {
    http_response_code(404);
    die("Error: Factura no localizada en el sistema.");
}

// ── 3. CARGA DE LÍNEAS DE DETALLE ────────────────────────────────────────────
// FIX #2: nombre correcto de la tabla es 'lineas_factura', no 'factura_lineas'
// El schema.sql define: CREATE TABLE lineas_factura (...)
$stmt_lineas = $pdo->prepare("SELECT * FROM lineas_factura WHERE factura_id = ?");
$stmt_lineas->execute([$id_factura]);
$lineas = $stmt_lineas->fetchAll(PDO::FETCH_ASSOC);

// ── 4. CONSTRUCCIÓN DEL HTML PARA EL PDF ────────────────────────────────────
// Usamos output buffering para capturar el HTML y pasarlo a Dompdf
ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        /* Estilos optimizados para renderizado PDF con Dompdf (DejaVu/Helvetica) */
        body {
            font-family: 'Helvetica', sans-serif;
            font-size: 12px;
            color: #333;
            margin: 0;
            padding: 20px;
        }

        /* Cabecera de la factura */
        .header {
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .header h1 {
            color: #2c3e50;
            font-size: 20px;
            margin: 0 0 4px;
        }
        .header p { margin: 2px 0; color: #555; }

        /* Datos del cliente */
        .cliente {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 12px;
        }
        .cliente strong { color: #2c3e50; }

        /* Tabla de líneas de factura */
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            margin-bottom: 20px;
        }
        .table th {
            background: #2c3e50;
            color: white;
            padding: 8px 10px;
            text-align: left;
            font-size: 11px;
        }
        .table td {
            border: 1px solid #dee2e6;
            padding: 7px 10px;
            font-size: 11px;
        }
        .table tr:nth-child(even) td { background: #f8f9fa; }
        .text-right { text-align: right; }

        /* Bloque de totales (derecha) */
        .totals {
            float: right;
            width: 35%;
            margin-top: 10px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
            font-size: 12px;
        }
        .totals-row.grand {
            font-size: 14px;
            font-weight: bold;
            border-top: 2px solid #2c3e50;
            border-bottom: none;
            margin-top: 4px;
            padding-top: 8px;
        }

        /* Pie de página fijo en la parte inferior */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            font-size: 9px;
            text-align: center;
            color: #aaa;
            padding: 6px 0;
            border-top: 1px solid #eee;
        }
    </style>
</head>
<body>

    <!-- Cabecera con número y fecha de la factura -->
    <div class="header">
        <h1>FACTURA: <?= htmlspecialchars($factura['numero_serie']) ?>/<?= htmlspecialchars((string)$factura['numero_factura']) ?></h1>
        <p>Fecha de emisión: <?= date('d/m/Y', strtotime($factura['fecha_emision'])) ?></p>
    </div>

    <!-- Datos del cliente receptor de la factura -->
    <div class="cliente">
        <strong>Cliente:</strong> <?= htmlspecialchars($factura['cliente_nombre']) ?><br>
        <strong>NIF/CIF:</strong> <?= htmlspecialchars($factura['nif'] ?? '—') ?><br>
        <strong>Dirección:</strong> <?= htmlspecialchars($factura['direccion'] ?? '—') ?>
    </div>

    <!-- Tabla de líneas de detalle -->
    <table class="table">
        <thead>
            <tr>
                <th style="width:40%">Descripción</th>
                <th style="width:10%" class="text-right">Cant.</th>
                <th style="width:15%" class="text-right">Precio €</th>
                <th style="width:10%" class="text-right">IVA %</th>
                <th style="width:15%" class="text-right">Total €</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lineas as $l): ?>
            <tr>
                <td><?= htmlspecialchars($l['descripcion']) ?></td>
                <td class="text-right"><?= number_format((float)$l['cantidad'],        2, ',', '.') ?></td>
                <td class="text-right"><?= number_format((float)$l['precio_unitario'], 2, ',', '.') ?> €</td>
                <td class="text-right"><?= htmlspecialchars((string)$l['tipo_iva']) ?>%</td>
                <td class="text-right"><?= number_format((float)$l['total'],           2, ',', '.') ?> €</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totales en bloque flotante a la derecha -->
    <div class="totals">
        <div class="totals-row">
            <span>Base Imponible:</span>
            <span><?= number_format((float)$factura['base_imponible'], 2, ',', '.') ?> €</span>
        </div>
        <div class="totals-row">
            <span>IVA:</span>
            <span><?= number_format((float)$factura['cuota_iva'], 2, ',', '.') ?> €</span>
        </div>
        <div class="totals-row grand">
            <span>TOTAL:</span>
            <span><?= number_format((float)$factura['total'], 2, ',', '.') ?> €</span>
        </div>
    </div>

    <!-- Pie de página -->
    <div class="footer">
        Documento generado automáticamente por ERP Financiero
    </div>

</body>
</html>
<?php
// Capturamos el HTML generado
$html = ob_get_clean();

// ── 5. CONFIGURACIÓN DE DOMPDF ───────────────────────────────────────────────
$options = new Options();
// FIX #2: isRemoteEnabled = false por seguridad (evita SSRF en producción).
// Solo activar a true si necesitas cargar imágenes remotas en el PDF.
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Helvetica');

// ── 6. RENDERIZADO Y ENVÍO DEL PDF ───────────────────────────────────────────
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nombre del fichero descargado: Factura_2025_1.pdf
$nombre_archivo = sprintf(
    'Factura_%s_%s.pdf',
    $factura['numero_serie'],
    $factura['numero_factura']
);

// Attachment = false → abre en el navegador en lugar de forzar descarga
$dompdf->stream($nombre_archivo, ['Attachment' => false]);