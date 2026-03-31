<?php
// =============================================================================
// views/proveedores/editar_proveedores.php
// Formulario de edición de un proveedor existente.
//
// CORRECCIONES aplicadas:
//   FIX #7 — csrf_verify() añadido en el handler POST de actualización
//   FIX #7 — csrf_field() añadido en el formulario HTML
// =============================================================================

// Conexión PDO a la base de datos
require_once __DIR__ . '/../../config/db_connect.php';

// Helpers: incluye csrf_field() y csrf_verify()
require_once __DIR__ . '/../../includes/helpers.php';

// ── OBTENER ID DEL PROVEEDOR A EDITAR ──────────────────────────────────────
// Casteamos a int directamente: si no viene o es 0, redirigimos al listado
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: proveedores.php');
    exit;
}

// ── PROCESAMIENTO DEL FORMULARIO (UPDATE) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // FIX #7: Verificamos el token CSRF antes de procesar nada.
    // Previene que un atacante edite proveedores desde otro origen (CSRF).
    csrf_verify();

    $errores = [];

    // Saneamos todos los campos con trim para eliminar espacios accidentales
    $nombre    = trim($_POST['nombre_fiscal']   ?? '');
    $nif       = trim($_POST['nif_cif']         ?? '');
    $email     = trim($_POST['email']           ?? '');
    $telefono  = trim($_POST['telefono']        ?? '');
    $direccion = trim($_POST['direccion']       ?? '');
    $ciudad    = trim($_POST['ciudad']          ?? '');
    $cp        = trim($_POST['codigo_postal']   ?? '');
    $provincia = trim($_POST['provincia']       ?? '');
    $pais      = trim($_POST['pais']            ?? 'España');
    // Cuenta contable: validamos que sea 4000 (Proveedores) o 4100 (Acreedores)
    $cuenta    = trim($_POST['cuenta_contable'] ?? '4000');

    // Validaciones básicas: campos obligatorios y formato de email
    if ($nombre === '') $errores[] = 'El nombre fiscal es obligatorio.';
    if ($nif    === '') $errores[] = 'El NIF/CIF es obligatorio.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El formato del email no es válido.';
    }

    // Comprobamos duplicado de NIF excluyendo el registro actual (AND id != ?)
    if (empty($errores)) {
        $check = $pdo->prepare("SELECT id FROM proveedores WHERE nif_cif = ? AND id != ?");
        $check->execute([$nif, $id]);
        if ($check->fetch()) {
            // Escapamos $nif antes de meterlo en el mensaje HTML
            $errores[] = "Ya existe otro proveedor con el NIF/CIF <strong>"
                       . htmlspecialchars($nif) . "</strong>.";
        }
    }

    // Solo actualizamos si no hay errores de validación ni de unicidad
    if (empty($errores)) {
        $stmt = $pdo->prepare(
            "UPDATE proveedores SET
                nombre_fiscal   = ?,
                nif_cif         = ?,
                email           = ?,
                telefono        = ?,
                direccion       = ?,
                ciudad          = ?,
                codigo_postal   = ?,
                provincia       = ?,
                pais            = ?,
                cuenta_contable = ?
             WHERE id = ?"
        );
        // El orden de parámetros debe coincidir exactamente con el SET y el WHERE
        $stmt->execute([
            $nombre, $nif, $email, $telefono,
            $direccion, $ciudad, $cp, $provincia,
            $pais, $cuenta, $id,
        ]);

        // Redirigimos al listado con mensaje de éxito (patrón Post/Redirect/Get)
        header('Location: proveedores.php?updated=1');
        exit;
    }
}

// ── CARGA DE DATOS ACTUALES DEL PROVEEDOR ──────────────────────────────────
// Se ejecuta en GET (primera carga) y también tras errores de validación en POST
$stmt = $pdo->prepare("SELECT * FROM proveedores WHERE id = ?");
$stmt->execute([$id]);
$proveedor = $stmt->fetch();

// Si el proveedor no existe (ID inválido o eliminado), volvemos al listado
if (!$proveedor) {
    header('Location: proveedores.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Proveedor — ERP Financiero</title>
    <style>
        /* Reset y base */
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; background: #f4f6f9; }

        /* Contenedor centrado de ancho medio */
        .container {
            max-width: 900px;
            margin: 25px auto;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        /* Cabecera con título y botón volver */
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        h1      { color: #2c3e50; margin: 0; }

        /* Grid de campos del formulario: 2 columnas en escritorio */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 18px; }

        /* Grupo campo: etiqueta + input apilados */
        .campo         { display: flex; flex-direction: column; gap: 6px; }
        .campo label   { font-weight: 600; font-size: 13px; color: #555; }
        .campo input,
        .campo select  {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color .2s, box-shadow .2s;
        }
        /* Acento naranja (color de proveedores) en foco */
        .campo input:focus,
        .campo select:focus {
            outline: none;
            border-color: #e67e22;
            box-shadow: 0 0 0 3px rgba(230,126,34,0.15);
        }

        /* Fila de botones inferior */
        .acciones { display: flex; gap: 12px; margin-top: 25px; }

        /* Estilos de botones */
        .btn {
            padding: 11px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            text-decoration: none;
            transition: opacity .2s;
            display: inline-block;
        }
        .btn:hover          { opacity: .88; }
        .btn-primary        { background: #e67e22; color: white; } /* Naranja = proveedores */
        .btn-cancel         { background: #ecf0f1; color: #555; }

        /* Alerta de error de validación */
        .alerta-err {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 12px 18px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alerta-err ul { margin: 5px 0 0; padding-left: 20px; }
    </style>
</head>
<body>

<?php
// Incluimos la barra de navegación compartida
require_once __DIR__ . '/../../includes/navbar.php';
?>

<div class="container">

    <!-- Cabecera de la página -->
    <div class="header">
        <h1>✏️ Editar Proveedor</h1>
        <a href="proveedores.php" class="btn btn-cancel">← Volver</a>
    </div>

    <!-- Errores de validación: solo visible si $errores no está vacío -->
    <?php if (!empty($errores)): ?>
        <div class="alerta-err">
            <strong>⛔ Corrige los siguientes errores:</strong>
            <ul>
                <?php foreach ($errores as $e) echo "<li>$e</li>"; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Formulario de edición -->
    <form method="POST">

        <!-- FIX #7: Token CSRF para proteger el formulario de edición contra ataques CSRF -->
        <?php csrf_field(); ?>

        <div class="form-grid">

            <div class="campo">
                <label for="ep_nombre_fiscal">Nombre fiscal *</label>
                <!--
                    Prioridad para repoblar: primero $_POST (si hubo error),
                    luego el valor de BD (carga inicial).
                -->
                <input type="text" name="nombre_fiscal" id="ep_nombre_fiscal" required
                       value="<?= htmlspecialchars($_POST['nombre_fiscal'] ?? $proveedor['nombre_fiscal']) ?>">
            </div>

            <div class="campo">
                <label for="ep_nif_cif">NIF / CIF *</label>
                <input type="text" name="nif_cif" id="ep_nif_cif" required
                       value="<?= htmlspecialchars($_POST['nif_cif'] ?? $proveedor['nif_cif']) ?>">
            </div>

            <div class="campo">
                <label for="ep_email">Email</label>
                <input type="email" name="email" id="ep_email"
                       value="<?= htmlspecialchars($_POST['email'] ?? $proveedor['email'] ?? '') ?>">
            </div>

            <div class="campo">
                <label for="ep_telefono">Teléfono</label>
                <input type="text" name="telefono" id="ep_telefono"
                       value="<?= htmlspecialchars($_POST['telefono'] ?? $proveedor['telefono'] ?? '') ?>">
            </div>

            <div class="campo">
                <label for="ep_direccion">Dirección</label>
                <input type="text" name="direccion" id="ep_direccion"
                       value="<?= htmlspecialchars($_POST['direccion'] ?? $proveedor['direccion'] ?? '') ?>">
            </div>

            <div class="campo">
                <label for="ep_ciudad">Ciudad</label>
                <input type="text" name="ciudad" id="ep_ciudad"
                       value="<?= htmlspecialchars($_POST['ciudad'] ?? $proveedor['ciudad'] ?? '') ?>">
            </div>

            <div class="campo">
                <label for="ep_codigo_postal">Código Postal</label>
                <input type="text" name="codigo_postal" id="ep_codigo_postal" maxlength="10"
                       value="<?= htmlspecialchars($_POST['codigo_postal'] ?? $proveedor['codigo_postal'] ?? '') ?>">
            </div>

            <div class="campo">
                <label for="ep_provincia">Provincia</label>
                <input type="text" name="provincia" id="ep_provincia"
                       value="<?= htmlspecialchars($_POST['provincia'] ?? $proveedor['provincia'] ?? '') ?>">
            </div>

            <div class="campo">
                <label for="ep_pais">País</label>
                <input type="text" name="pais" id="ep_pais"
                       value="<?= htmlspecialchars($_POST['pais'] ?? $proveedor['pais'] ?? 'España') ?>">
            </div>

            <div class="campo">
                <label for="ep_cuenta_contable">Cuenta contable</label>
                <!--
                    Dropdown de cuenta contable: 4000 = Proveedores, 4100 = Acreedores.
                    El valor seleccionado por defecto es el guardado en BD.
                -->
                <select name="cuenta_contable" id="ep_cuenta_contable">
                    <?php
                    $cuenta_actual = $_POST['cuenta_contable'] ?? $proveedor['cuenta_contable'] ?? '4000';
                    // Obtenemos del catálogo las cuentas de proveedores/acreedores (grupo 4x00)
                    $cuentas_prov = $pdo->query(
                        "SELECT codigo_pgc, descripcion FROM cuentas_contables
                         WHERE codigo_pgc IN ('4000','4100')
                         ORDER BY codigo_pgc ASC"
                    )->fetchAll();
                    foreach ($cuentas_prov as $cp_row):
                    ?>
                        <option value="<?= htmlspecialchars($cp_row['codigo_pgc']) ?>"
                            <?= $cp_row['codigo_pgc'] === $cuenta_actual ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cp_row['codigo_pgc']) ?> —
                            <?= htmlspecialchars($cp_row['descripcion']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div><!-- /form-grid -->

        <!-- Botones de acción -->
        <div class="acciones">
            <button type="submit" class="btn btn-primary">💾 Guardar cambios</button>
            <a href="proveedores.php" class="btn btn-cancel">Cancelar</a>
        </div>

    </form>

</div><!-- /container -->

</body>
</html>