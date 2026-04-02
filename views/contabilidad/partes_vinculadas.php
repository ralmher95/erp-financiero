<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/db_connect.php';

$anio = (int)($_GET['anio'] ?? date('Y'));

// 1. Obtener Entidades Vinculadas (Clientes y Proveedores)
$sql_ent = "SELECT 'Cliente' as tipo_e, id, nombre_fiscal, nif_cif FROM clientes WHERE es_vinculado = 1
            UNION
            SELECT 'Proveedor' as tipo_e, id, nombre_fiscal, nif_cif FROM proveedores WHERE es_vinculado = 1";
$vinculadas = $pdo->query($sql_ent)->fetchAll();

$resumen_vinculadas = [];
foreach ($vinculadas as $v) {
    // Buscar facturas vinculadas
    if ($v['tipo_e'] === 'Cliente') {
        $stmt = $pdo->prepare("SELECT COUNT(*) as num, SUM(total) as total FROM facturas WHERE cliente_id = ? AND YEAR(fecha_emision) = ?");
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as num, SUM(total) as total FROM facturas WHERE proveedor_id = ? AND YEAR(fecha_emision) = ?");
    }
    $stmt->execute([$v['id'], $anio]);
    $f_data = $stmt->fetch();

    // Buscar otros asientos manuales marcados como vinculados
    $stmt_asi = $pdo->prepare("SELECT COALESCE(SUM(ABS(debe-haber))/2, 0) FROM libro_diario WHERE es_vinculado = 1 AND (concepto LIKE ? OR concepto LIKE ?) AND YEAR(fecha) = ?");
    $stmt_asi->execute(['%'.$v['nombre_fiscal'].'%', '%'.$v['nif_cif'].'%', $anio]);
    $otros_asientos = (float)$stmt_asi->fetchColumn();

    if ($f_data['num'] > 0 || $otros_asientos > 0) {
        $resumen_vinculadas[] = [
            'nombre' => $v['nombre_fiscal'],
            'nif' => $v['nif_cif'],
            'tipo' => $v['tipo_e'],
            'num_facturas' => $f_data['num'],
            'total_facturas' => (float)$f_data['total'],
            'otros' => $otros_asientos,
            'total_general' => (float)$f_data['total'] + $otros_asientos
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Operaciones con Partes Vinculadas - ERP Financiero</title>
    <style>
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?>
        .vinculadas-card { background: white; padding: 2rem; border-radius: 12px; margin-top: 2rem; }
        .badge-vinculada { background: #ede9fe; color: #5b21b6; padding: 4px 12px; border-radius: 999px; font-weight: 600; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container">
    <header style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1>🤝 Partes Vinculadas</h1>
            <p style="color: #64748b;">Seguimiento de operaciones con socios, administradores y empresas del grupo.</p>
        </div>
        <form method="GET">
            <select name="anio" onchange="this.form.submit()" style="padding: 8px; border-radius: 6px;">
                <?php for($i=date('Y'); $i>=2020; $i--): ?>
                    <option value="<?= $i ?>" <?= $i === $anio ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </header>

    <div class="vinculadas-card">
        <table>
            <thead>
                <tr>
                    <th>Entidad Vinculada</th>
                    <th>NIF</th>
                    <th>Tipo</th>
                    <th class="text-right">Nº Facturas</th>
                    <th class="text-right">Total Facturado</th>
                    <th class="text-right">Otros Asientos</th>
                    <th class="text-right">Total Ejercicio</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($resumen_vinculadas)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:3rem; color:#94a3b8;">No se han detectado operaciones con partes vinculadas en <?= $anio ?>.</td></tr>
                <?php else: foreach($resumen_vinculadas as $r): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['nombre']) ?></strong></td>
                        <td><small><?= htmlspecialchars($r['nif']) ?></small></td>
                        <td><span class="badge-vinculada"><?= $r['tipo'] ?></span></td>
                        <td class="text-right"><?= $r['num_facturas'] ?></td>
                        <td class="text-right"><?= number_format($r['total_facturas'], 2, ',', '.') ?> €</td>
                        <td class="text-right"><?= number_format($r['otros'], 2, ',', '.') ?> €</td>
                        <td class="text-right"><strong><?= number_format($r['total_general'], 2, ',', '.') ?> €</strong></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div style="background: #f8fafc; padding: 1.5rem; border-radius: 8px; margin-top: 2rem; border: 1px solid #e2e8f0;">
        <h4 style="margin-top: 0;">ℹ️ Nota sobre Partes Vinculadas</h4>
        <p style="font-size: 0.9rem; color: #64748b; margin-bottom: 0;">
            Para que una operación aparezca en este informe, debes marcar al Cliente o Proveedor como "Vinculado" en su ficha técnica. El sistema sumará automáticamente todas las facturas y asientos contables asociados a dicho NIF.
        </p>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php'; ?>
</body>
</html>
