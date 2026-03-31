<?php
// src/Controllers/export/pdf_balances.php
// MEJORA M-01 — Rutas dinámicas con __DIR__ (elimina hardcoding de C:/laragon/...).
// MEJORA M-02 — validarFecha() ahora existe en helpers.php (fix del Fatal error).
// MEJORA M-03 — Header HTTP de seguridad añadido antes de cualquier output.
// MEJORA M-04 — Separación de totales por tipo PGC (activo/pasivo/ingreso/gasto).
// MEJORA M-05 — Nombre del archivo PDF incluye el rango de fechas.
// MEJORA M-06 — Manejo de resultado vacío con mensaje amigable en el PDF.

declare(strict_types=1);

// =============================================================================
// 1. BOOTSTRAPPING — Rutas dinámicas (sin hardcoding de rutas absolutas)
//    __DIR__ apunta a: src/Controllers/export/
//    Tres niveles arriba (../../..) llegamos a la raíz del proyecto.
// =============================================================================
$raizProyecto = dirname(__DIR__, 3); // → C:/laragon/www/PHP/erp-financiero

require_once $raizProyecto . '/vendor/autoload.php';
require_once $raizProyecto . '/config/db_connect.php';
require_once $raizProyecto . '/includes/helpers.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// =============================================================================
// 2. GESTIÓN DE PARÁMETROS GET — Validación con helpers (fix del Fatal error)
//    validarFecha() ahora está definida en includes/helpers.php v3.4
// =============================================================================
$desde = validarFecha($_GET['desde'] ?? '', date('Y-01-01'));
$hasta = validarFecha($_GET['hasta'] ?? '', date('Y-12-31'));

// Sanidad adicional: desde no puede ser mayor que hasta
if ($desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}

// =============================================================================
// 3. OBTENCIÓN DE DATOS — Consulta con partida doble y agrupación por tipo PGC
// =============================================================================
try {
    $query = "
        SELECT
            cc.codigo_pgc,
            cc.descripcion,
            cc.tipo,
            COALESCE(SUM(ld.debe),  0)                   AS t_debe,
            COALESCE(SUM(ld.haber), 0)                   AS t_haber,
            COALESCE(SUM(ld.debe) - SUM(ld.haber), 0)   AS saldo
        FROM cuentas_contables cc
        LEFT JOIN libro_diario ld
               ON ld.cuenta_id = cc.id
              AND ld.fecha BETWEEN :desde AND :hasta
        GROUP BY cc.id, cc.codigo_pgc, cc.descripcion, cc.tipo
        HAVING t_debe > 0 OR t_haber > 0
        ORDER BY cc.codigo_pgc ASC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([':desde' => $desde, ':hasta' => $hasta]);
    $cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // En producción loguear con error_log() en lugar de exponer el mensaje
    error_log('[ERP][pdf_balances] PDOException: ' . $e->getMessage());
    http_response_code(500);
    die('Error interno al obtener los datos contables.');
}

// =============================================================================
// 4. PRE-CÁLCULO DE TOTALES por tipo PGC
//    Esto evita doble recorrido en la plantilla HTML y mantiene la lógica fuera
//    del template (separación de concerns).
// =============================================================================
$granDebe  = 0.0;
$granHaber = 0.0;

// Agrupamos filas por tipo para el resumen final
$porTipo = ['activo' => [], 'pasivo' => [], 'ingreso' => [], 'gasto' => []];

foreach ($cuentas as $c) {
    $granDebe  += (float) $c['t_debe'];
    $granHaber += (float) $c['t_haber'];
    $tipo = $c['tipo'] ?? 'otro';
    if (isset($porTipo[$tipo])) {
        $porTipo[$tipo][] = $c;
    } else {
        $porTipo['activo'][] = $c; // fallback seguro
    }
}

$estaEquilibrado = abs($granDebe - $granHaber) < 0.01;

// Función local de formateo dentro del scope del script (solo para este PDF)
$fmt = static fn(float $n): string => number_format($n, 2, ',', '.');

// =============================================================================
// 5. CONSTRUCCIÓN DEL HTML — Template del PDF
// =============================================================================
ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        /* ── Reset y base ── */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 9.5px;
            color: #2c3e50;
            line-height: 1.45;
        }

        /* ── Cabecera del documento ── */
        .doc-header {
            border-bottom: 3px solid #2c3e50;
            padding-bottom: 10px;
            margin-bottom: 18px;
            text-align: center;
        }
        .doc-header h1 {
            font-size: 16px;
            letter-spacing: 1px;
            color: #1a252f;
            text-transform: uppercase;
        }
        .doc-header p {
            font-size: 10px;
            color: #555;
            margin-top: 4px;
        }

        /* ── Tablas ── */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        thead th {
            background: #2c3e50;
            color: #ffffff;
            padding: 6px 8px;
            text-align: left;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        thead th.num { text-align: right; }

        tbody tr:nth-child(even) { background: #f5f8fa; }
        tbody tr:hover           { background: #ebf5fb; }  /* solo preview web */

        td {
            padding: 4px 8px;
            border-bottom: 1px solid #e8ecef;
            vertical-align: middle;
        }
        td.num   { text-align: right; font-variant-numeric: tabular-nums; }
        td.code  { font-weight: 700; color: #1a252f; width: 70px; }
        td.desc  { max-width: 200px; }

        /* ── Fila de totales ── */
        .tr-total td {
            background: #d6eaf8;
            font-weight: 700;
            border-top: 2px solid #2980b9;
            border-bottom: 2px solid #2980b9;
            padding: 5px 8px;
        }

        /* ── Sección por tipo ── */
        .section-title {
            background: #f0f3f4;
            font-weight: 700;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #7f8c8d;
            padding: 4px 8px;
            border-left: 3px solid #2c3e50;
        }

        /* ── Resultado final ── */
        .resultado {
            margin-top: 20px;
            text-align: right;
            font-size: 11px;
            font-weight: 700;
            padding: 8px 12px;
            border-radius: 4px;
        }
        .cuadrado  { color: #27ae60; background: #eafaf1; border: 1px solid #27ae60; }
        .descuadre { color: #c0392b; background: #fdedec; border: 1px solid #c0392b; }

        /* ── Pie del documento ── */
        .doc-footer {
            margin-top: 24px;
            border-top: 1px solid #bdc3c7;
            padding-top: 6px;
            font-size: 8px;
            color: #95a5a6;
            display: flex;
            justify-content: space-between;
        }
    </style>
</head>
<body>

    <!-- Cabecera -->
    <div class="doc-header">
        <h1>Balance de Sumas y Saldos</h1>
        <p>Período Contable: <strong><?= e($desde) ?></strong> al <strong><?= e($hasta) ?></strong></p>
        <p>Generado el <?= date('d/m/Y \a \l\a\s H:i') ?></p>
    </div>

    <?php if (empty($cuentas)): ?>
        <!-- Estado vacío: sin movimientos en el período -->
        <p style="text-align:center; color:#888; margin-top:40px; font-size:11px;">
            No se registraron movimientos contables en el período seleccionado.
        </p>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th style="width:65px">Cód. PGC</th>
                    <th>Cuenta Contable</th>
                    <th style="width:55px">Tipo</th>
                    <th class="num" style="width:90px">Debe (€)</th>
                    <th class="num" style="width:90px">Haber (€)</th>
                    <th class="num" style="width:90px">Saldo (€)</th>
                </tr>
            </thead>
            <tbody>

                <?php
                // Renderizamos agrupado por tipo PGC (activo → pasivo → ingreso → gasto)
                $etiquetas = [
                    'activo'  => '📦 Cuentas de Activo  (Grupos 1-5)',
                    'pasivo'  => '🏦 Cuentas de Pasivo  (Grupos 1-5)',
                    'ingreso' => '📈 Cuentas de Ingreso (Grupo 7)',
                    'gasto'   => '📉 Cuentas de Gasto   (Grupo 6)',
                ];

                foreach ($etiquetas as $tipo => $label):
                    if (empty($porTipo[$tipo])) continue;
                ?>
                    <!-- Separador de sección -->
                    <tr>
                        <td colspan="6" class="section-title"><?= $label ?></td>
                    </tr>

                    <?php foreach ($porTipo[$tipo] as $c): ?>
                    <tr>
                        <td class="code"><?= e($c['codigo_pgc']) ?></td>
                        <td class="desc"><?= e($c['descripcion']) ?></td>
                        <td><?= e(ucfirst($c['tipo'])) ?></td>
                        <td class="num"><?= $fmt((float)$c['t_debe']) ?></td>
                        <td class="num"><?= $fmt((float)$c['t_haber']) ?></td>
                        <td class="num"><?= $fmt((float)$c['saldo']) ?></td>
                    </tr>
                    <?php endforeach; ?>

                <?php endforeach; ?>

                <!-- Fila de totales globales -->
                <tr class="tr-total">
                    <td colspan="3">TOTALES DEL DIARIO</td>
                    <td class="num"><?= $fmt($granDebe) ?></td>
                    <td class="num"><?= $fmt($granHaber) ?></td>
                    <td class="num"><?= $fmt($granDebe - $granHaber) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Indicador de cuadre -->
        <div class="resultado <?= $estaEquilibrado ? 'cuadrado' : 'descuadre' ?>">
            ESTADO DEL BALANCE:
            <?= $estaEquilibrado
                ? '✔ CUADRADO — Debe = Haber'
                : '✘ DESCUADRE DETECTADO — Diferencia: ' . $fmt(abs($granDebe - $granHaber)) . ' €'
            ?>
        </div>

    <?php endif; ?>

    <!-- Pie -->
    <div class="doc-footer">
        <span>ERP Financiero — Plan General Contable (PGC) Español</span>
        <span>Página 1</span>
    </div>

</body>
</html>
<?php
$html = ob_get_clean();

// =============================================================================
// 6. RENDERIZADO DEL PDF con Dompdf
// =============================================================================
try {
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false); // Seguridad: sin recursos externos
    $options->set('defaultFont', 'Helvetica');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Limpiamos cualquier buffer residual para evitar corrupción del PDF
    if (ob_get_length()) {
        ob_end_clean();
    }

    // MEJORA M-05: Nombre de archivo incluye el rango de fechas para identificación
    $nombreArchivo = sprintf(
        'Balance_Saldos_%s_%s.pdf',
        str_replace('-', '', $desde),
        str_replace('-', '', $hasta)
    );

    $dompdf->stream($nombreArchivo, ['Attachment' => false]);

} catch (\Exception $e) {
    error_log('[ERP][pdf_balances] Dompdf Exception: ' . $e->getMessage());
    http_response_code(500);
    die('Error interno al generar el PDF. Consulta los logs del servidor.');
}