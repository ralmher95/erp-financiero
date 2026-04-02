<?php
// views/facturacion/listar_facturas.php
require_once __DIR__ . '/../../config/db_connect.php';

// MEJORA #6 — Filtros opcionales por cliente y fecha
$filtro_cliente = trim($_GET['cliente'] ?? '');
$filtro_desde   = trim($_GET['desde']   ?? '');
$filtro_hasta   = trim($_GET['hasta']   ?? '');
$filtro_tipo    = trim($_GET['tipo']    ?? ''); // 'emitida', 'recibida' o ''

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
if ($filtro_tipo !== '') {
    $donde[]  = 'f.tipo = ?';
    $params[] = $filtro_tipo;
}

$clausula_where = $donde ? 'WHERE ' . implode(' AND ', $donde) : '';

$stmt = $pdo->prepare(
    "SELECT f.id, f.tipo, f.numero_serie, f.numero_factura, f.fecha_emision,
            f.base_imponible, f.cuota_iva, f.total,
            COALESCE(c.nombre_fiscal, p.nombre_fiscal) AS nombre_entidad
     FROM facturas f
     LEFT JOIN clientes c ON f.cliente_id = c.id
     LEFT JOIN proveedores p ON f.proveedor_id = p.id
     $clausula_where
     ORDER BY f.fecha_emision DESC, f.numero_factura DESC"
);
$stmt->execute($params);
$facturas = $stmt->fetchAll();

// Cálculo de totales desglosados
$total_emitidas = 0;
$total_recibidas = 0;
foreach ($facturas as $f) {
    if ($f['tipo'] === 'emitida') $total_emitidas += (float)$f['total'];
    else $total_recibidas += (float)$f['total'];
}

$hay_filtro = $filtro_cliente || $filtro_desde || $filtro_hasta || $filtro_tipo;
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
            <label>Tipo</label>
            <select name="tipo">
                <option value="">Todas</option>
                <option value="emitida" <?= $filtro_tipo === 'emitida' ? 'selected' : '' ?>>📤 Emitidas</option>
                <option value="recibida" <?= $filtro_tipo === 'recibida' ? 'selected' : '' ?>>📥 Recibidas</option>
            </select>
        </div>
        <div class="campo">
            <label id="label-entidad">
                <?php 
                if ($filtro_tipo === 'emitida') echo 'Cliente';
                elseif ($filtro_tipo === 'recibida') echo 'Proveedor';
                else echo 'Entidad (Cliente/Prov.)';
                ?>
            </label>
            <input type="text" name="cliente" placeholder="Buscar..."
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
                <th>Tipo</th><th>Nº Factura</th><th>Entidad</th><th>Fecha</th>
                <th class="text-right">Base</th>
                <th class="text-right">IVA</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($facturas)): ?>
            <tr>
                <td colspan="7" class="empty-msg">
                    <?= $hay_filtro ? 'No hay facturas que coincidan con los filtros aplicados.' : 'No hay facturas registradas.' ?>
                </td>
            </tr>
        <?php else: foreach ($facturas as $f): 
            $es_emitida = $f['tipo'] === 'emitida';
            $clase_tipo = $es_emitida ? 'tipo-emitida' : 'tipo-recibida';
            $icon_tipo  = $es_emitida ? '📤' : '📥';
        ?>
            <tr>
                <td><span class="badge <?= $clase_tipo ?>"><?= $icon_tipo ?> <?= ucfirst($f['tipo']) ?></span></td>
                <td class="n-factura"><?= htmlspecialchars($f['numero_serie']) ?>/<?= $f['numero_factura'] ?></td>
                <td><?= htmlspecialchars($f['nombre_entidad'] ?? 'N/A') ?></td>
                <td><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></td>
                <td class="text-right"><?= number_format($f['base_imponible'], 2, ',', '.') ?> €</td>
                <td class="text-right"><?= number_format($f['cuota_iva'],      2, ',', '.') ?> €</td>
                <td class="text-right"><strong><?= number_format($f['total'],  2, ',', '.') ?> €</strong></td>
            </tr>
        <?php endforeach; ?>
            <?php if ($filtro_tipo !== 'recibida'): ?>
            <tr class="total-row total-emitida">
                <td colspan="6" class="text-right">TOTAL EMITIDAS (VENTAS):</td>
                <td class="text-right"><?= number_format($total_emitidas, 2, ',', '.') ?> €</td>
            </tr>
            <?php endif; ?>
            <?php if ($filtro_tipo !== 'emitida'): ?>
            <tr class="total-row total-recibida">
                <td colspan="6" class="text-right">TOTAL RECIBIDAS (GASTOS):</td>
                <td class="text-right"><?= number_format($total_recibidas, 2, ',', '.') ?> €</td>
            </tr>
            <?php endif; ?>
            <?php if ($filtro_tipo === ''): ?>
            <tr class="total-row total-balance">
                <td colspan="6" class="text-right">BALANCE NETO (Ventas - Gastos):</td>
                <td class="text-right"><strong><?= number_format($total_emitidas - $total_recibidas, 2, ',', '.') ?> €</strong></td>
            </tr>
            <?php endif; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
<?php // Busca la carpeta 'includes' partiendo de la base de Laragon de forma absoluta  
require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php';  ?>      
</html>
