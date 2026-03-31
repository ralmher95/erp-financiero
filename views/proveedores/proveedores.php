<?php
// =============================================================================
// views/proveedores/proveedores.php
// Módulo de gestión de proveedores.
//
// CORRECCIONES aplicadas:
//   FIX #4 — csrf_verify() añadido en acción 'guardar' (antes solo en 'eliminar')
//   FIX #4 — csrf_field() añadido al formulario de alta de proveedor
//
// FUNCIONALIDADES:
//   · Exportar CSV de proveedores
//   · Alta de nuevo proveedor (con CSRF, validación y control de duplicados)
//   · Eliminación de proveedor (con CSRF y confirmación JS)
//   · KPIs: total, con email, con teléfono, cobertura email
//   · Búsqueda en tiempo real con debounce
//   · Ordenación por columna (whitelist anti-SQLi)
//   · Paginación
// =============================================================================

declare(strict_types=1);


// Cargamos la configuración de la aplicación (URL_BASE, APP_NAME, etc.)
require_once __DIR__ . '/../../config/app.php';

// Conexión PDO a la base de datos
require_once __DIR__ . '/../../config/db_connect.php';

// Logger centralizado (log_erp)
require_once __DIR__ . '/../../includes/logger.php';

// Helpers: funciones csrf_field(), csrf_verify(), htmlspecialchars wrapper, etc.
require_once __DIR__ . '/../../includes/helpers.php';

// Variables de estado para el formulario de alta
$errores = [];
$exito   = false;

// =============================================================================
// EXPORTAR CSV
// Si se recibe ?export=csv, volcamos todos los proveedores y terminamos.
// =============================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    // Consulta sin filtros: exportamos TODOS los proveedores ordenados por nombre
    $todos = $pdo->query(
        "SELECT id, nombre_fiscal, nif_cif, email, telefono, direccion,
                ciudad, codigo_postal, provincia, pais
         FROM proveedores
         ORDER BY nombre_fiscal ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Cabeceras HTTP para forzar descarga del fichero CSV
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="proveedores_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 para que Excel abra correctamente los tildes

    $out = fopen('php://output', 'w');
    // Primera fila: cabeceras de columna
    fputcsv($out, ['ID', 'Nombre Fiscal', 'NIF/CIF', 'Email', 'Teléfono', 'Dirección', 'Ciudad', 'CP', 'Provincia', 'País'], ';');
    // Filas de datos
    foreach ($todos as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit; // Importante: cortar la ejecución para no renderizar HTML después
}

// =============================================================================
// GUARDAR NUEVO PROVEEDOR
// Se activa cuando el formulario de alta envía POST con accion=guardar.
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {

    // FIX #4: Verificamos el token CSRF antes de procesar nada.
    // Sin esta llamada, un atacante podría crear proveedores desde otro dominio.
    csrf_verify();

    // Saneamos todos los campos del formulario con trim para eliminar espacios
    $nombre    = trim($_POST['nombre_fiscal'] ?? '');
    $nif       = trim($_POST['nif_cif']       ?? '');
    $email     = trim($_POST['email']         ?? '');
    $telefono  = trim($_POST['telefono']      ?? '');
    $direccion = trim($_POST['direccion']     ?? '');
    $ciudad    = trim($_POST['ciudad']        ?? '');
    $cp        = trim($_POST['codigo_postal'] ?? '');
    $provincia = trim($_POST['provincia']     ?? '');
    $pais      = trim($_POST['pais']          ?? 'España');

    // Validaciones básicas: campos obligatorios y formato de email
    if ($nombre === '') $errores[] = 'El nombre fiscal es obligatorio.';
    if ($nif    === '') $errores[] = 'El NIF/CIF es obligatorio.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El formato del email no es válido.';
    }

    // Solo intentamos insertar si no hay errores de validación
    if (empty($errores)) {

        // Comprobamos si ya existe un proveedor con ese NIF/CIF (unique constraint)
        $check = $pdo->prepare("SELECT id FROM proveedores WHERE nif_cif = :nif");
        $check->execute([':nif' => $nif]);

        if ($check->fetch()) {
            // NIF duplicado: informamos al usuario sin exponer datos crudos
            $errores[] = "Ya existe un proveedor con el NIF/CIF <strong>"
                       . htmlspecialchars($nif) . "</strong>.";
        } else {
            try {
                // Insertamos con parámetros nombrados (prevención de SQL Injection)
                $stmt = $pdo->prepare(
                    "INSERT INTO proveedores
                        (nombre_fiscal, nif_cif, email, telefono, direccion,
                         ciudad, codigo_postal, provincia, pais)
                     VALUES
                        (:nom, :nif, :ema, :tel, :dir, :ciu, :cp, :pro, :pai)"
                );
                $stmt->execute([
                    ':nom' => $nombre,
                    ':nif' => $nif,
                    ':ema' => $email,
                    ':tel' => $telefono,
                    ':dir' => $direccion,
                    ':ciu' => $ciudad,
                    ':cp'  => $cp,
                    ':pro' => $provincia,
                    ':pai' => $pais,
                ]);

                // Registramos el evento en el log del servidor
                log_erp('INFO', 'proveedores', "Proveedor creado: NIF $nif");
                $exito = true;

            } catch (PDOException $e) {
                // Error de BD: lo logamos en detalle pero mostramos mensaje genérico al usuario
                log_erp('ERROR', 'proveedores', 'Error al insertar proveedor: ' . $e->getMessage());
                $errores[] = 'Error al guardar el proveedor. Inténtalo de nuevo.';
            }
        }
    }
}

// =============================================================================
// ELIMINAR PROVEEDOR
// Se activa cuando el formulario de eliminación envía POST con accion=eliminar.
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {

    // Verificamos CSRF (ya estaba correcto en el original)
    csrf_verify();

    // Casteamos a int para prevenir cualquier inyección en el ID
    $id_eliminar = (int)($_POST['id'] ?? 0);

    if ($id_eliminar > 0) {
        try {
            $pdo->prepare("DELETE FROM proveedores WHERE id = :id")
                ->execute([':id' => $id_eliminar]);
            log_erp('INFO', 'proveedores', "Proveedor eliminado: ID $id_eliminar");
        } catch (PDOException $e) {
            log_erp('ERROR', 'proveedores', 'Error al eliminar proveedor: ' . $e->getMessage());
        }
    }

    // Redirigimos siempre tras POST para evitar reenvíos del formulario (PRG pattern)
    header('Location: proveedores.php?deleted=1');
    exit;
}

// =============================================================================
// KPIs — Estadísticas rápidas para las tarjetas del dashboard
// =============================================================================
$kpis = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN email    != '' AND email    IS NOT NULL THEN 1 ELSE 0 END) AS con_email,
        SUM(CASE WHEN telefono != '' AND telefono IS NOT NULL THEN 1 ELSE 0 END) AS con_telefono
     FROM proveedores"
)->fetch(PDO::FETCH_ASSOC);

// =============================================================================
// LISTADO CON BÚSQUEDA, ORDENACIÓN Y PAGINACIÓN
// =============================================================================

// Parámetros de búsqueda y paginación desde la URL (GET)
$busqueda   = trim($_GET['q']      ?? '');
$sort_col   = $_GET['sort']        ?? 'nombre_fiscal';
$sort_dir   = strtoupper($_GET['dir'] ?? 'ASC');
$por_pagina = 50;
$pagina     = max(1, (int)($_GET['pagina'] ?? 1));
$offset     = ($pagina - 1) * $por_pagina;

// Whitelist de columnas permitidas para ORDER BY (previene SQL Injection)
$allowed_cols = ['nombre_fiscal', 'nif_cif', 'ciudad', 'email'];
if (!in_array($sort_col, $allowed_cols, true)) $sort_col = 'nombre_fiscal';
if (!in_array($sort_dir, ['ASC', 'DESC'],  true)) $sort_dir = 'ASC';

// Construcción dinámica del WHERE según si hay búsqueda o no
$sql_base      = "FROM proveedores";
$where_clauses = [];
$params        = [];

if ($busqueda !== '') {
    // Un único parámetro :q reutilizado en varias columnas (LIKE seguro)
    $where_clauses[] = "(nombre_fiscal LIKE :q OR nif_cif LIKE :q OR ciudad LIKE :q OR email LIKE :q)";
    $params[':q']    = "%{$busqueda}%";
}

$sql_where = $where_clauses ? "WHERE " . implode(' AND ', $where_clauses) : "";

// Query de conteo para calcular total de páginas
$stmt_count = $pdo->prepare("SELECT COUNT(*) $sql_base $sql_where");
foreach ($params as $key => $val) {
    $stmt_count->bindValue($key, $val);
}
$stmt_count->execute();
$total_registros = (int)$stmt_count->fetchColumn();
$total_paginas   = (int)ceil($total_registros / $por_pagina);

// Query principal con ORDER BY de whitelist y LIMIT/OFFSET para paginación
$sql_select = "SELECT id, nombre_fiscal, nif_cif, email, telefono, ciudad, codigo_postal, pais
               $sql_base $sql_where
               ORDER BY $sort_col $sort_dir
               LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql_select);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
// LIMIT y OFFSET necesitan PDO::PARAM_INT para que el driver no los entrecomille
$stmt->bindValue(':limit',  $por_pagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,     PDO::PARAM_INT);
$stmt->execute();
$proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Título de la página (lo consume layout_header.php)
$titulo = 'Proveedores';
require_once __DIR__ . '/../../includes/layout_header.php';

?>

<!-- =========================================================================
     ESTILOS LOCALES DEL MÓDULO
     Extienden el styles.css global. Variables CSS reutilizan los del tema.
     ========================================================================= -->
<style><?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?>
</style>

<!-- =========================================================================
     CONTENIDO PRINCIPAL
     ========================================================================= -->
<div class="erp-page">
<div class="erp-inner">

    <!-- Alertas de feedback tras operaciones POST (patrón PRG) -->
    <?php if ($exito): ?>
        <div class="alerta alerta-ok">✅ Proveedor añadido correctamente.</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alerta alerta-ok">🗑️ Proveedor eliminado correctamente.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="alerta alerta-ok">✏️ Proveedor actualizado correctamente.</div>
    <?php endif; ?>
    <?php if (!empty($errores)): ?>
        <div class="alerta alerta-err">
            <strong>⛔ Corrige los siguientes errores:</strong>
            <ul>
                <?php foreach ($errores as $e) echo "<li>$e</li>"; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Cabecera con título y botón de exportación -->
    <div class="page-hdr">
        <h1>📑 Proveedores</h1>
        <div class="hdr-actions">
            <a href="?export=csv" class="btn-csv">⬇️ Exportar CSV</a>
        </div>
    </div>

    <!-- KPIs: métricas rápidas del módulo -->
    <div class="kpi-strip">
        <div class="kpi-box">
            <span class="kpi-label">Total proveedores</span>
            <span class="kpi-valor"><?= number_format((int)$kpis['total'], 0, ',', '.') ?></span>
            <span class="kpi-sub">registrados</span>
        </div>
        <div class="kpi-box verde">
            <span class="kpi-label">Con email</span>
            <span class="kpi-valor"><?= number_format((int)$kpis['con_email'], 0, ',', '.') ?></span>
            <span class="kpi-sub">contactables por correo</span>
        </div>
        <div class="kpi-box naranja">
            <span class="kpi-label">Con teléfono</span>
            <span class="kpi-valor"><?= number_format((int)$kpis['con_telefono'], 0, ',', '.') ?></span>
            <span class="kpi-sub">contactables por teléfono</span>
        </div>
        <div class="kpi-box morado">
            <span class="kpi-label">Cobertura email</span>
            <!-- Evitamos división por cero si no hay proveedores -->
            <span class="kpi-valor">
                <?= $kpis['total'] > 0 ? round(($kpis['con_email'] / $kpis['total']) * 100) : 0 ?>%
            </span>
            <span class="kpi-sub">del total</span>
        </div>
    </div>

    <!-- Toolbar: buscador con debounce + contador de resultados + botón nuevo -->
    <div class="toolbar">
        <div class="search-wrap">
            <span class="ico">🔍</span>
            <input
                type="text"
                id="campoBusqueda"
                placeholder="Buscar por nombre, NIF, ciudad o email…"
                value="<?= htmlspecialchars($busqueda) ?>"
                autocomplete="off"
            >
            <!-- Botón limpiar búsqueda: visible solo si hay texto -->
            <button
                class="btn-limpiar-busqueda <?= $busqueda ? 'visible' : '' ?>"
                id="btnLimpiarBusqueda"
                type="button"
                title="Limpiar búsqueda"
            >✕</button>
        </div>
        <span id="contadorResultados">
            <?= $total_registros ?> <?= $total_registros === 1 ? 'proveedor' : 'proveedores' ?>
            <?= $busqueda ? 'encontrados' : '' ?>
        </span>
        <button class="toggle-form-btn" id="toggleFormBtn" type="button">
            <span class="arrow">＋</span> Nuevo proveedor
        </button>
    </div>

    <!-- Formulario colapsable de alta de proveedor -->
    <!-- Se abre automáticamente si hay errores de validación o tras guardar -->
    <div class="form-collapsible <?= (!empty($errores)) ? 'abierto' : '' ?>" id="formCollapsible">
        <div class="form-card">
            <h3>➕ Nuevo proveedor</h3>
            <form method="POST" id="formNuevoProveedor" novalidate>
                <!-- Campo oculto para identificar la acción en el handler PHP -->
                <input type="hidden" name="accion" value="guardar">

                <!-- FIX #4: Token CSRF obligatorio para proteger el formulario de alta -->
                <?php csrf_field(); ?>

                <div class="form-grid">
                    <div class="campo">
                        <label for="p_nombre_fiscal">Nombre fiscal *</label>
                        <!-- Repoblamos el campo si hubo error para no perder lo escrito -->
                        <input type="text" name="nombre_fiscal" id="p_nombre_fiscal" required
                               value="<?= htmlspecialchars($_POST['nombre_fiscal'] ?? '') ?>"
                               placeholder="Razón social o nombre">
                    </div>
                    <div class="campo">
                        <label for="p_nif_cif">NIF / CIF *</label>
                        <input type="text" name="nif_cif" id="p_nif_cif" maxlength="20" required
                               value="<?= htmlspecialchars($_POST['nif_cif'] ?? '') ?>"
                               placeholder="B12345678">
                    </div>
                    <div class="campo">
                        <label for="p_email">Email</label>
                        <input type="email" name="email" id="p_email"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               placeholder="contacto@empresa.com">
                    </div>
                    <div class="campo">
                        <label for="p_telefono">Teléfono</label>
                        <input type="text" name="telefono" id="p_telefono"
                               value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>"
                               placeholder="600 000 000">
                    </div>
                    <div class="campo">
                        <label for="p_direccion">Dirección</label>
                        <input type="text" name="direccion" id="p_direccion"
                               value="<?= htmlspecialchars($_POST['direccion'] ?? '') ?>">
                    </div>
                    <div class="campo">
                        <label for="p_ciudad">Ciudad</label>
                        <input type="text" name="ciudad" id="p_ciudad"
                               value="<?= htmlspecialchars($_POST['ciudad'] ?? '') ?>">
                    </div>
                    <div class="campo">
                        <label for="p_codigo_postal">Código Postal</label>
                        <input type="text" name="codigo_postal" id="p_codigo_postal" maxlength="10"
                               value="<?= htmlspecialchars($_POST['codigo_postal'] ?? '') ?>">
                    </div>
                    <div class="campo">
                        <label for="p_provincia">Provincia</label>
                        <input type="text" name="provincia" id="p_provincia"
                               value="<?= htmlspecialchars($_POST['provincia'] ?? '') ?>">
                    </div>
                    <div class="campo">
                        <label for="p_pais">País</label>
                        <input type="text" name="pais" id="p_pais"
                               value="<?= htmlspecialchars($_POST['pais'] ?? 'España') ?>">
                    </div>
                </div>

                <div class="form-footer">
                    <button type="submit" class="toggle-form-btn" style="background:var(--c-verde)">
                        💾 Guardar proveedor
                    </button>
                    <button type="button" id="cancelarFormBtn"
                            style="background:none;border:1.5px solid var(--c-border);color:#64748b;border-radius:8px;padding:9px 16px;font-size:14px;cursor:pointer;">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla de proveedores con ordenación por columna -->
    <div class="tabla-card">
        <div class="tabla-scroll">
            <table class="tabla-proveedores" id="tablaProveedores">
                <thead>
                    <tr>
                        <!-- Cabeceras clicables para ordenar. El parámetro dir se invierte para toggle. -->
                        <th>
                            <a href="?sort=nombre_fiscal&dir=<?= $sort_dir === 'ASC' ? 'DESC' : 'ASC' ?>&q=<?= urlencode($busqueda) ?>"
                               style="color:white;text-decoration:none;">
                                Nombre fiscal
                                <?= $sort_col === 'nombre_fiscal' ? ($sort_dir === 'ASC' ? '↑' : '↓') : '' ?>
                            </a>
                        </th>
                        <th>
                            <a href="?sort=nif_cif&dir=<?= $sort_dir === 'ASC' ? 'DESC' : 'ASC' ?>&q=<?= urlencode($busqueda) ?>"
                               style="color:white;text-decoration:none;">
                                NIF / CIF
                                <?= $sort_col === 'nif_cif' ? ($sort_dir === 'ASC' ? '↑' : '↓') : '' ?>
                            </a>
                        </th>
                        <th>Email</th>
                        <th>Teléfono</th>
                        <th>Ciudad</th>
                        <th>CP</th>
                        <th>País</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($proveedores)): ?>
                    <!-- Estado vacío: sin resultados -->
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <span>📦</span>
                                <p>
                                    <?= $busqueda
                                        ? 'No se encontraron proveedores con "' . htmlspecialchars($busqueda) . '".'
                                        : 'No hay proveedores registrados aún.' ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($proveedores as $c): ?>
                    <!-- Fila de datos: data-label para responsive (se muestra con ::before en móvil) -->
                    <tr>
                        <td data-label="Nombre">
                            <span class="nombre-proveedor"><?= htmlspecialchars($c['nombre_fiscal']) ?></span>
                        </td>
                        <td data-label="NIF/CIF">
                            <span class="nif-badge"><?= htmlspecialchars($c['nif_cif']) ?></span>
                        </td>
                        <td data-label="Email">
                            <?php if ($c['email']): ?>
                                <a href="mailto:<?= htmlspecialchars($c['email']) ?>" class="email-link">
                                    <?= htmlspecialchars($c['email']) ?>
                                </a>
                            <?php else: ?>
                                <span class="sin-dato">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Teléfono">
                            <?= $c['telefono'] ? htmlspecialchars($c['telefono']) : '<span class="sin-dato">—</span>' ?>
                        </td>
                        <td data-label="Ciudad">
                            <?= $c['ciudad'] ? htmlspecialchars($c['ciudad']) : '<span class="sin-dato">—</span>' ?>
                        </td>
                        <td data-label="CP">
                            <?= $c['codigo_postal'] ? htmlspecialchars($c['codigo_postal']) : '<span class="sin-dato">—</span>' ?>
                        </td>
                        <td data-label="País">
                            <span class="badge-pais"><?= htmlspecialchars($c['pais'] ?? 'España') ?></span>
                        </td>
                        <td data-label="Acciones">
                            <div class="acciones-td">
                                <!-- Enlace a edición (GET, no necesita CSRF) -->
                                <a href="editar_proveedores.php?id=<?= $c['id'] ?>" class="btn-sm btn-editar">
                                    ✏️ Editar
                                </a>
                                <!-- Formulario de eliminación: confirmación JS + CSRF -->
                                <form method="POST" style="display:inline"
                                      onsubmit="return confirm('¿Eliminar a <?= htmlspecialchars($c['nombre_fiscal'], ENT_QUOTES) ?>?')">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <?php csrf_field(); // Token CSRF para el botón de eliminar ?>
                                    <button type="submit" class="btn-sm btn-eliminar">🗑️ Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginación: solo visible si hay más de una página o hay búsqueda activa -->
        <?php if ($total_paginas > 1 || $busqueda): ?>
        <div class="paginacion">
            <span class="info">
                Pág. <?= $pagina ?>/<?= $total_paginas ?> · <?= $total_registros ?>
                <?= $total_registros === 1 ? 'proveedor' : 'proveedores' ?>
            </span>

            <?php
            // Construimos los parámetros de URL preservando filtros activos
            $query_params = [];
            if ($busqueda)              $query_params['q']    = $busqueda;
            if ($sort_col !== 'nombre_fiscal') $query_params['sort'] = $sort_col;
            if ($sort_dir !== 'ASC')    $query_params['dir']  = $sort_dir;

            // Helper local: genera la URL de una página concreta
            function build_url_proveedores(array $base_params, int $page): string {
                $base_params['pagina'] = $page;
                return '?' . http_build_query($base_params);
            }
            ?>

            <!-- Botón página anterior -->
            <?php if ($pagina > 1): ?>
                <a href="<?= build_url_proveedores($query_params, $pagina - 1) ?>" class="pag-btn">← Ant.</a>
            <?php endif; ?>

            <!-- Ventana de 5 páginas centrada en la actual -->
            <?php for ($p = max(1, $pagina - 2); $p <= min($total_paginas, $pagina + 2); $p++): ?>
                <a href="<?= build_url_proveedores($query_params, $p) ?>"
                   class="pag-btn <?= $p === $pagina ? 'activo' : '' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>

            <!-- Botón página siguiente -->
            <?php if ($pagina < $total_paginas): ?>
                <a href="<?= build_url_proveedores($query_params, $pagina + 1) ?>" class="pag-btn">Sig. →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /erp-inner -->
</div><!-- /erp-page -->

<!-- =========================================================================
     JAVASCRIPT DEL MÓDULO
     ========================================================================= -->
<script>
(function () {
    'use strict';

    // Referencias a elementos del DOM
    const toggleBtn   = document.getElementById('toggleFormBtn');
    const cancelBtn   = document.getElementById('cancelarFormBtn');
    const collapsible = document.getElementById('formCollapsible');
    const campo       = document.getElementById('campoBusqueda');
    const btnLimpiar  = document.getElementById('btnLimpiarBusqueda');

    // ── Toggle del formulario colapsable ────────────────────────────────────
    function abrirForm()  {
        collapsible.classList.add('abierto');
        toggleBtn.classList.add('abierto');
        // Cambiamos el icono visualmente (rotación CSS mediante clase .abierto)
        toggleBtn.querySelector('.arrow').textContent = '＋';
    }
    function cerrarForm() {
        collapsible.classList.remove('abierto');
        toggleBtn.classList.remove('abierto');
    }

    // Click en "Nuevo proveedor": abre o cierra el formulario
    toggleBtn.addEventListener('click', () =>
        collapsible.classList.contains('abierto') ? cerrarForm() : abrirForm()
    );

    // Click en "Cancelar": cierra el formulario
    if (cancelBtn) cancelBtn.addEventListener('click', cerrarForm);

    // Si PHP detectó errores de validación, abrimos el form automáticamente
    <?php if (!empty($errores)): ?> abrirForm(); <?php endif; ?>

    // ── Búsqueda con debounce (420 ms) ──────────────────────────────────────
    let timer = null;

    campo.addEventListener('input', function () {
        // Mostramos/ocultamos el botón de limpiar según haya texto
        btnLimpiar.classList.toggle('visible', this.value.length > 0);

        // Esperamos 420 ms desde la última pulsación antes de lanzar la búsqueda
        clearTimeout(timer);
        timer = setTimeout(() => {
            const params = new URLSearchParams(window.location.search);
            if (this.value.trim()) {
                params.set('q', this.value.trim());
                params.delete('pagina'); // Volvemos a la primera página al buscar
            } else {
                params.delete('q');
            }
            window.location.href = '?' + params.toString();
        }, 420);
    });

    // Botón "✕": limpia el campo y elimina el filtro de búsqueda de la URL
    btnLimpiar.addEventListener('click', function () {
        campo.value = '';
        this.classList.remove('visible');
        const params = new URLSearchParams(window.location.search);
        params.delete('q');
        params.delete('pagina');
        window.location.href = '?' + params.toString();
    });

    // Si el formulario está cerrado, ponemos el foco en el buscador automáticamente
    if (!collapsible.classList.contains('abierto')) {
        campo.focus();
    }
})();
</script>
</body>
<?php // Busca la carpeta 'includes' partiendo de la base de Laragon de forma absoluta  
require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php';?>

