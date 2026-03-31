<?php
// =============================================================================
// views/clientes/editar_cliente.php
// Formulario de edición de un cliente existente.
//
// CORRECCIONES aplicadas:
//   FIX #7 — csrf_verify() añadido en el handler POST antes de procesar nada
//   FIX #7 — csrf_field() añadido en el formulario HTML para emitir el token
// =============================================================================

// Conexión PDO (proporciona $pdo)
require_once __DIR__ . '/../../config/db_connect.php';

// Helpers: incluye csrf_field(), csrf_verify(), etc.
require_once __DIR__ . '/../../includes/helpers.php';

// Casteo seguro del ID: si no viene o es 0, redirigimos
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: clientes.php');
    exit;
}

$errores = [];

// ── PROCESAMIENTO DEL FORMULARIO (UPDATE) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // FIX #7: Verificar CSRF antes de tocar cualquier dato.
    // Previene que un sitio externo modifique clientes mediante CSRF.
    csrf_verify();

    // Saneamos todos los campos
    $nombre    = trim($_POST['nombre_fiscal'] ?? '');
    $nif       = trim($_POST['nif_cif']       ?? '');
    $email     = trim($_POST['email']         ?? '');
    $telefono  = trim($_POST['telefono']      ?? '');
    $direccion = trim($_POST['direccion']     ?? '');
    $ciudad    = trim($_POST['ciudad']        ?? '');
    $cp        = trim($_POST['codigo_postal'] ?? '');
    $provincia = trim($_POST['provincia']     ?? '');
    $pais      = trim($_POST['pais']          ?? 'España');

    // Validaciones básicas
    if ($nombre === '') $errores[] = 'El nombre fiscal es obligatorio.';
    if ($nif    === '') $errores[] = 'El NIF/CIF es obligatorio.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El formato del email no es válido.';
    }

    // Comprobamos duplicado de NIF excluyendo el registro actual
    if (empty($errores)) {
        $check = $pdo->prepare("SELECT id FROM clientes WHERE nif_cif = ? AND id != ?");
        $check->execute([$nif, $id]);
        if ($check->fetch()) {
            $errores[] = "Ya existe otro cliente con el NIF/CIF <strong>" . htmlspecialchars($nif) . "</strong>.";
        }
    }

    // Solo ejecutamos el UPDATE si no hay errores
    if (empty($errores)) {
        try {
            $stmt = $pdo->prepare(
                "UPDATE clientes
                 SET nombre_fiscal=?, nif_cif=?, email=?, telefono=?,
                     direccion=?, ciudad=?, codigo_postal=?, provincia=?, pais=?
                 WHERE id=?"
            );
            $stmt->execute([$nombre, $nif, $email, $telefono, $direccion, $ciudad, $cp, $provincia, $pais, $id]);
            // Patrón PRG: redirigimos tras éxito
            header('Location: clientes.php?updated=1');
            exit;
        } catch (PDOException $e) {
            $errores[] = "Error al actualizar la base de datos: " . $e->getMessage();
        }
    }
}

// ── CARGA DE DATOS ACTUALES ──────────────────────────────────────────────────
// Se ejecuta en GET (carga inicial) y en POST con errores
$stmt   = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$id]);
$cliente = $stmt->fetch();

// Si no existe, volvemos al listado
if (!$cliente) {
    header('Location: clientes.php');
    exit;
}

$titulo = 'Editar Cliente';
require_once __DIR__ . '/../../includes/layout_header.php';
?>

<style>
    /* Contenedor centrado */
    .editar-container { max-width: 860px; margin: 30px auto; padding: 0 20px 40px; }

    /* Cabecera con título y botón volver */
    .editar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .editar-header h1 { margin: 0; font-size: 1.4rem; color: #1a2332; }

    /* Tarjeta del formulario con acento azul (color clientes) */
    .form-card { background: white; padding: 28px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); border-top: 3px solid #1a73e8; }

    /* Grid de 2 columnas */
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-bottom: 24px; }

    /* Campo: etiqueta + input apilados */
    .campo { display: flex; flex-direction: column; gap: 6px; }
    .campo label { font-weight: 600; font-size: 13px; color: #475569; }
    .campo input { padding: 10px 12px; border: 1.5px solid #dde3ec; border-radius: 7px; font-size: 14px; background: #f8fafc; transition: border-color .2s, box-shadow .2s; }
    .campo input:focus { outline: none; border-color: #1a73e8; box-shadow: 0 0 0 3px rgba(26,115,232,.12); background: #fff; }

    /* Separador + fila de botones */
    .form-footer { border-top: 1px solid #f1f5f9; padding-top: 20px; display: flex; gap: 12px; }
    .btn { padding: 11px 24px; border: none; border-radius: 7px; cursor: pointer; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-block; transition: opacity .2s; }
    .btn:hover   { opacity: .88; }
    .btn-primary { background: #1a73e8; color: white; }
    .btn-cancel  { background: #ecf0f1; color: #555; border: 1px solid #dde3ec; }

    /* Alerta de errores */
    .alerta-err { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 13px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
    .alerta-err ul { margin: 6px 0 0; padding-left: 20px; }
</style>

<div class="editar-container">

    <div class="editar-header">
        <h1>✏️ Editar cliente</h1>
        <a href="clientes.php" class="btn btn-cancel">← Volver</a>
    </div>

    <!-- Errores de validación (solo visible si hay errores) -->
    <?php if (!empty($errores)): ?>
        <div class="alerta-err">
            <strong>⛔ Corrige los siguientes errores:</strong>
            <ul>
                <?php foreach ($errores as $e) echo "<li>$e</li>"; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <form method="POST">

            <!-- FIX #7: Token CSRF para proteger el formulario de edición -->
            <?php csrf_field(); ?>

            <div class="form-grid">
                <div class="campo">
                    <label for="e_nombre_fiscal">Nombre fiscal *</label>
                    <!--
                        Repoblado dual:
                        · POST disponible (error): $_POST conserva lo que el usuario escribió
                        · GET inicial: $cliente[...] carga el valor guardado en BD
                    -->
                    <input type="text" name="nombre_fiscal" id="e_nombre_fiscal" required
                           value="<?= htmlspecialchars($_POST['nombre_fiscal'] ?? $cliente['nombre_fiscal']) ?>">
                </div>
                <div class="campo">
                    <label for="e_nif_cif">NIF / CIF *</label>
                    <input type="text" name="nif_cif" id="e_nif_cif" required
                           value="<?= htmlspecialchars($_POST['nif_cif'] ?? $cliente['nif_cif']) ?>">
                </div>
                <div class="campo">
                    <label for="e_email">Email</label>
                    <input type="email" name="email" id="e_email"
                           value="<?= htmlspecialchars($_POST['email'] ?? $cliente['email'] ?? '') ?>">
                </div>
                <div class="campo">
                    <label for="e_telefono">Teléfono</label>
                    <input type="text" name="telefono" id="e_telefono"
                           value="<?= htmlspecialchars($_POST['telefono'] ?? $cliente['telefono'] ?? '') ?>">
                </div>
                <div class="campo">
                    <label for="e_direccion">Dirección</label>
                    <input type="text" name="direccion" id="e_direccion"
                           value="<?= htmlspecialchars($_POST['direccion'] ?? $cliente['direccion'] ?? '') ?>">
                </div>
                <div class="campo">
                    <label for="e_ciudad">Ciudad</label>
                    <input type="text" name="ciudad" id="e_ciudad"
                           value="<?= htmlspecialchars($_POST['ciudad'] ?? $cliente['ciudad'] ?? '') ?>">
                </div>
                <div class="campo">
                    <label for="e_codigo_postal">Código Postal</label>
                    <input type="text" name="codigo_postal" id="e_codigo_postal" maxlength="10"
                           value="<?= htmlspecialchars($_POST['codigo_postal'] ?? $cliente['codigo_postal'] ?? '') ?>">
                </div>
                <div class="campo">
                    <label for="e_provincia">Provincia</label>
                    <input type="text" name="provincia" id="e_provincia"
                           value="<?= htmlspecialchars($_POST['provincia'] ?? $cliente['provincia'] ?? '') ?>">
                </div>
                <div class="campo">
                    <label for="e_pais">País</label>
                    <input type="text" name="pais" id="e_pais"
                           value="<?= htmlspecialchars($_POST['pais'] ?? $cliente['pais'] ?? 'España') ?>">
                </div>
            </div><!-- /form-grid -->

            <div class="form-footer">
                <button type="submit" class="btn btn-primary">💾 Guardar Cambios</button>
                <a href="clientes.php" class="btn btn-cancel">Cancelar</a>
            </div>

        </form>
    </div><!-- /form-card -->

</div><!-- /editar-container -->

<?php require_once __DIR__ . '/../../includes/layout_footer.php'; ?>