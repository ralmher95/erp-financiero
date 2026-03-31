<?php
// views/facturacion/listar_facturas.php
require_once __DIR__ . '/../../config/db_connect.php';

// MEJORA #6 — Filtros opcionales por cliente y fecha
$filtro_cliente = trim($_GET['cliente'] ?? '');
$filtro_desde   = trim($_GET['desde']   ?? '');
$filtro_hasta   = trim($_GET['hasta']   ?? '');

$donde  = [];
$params = [];

if ($filtro_cliente !== '') {
    $donde[]  = 'c.nombre_fiscal LIKE ?';
    $params[] = '%' . $filtro_cliente . '%';
}
if ($filtro_desde !== '') {
    $donde[]  = 'f.fecha_emision >= ?';
    $params[] = $filtro_desde;
}
if ($filtro_hasta !== '') {
    $donde[]  = 'f.fecha_emision <= ?';
    $params[] = $filtro_hasta;
}

$clausula_where = $donde ? 'WHERE ' . implode(' AND ', $donde) : '';

$stmt = $pdo->prepare(
    "SELECT f.id, f.numero_serie, f.numero_factura, f.fecha_emision,
            f.base_imponible, f.cuota_iva, f.total, c.nombre_fiscal
     FROM facturas f
     JOIN clientes c ON f.cliente_id = c.id
     $clausula_where
     ORDER BY f.fecha_emision DESC, f.numero_factura DESC"
);
$stmt->execute($params);
$facturas = $stmt->fetchAll();

$total_general = array_sum(array_column($facturas, 'total'));
$hay_filtro    = $filtro_cliente || $filtro_desde || $filtro_hasta;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facturas - ERP Financiero</title>
    <style>
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?>
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container">
    <div class="header">
        <h1>📑 Facturación</h1>
        <a href="crear_factura.php" class="btn-nueva">+ Nueva Factura</a>
    </div>

    <?php if (isset($_GET['nueva'])): ?>
        <div class="alerta-ok">✅ Factura <?= htmlspecialchars($_GET['nueva']) ?> creada correctamente.</div>
    <?php endif; ?>

    <!-- MEJORA #6: Formulario de filtros -->
    <form method="GET" class="filtros">
        <div class="campo">
            <label>Cliente</label>
            <input type="text" name="cliente" placeholder="Buscar por nombre..."
                   value="<?= htmlspecialchars($filtro_cliente) ?>">
        </div>
        <div class="campo">
            <label>Desde</label>
            <input type="date" name="desde" value="<?= htmlspecialchars($filtro_desde) ?>">
        </div>
        <div class="campo">
            <label>Hasta</label>
            <input type="date" name="hasta" value="<?= htmlspecialchars($filtro_hasta) ?>">
        </div>
        <button type="submit" class="btn-filtrar">🔍 Filtrar</button>
        <?php if ($hay_filtro): ?>
            <a href="listar_facturas.php" class="btn-limpiar">✕ Limpiar</a>
        <?php endif; ?>
    </form>

    <?php if ($hay_filtro): ?>
        <p class="resultado-filtro">
            Mostrando <strong><?= count($facturas) ?></strong>
            <?= count($facturas) === 1 ? 'factura' : 'facturas' ?> con los filtros aplicados.
        </p>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Nº Factura</th><th>Cliente</th><th>Fecha</th>
                <th class="text-right">Base</th>
                <th class="text-right">IVA</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($facturas)): ?>
            <tr>
                <td colspan="6" class="empty-msg">
                    <?= $hay_filtro ? 'No hay facturas que coincidan con los filtros aplicados.' : 'No hay facturas registradas.' ?>
                </td>
            </tr>
        <?php else: foreach ($facturas as $f): ?>
            <tr>
                <td class="n-factura"><?= htmlspecialchars($f['numero_serie']) ?>/<?= $f['numero_factura'] ?></td>
                <td><?= htmlspecialchars($f['nombre_fiscal']) ?></td>
                <td><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></td>
                <td class="text-right"><?= number_format($f['base_imponible'], 2, ',', '.') ?> €</td>
                <td class="text-right"><?= number_format($f['cuota_iva'],      2, ',', '.') ?> €</td>
                <td class="text-right"><strong><?= number_format($f['total'],  2, ',', '.') ?> €</strong></td>
            </tr>
        <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="5" class="text-right">
                    <?= $hay_filtro ? 'TOTAL FILTRADO:' : 'TOTAL FACTURADO:' ?>
                </td>
                <td class="text-right"><?= number_format($total_general, 2, ',', '.') ?> €</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
<?php // Busca la carpeta 'includes' partiendo de la base de Laragon de forma absoluta  
require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php';  ?>      
</html>
