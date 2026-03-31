<?php
// views/contabilidad/libro_diario.php
require_once __DIR__ . '/../../config/db_connect.php';

// MEJORA #9 — UUID v4 con random_bytes (criptográficamente seguro)
// Sustituye la versión anterior con mt_rand que no era segura
function uuid(): string {
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versión 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variante RFC 4122
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$mensaje_ok  = '';
$mensaje_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_asiento'])) {
    $fecha    = trim($_POST['fecha']    ?? '');
    $concepto = trim($_POST['concepto'] ?? '');

    // Validación mínima de cabecera antes de abrir la transacción
    if (!$fecha || !$concepto) {
        $mensaje_err = '❌ La fecha y el concepto son obligatorios.';
    } else {

        try {
            // MEJORA #5 — Todo el asiento en una única transacción atómica.
            // Si falla cualquier INSERT (p.ej. FK inválida), se revierte todo.
            $pdo->beginTransaction();

            $stmt_num = $pdo->prepare(
                "SELECT COALESCE(MAX(numero_asiento), 0) + 1
                 FROM libro_diario
                 WHERE YEAR(fecha) = YEAR(?)"
            );
            $stmt_num->execute([$fecha]);
            $nuevo_n = (int)$stmt_num->fetchColumn();

            $stmt = $pdo->prepare(
                "INSERT INTO libro_diario
                    (uuid, fecha, numero_asiento, cuenta_id, concepto, debe, haber)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $insertados = 0;
            foreach (['debe', 'haber'] as $tipo) {
                if (!empty($_POST[$tipo . '_cuenta'])) {
                    foreach ($_POST[$tipo . '_cuenta'] as $i => $cuenta_id) {
                        if (!empty($cuenta_id) && !empty($_POST[$tipo . '_importe'][$i])) {
                            $imp = (float)str_replace(',', '.', $_POST[$tipo . '_importe'][$i]);
                            $d   = ($tipo === 'debe')  ? $imp : 0.0;
                            $h   = ($tipo === 'haber') ? $imp : 0.0;
                            $stmt->execute([uuid(), $fecha, $nuevo_n, (int)$cuenta_id, $concepto, $d, $h]);
                            $insertados++;
                        }
                    }
                }
            }

            if ($insertados === 0) {
                throw new Exception('El asiento no tiene líneas válidas.');
            }

            $pdo->commit();
            $mensaje_ok = "✅ Asiento #$nuevo_n registrado correctamente.";

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[libro_diario] PDOException: ' . $e->getMessage());
            $mensaje_err = '❌ Error de base de datos al guardar el asiento. Inténtalo de nuevo.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensaje_err = '❌ ' . htmlspecialchars($e->getMessage());
        }
    }
}

$cuentas = $pdo->query(
    "SELECT id, codigo_pgc, descripcion FROM cuentas_contables ORDER BY codigo_pgc ASC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Libro Diario - ERP Financiero</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style><?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?></style>
        
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="main-content">
    <div class="header-section">
        <h1>📖 Registro de Asiento Contable</h1>
    </div>

    <?php if ($mensaje_ok): ?>
        <div class="alerta-ok"><?= $mensaje_ok ?></div>
    <?php endif; ?>
    <?php if ($mensaje_err): ?>
        <div class="alerta-err"><?= $mensaje_err ?></div>
    <?php endif; ?>

    <form method="POST" id="form-asiento">
        <div class="form-row">
            <div class="form-group">
                <label for="fecha">Fecha</label>
                <input type="date" id="fecha" name="fecha" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group" style="flex:3">
                <label for="concepto">Concepto General</label>
                <input type="text" id="concepto" name="concepto" required
                       placeholder="Descripción del movimiento...">
            </div>
        </div>

        <div class="asiento-container">
            <?php foreach (['debe' => ['💰 DEBE', 'blue'], 'haber' => ['📤 HABER', 'red']] as $tipo => [$titulo, $color]): ?>
            <div class="columna <?= $tipo ?>-column">
                <div class="col-header">
                    <h3><?= $titulo ?></h3>
                    <button type="button" class="btn btn-add"
                            onclick="agregarLinea('<?= $tipo ?>')">＋ Línea</button>
                </div>
                <div id="lineas-<?= $tipo ?>">
                    <div class="linea-asiento">
                        <div style="flex:3">
                            <select name="<?= $tipo ?>_cuenta[]" class="js-cuenta" required>
                                <option value="">Seleccionar cuenta...</option>
                                <?php foreach ($cuentas as $c): ?>
                                    <option value="<?= $c['id'] ?>">
                                        <?= $c['codigo_pgc'] ?> - <?= htmlspecialchars($c['descripcion']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex:1">
                            <input type="text" name="<?= $tipo ?>_importe[]"
                                   class="input-importe" placeholder="0.00" required>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div id="barra-totales" class="barra-estado descuadre">
            ⚖️ DEBE: <span id="val-debe">0,00 €</span>
            &nbsp;|&nbsp;
            HABER: <span id="val-haber">0,00 €</span>
        </div>

        <div style="text-align:center">
            <button type="submit" name="guardar_asiento" id="btnGuardar"
                    class="btn btn-submit" disabled>
                REGISTRAR ASIENTO
            </button>
        </div>
    </form>
</div>

<script>
    function initSelect2() {
        $('.js-cuenta').select2({ width: '100%' });
    }
    $(document).ready(() => initSelect2());

    function agregarLinea(tipo) {
        const cont = document.getElementById('lineas-' + tipo);
        const clon = cont.querySelector('.linea-asiento').cloneNode(true);

        // Limpiar valores del clon
        clon.querySelectorAll('input').forEach(i => i.value = '');
        clon.querySelectorAll('select').forEach(s => s.selectedIndex = 0);

        // Botón de eliminar
        const btnElim = document.createElement('button');
        btnElim.type      = 'button';
        btnElim.className = 'btn btn-remove';
        btnElim.textContent = '✕';
        btnElim.onclick = function () { clon.remove(); recalcular(); };
        clon.appendChild(btnElim);

        cont.appendChild(clon);
        initSelect2();
    }
</script>
<script src="<?= URL_BASE ?>public/assets/js/validacion_asientos.js"></script>
</body>     
<?php
// Busca la carpeta 'includes' partiendo de la base de Laragon de forma absoluta
require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php';
?>
</html>
