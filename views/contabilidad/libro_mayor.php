<?php
require_once __DIR__ . '/../../config/db_connect.php';

$cuenta_id = isset($_GET['cuenta_id']) ? (int)$_GET['cuenta_id'] : null;
$cuentas   = $pdo->query("SELECT id, codigo_pgc, descripcion FROM cuentas_contables ORDER BY codigo_pgc ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Libro Mayor - ERP Financiero</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style><?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?></style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container">
    <h1>📋 Libro Mayor</h1>

    <div class="filtro">
        <form method="GET" class="filtro-form">
            <div class="filtro-grupo">
                <label>Selecciona Cuenta Contable:</label>
                <select name="cuenta_id" class="js-mayor" onchange="this.form.submit()">
                    <option value="">-- Buscar cuenta por nombre o código --</option>
                    <?php foreach ($cuentas as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $cuenta_id == $c['id'] ? 'selected' : '' ?>>
                            <?= $c['codigo_pgc'] ?> - <?= htmlspecialchars($c['descripcion']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-buscar">Ver Extracto</button>
        </form>
    </div>

    <?php if ($cuenta_id): ?>
        <?php
        $stmt = $pdo->prepare(
            "SELECT fecha, numero_asiento, concepto, debe, haber
             FROM libro_diario WHERE cuenta_id = ? ORDER BY fecha ASC, numero_asiento ASC"
        );
        $stmt->execute([$cuenta_id]);
        $movimientos = $stmt->fetchAll();
        $saldo = 0;
        ?>
        <table>
            <thead>
                <tr><th>Fecha</th><th>Asiento</th><th>Concepto</th>
                    <th class="text-right">Debe</th><th class="text-right">Haber</th><th class="text-right">Saldo</th></tr>
            </thead>
            <tbody>
            <?php if (empty($movimientos)): ?>
                <tr><td colspan="6" class="empty-msg">Esta cuenta no tiene movimientos registrados.</td></tr>
            <?php else: foreach ($movimientos as $m):
                $saldo += ($m['debe'] - $m['haber']); ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
                    <td>#<?= $m['numero_asiento'] ?></td>
                    <td><?= htmlspecialchars($m['concepto']) ?></td>
                    <td class="text-right"><?= number_format($m['debe'],  2, ',', '.') ?> €</td>
                    <td class="text-right"><?= number_format($m['haber'], 2, ',', '.') ?> €</td>
                    <td class="text-right <?= $saldo >= 0 ? 'pos' : 'neg' ?>"><?= number_format($saldo, 2, ',', '.') ?> €</td>
                </tr>
            <?php endforeach; endif; ?>
                <tr class="total-row">
                    <td colspan="3" class="text-right"><strong>SALDO ACTUAL:</strong></td>
                    <td colspan="3" class="text-right"><strong><?= number_format($saldo, 2, ',', '.') ?> €</strong></td>
                </tr>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-msg">Selecciona una cuenta para ver su historial de movimientos.</div>
    <?php endif; ?>
</div>

<script>
$(document).ready(() => {
    $('.js-mayor').select2({ placeholder: 'Escribe para buscar...', width: '100%' });
});
</script>
</body>
<?php // Busca la carpeta 'includes' partiendo de la base de Laragon de forma absoluta
require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php';  ?>
</html>