<?php
// =============================================================================
// views/clientes/clientes.php
// Módulo principal de gestión de clientes.
//
// CORRECCIONES aplicadas:
//   FIX #3 — csrf_verify() añadido en acción 'guardar' (antes solo en 'eliminar')
//   FIX #3 — csrf_field() añadido al formulario de alta de cliente
//
// FUNCIONALIDADES:
//   · Exportar CSV de todos los clientes
//   · Alta de nuevo cliente (con CSRF, validación y control de NIF duplicado)
//   · Eliminación de cliente (con CSRF y confirmación JS)
//   · KPIs: total clientes, con email, con teléfono
//   · Búsqueda en tiempo real con debounce (420 ms)
//   · Ordenación por columna con whitelist anti-SQLi
//   · Paginación (50 registros por página)
// =============================================================================

// Conexión PDO (proporciona $pdo)
require_once __DIR__ . '/../../config/db_connect.php';

// Helpers: csrf_field(), csrf_verify(), e(), prepararLike(), etc.
require_once __DIR__ . '/../../includes/helpers.php';

// Variables de estado para la vista
$errores = [];
$exito   = false;

// =============================================================================
// EXPORTAR CSV
// Si llega ?export=csv, volcamos todos los clientes y terminamos.
// =============================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    // Consulta sin filtros: exportamos TODOS los clientes ordenados por nombre
    $todos = $pdo->query(
        "SELECT id, nombre_fiscal, nif_cif, email, telefono,
                direccion, ciudad, codigo_postal, provincia, pais
         FROM clientes
         ORDER BY nombre_fiscal ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Forzamos la descarga del fichero CSV
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clientes_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 para compatibilidad con Excel

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Nombre Fiscal', 'NIF/CIF', 'Email', 'Teléfono', 'Dirección', 'Ciudad', 'CP', 'Provincia', 'País'], ';');
    foreach ($todos as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit; // Cortamos para no renderizar HTML después de los headers del CSV
}

// =============================================================================
// GUARDAR NUEVO CLIENTE
// POST con accion=guardar desde el formulario de alta.
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {

    // FIX #3: Verificamos el token CSRF antes de tocar ningún dato.
    // Sin esto, un atacante podría crear clientes desde otro dominio.
    csrf_verify();

    // Saneamos todos los campos del formulario
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

    // Solo comprobamos duplicados si el formulario está limpio
    if (empty($errores)) {

        // Comprobamos si ya existe un cliente con ese NIF/CIF
        $check = $pdo->prepare("SELECT id FROM clientes WHERE nif_cif = :nif");
        $check->execute([':nif' => $nif]);

        if ($check->fetch()) {
            $errores[] = "Ya existe un cliente con el NIF/CIF <strong>"
                       . htmlspecialchars($nif) . "</strong>.";
        } else {
            try {
                // INSERT con parámetros nombrados (prevención de SQL Injection)
                $stmt = $pdo->prepare(
                    "INSERT INTO clientes
                        (nombre_fiscal, nif_cif, email, telefono,
                         direccion, ciudad, codigo_postal, provincia, pais)
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

                log_erp('INFO', 'clientes', "Cliente creado: NIF $nif");
                $exito = true;

            } catch (PDOException $e) {
                // Error de BD: loguear en detalle, mostrar mensaje genérico
                log_erp('ERROR', 'clientes', 'Error al insertar cliente: ' . $e->getMessage());
                $errores[] = 'Error al guardar el cliente. Inténtalo de nuevo.';
            }
        }
    }
}

// =============================================================================
// ELIMINAR CLIENTE
// POST con accion=eliminar desde el botón de la tabla.
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {

    // CSRF ya estaba bien implementado aquí en el original
    csrf_verify();

    $id_eliminar = (int)($_POST['id'] ?? 0);

    if ($id_eliminar > 0) {
        try {
            $pdo->prepare("DELETE FROM clientes WHERE id = :id")
                ->execute([':id' => $id_eliminar]);
            log_erp('INFO', 'clientes', "Cliente eliminado: ID $id_eliminar");
        } catch (PDOException $e) {
            log_erp('ERROR', 'clientes', 'Error al eliminar cliente: ' . $e->getMessage());
        }
    }

    // Patrón PRG: redirigimos siempre tras DELETE para evitar reenvíos
    header('Location: clientes.php?deleted=1');
    exit;
}

// =============================================================================
// KPIs — Estadísticas de la cabecera del módulo
// =============================================================================
$kpis = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN email    != '' AND email    IS NOT NULL THEN 1 ELSE 0 END) AS con_email,
        SUM(CASE WHEN telefono != '' AND telefono IS NOT NULL THEN 1 ELSE 0 END) AS con_telefono
     FROM clientes"
)->fetch(PDO::FETCH_ASSOC);

// =============================================================================
// LISTADO CON BÚSQUEDA, ORDENACIÓN Y PAGINACIÓN
// =============================================================================

// Parámetros GET
$busqueda   = trim($_GET['q']   ?? '');
$sort_col   = $_GET['sort']     ?? 'nombre_fiscal';
$sort_dir   = strtoupper($_GET['dir'] ?? 'ASC');
$por_pagina = 50;
$pagina     = max(1, (int)($_GET['pagina'] ?? 1));
$offset     = ($pagina - 1) * $por_pagina;

// Whitelist de columnas para ORDER BY (previene SQL Injection en columna dinámica)
$allowed_cols = ['nombre_fiscal', 'nif_cif', 'ciudad', 'email'];
if (!in_array($sort_col, $allowed_cols, true)) $sort_col = 'nombre_fiscal';
if (!in_array($sort_dir, ['ASC', 'DESC'],  true)) $sort_dir = 'ASC';

// Construcción dinámica del WHERE
$sql_base      = "FROM clientes";
$where_clauses = [];
$params        = [];

if ($busqueda !== '') {
    // Un solo parámetro :q reutilizado en varias columnas
    $where_clauses[] = "(nombre_fiscal LIKE :q OR nif_cif LIKE :q OR ciudad LIKE :q OR email LIKE :q)";
    $params[':q']    = "%{$busqueda}%";
}

$sql_where = $where_clauses ? "WHERE " . implode(' AND ', $where_clauses) : "";

// Total de registros para calcular la paginación
$stmt_count = $pdo->prepare("SELECT COUNT(*) $sql_base $sql_where");
foreach ($params as $key => $val) {
    $stmt_count->bindValue($key, $val);
}
$stmt_count->execute();
$total_registros = (int)$stmt_count->fetchColumn();
$total_paginas   = (int)ceil($total_registros / $por_pagina);

// Consulta de datos paginados
$sql_select = "SELECT id, nombre_fiscal, nif_cif, email, telefono, ciudad, codigo_postal, pais
               $sql_base $sql_where
               ORDER BY $sort_col $sort_dir
               LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql_select);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
// LIMIT y OFFSET con PDO::PARAM_INT para que no se entrecomillen
$stmt->bindValue(':limit',  $por_pagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,     PDO::PARAM_INT);
$stmt->execute();
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Título de pestaña para layout_header.php
$titulo = 'Clientes';
require_once __DIR__ . '/../../includes/layout_header.php';

?>

<!-- =========================================================================
     ESTILOS LOCALES DEL MÓDULO DE CLIENTES
     El acento de color es azul (#1a73e8) para diferenciar de proveedores (naranja).
     ========================================================================= -->
<style><?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?></style>

<!-- =========================================================================
     CONTENIDO PRINCIPAL
     ========================================================================= -->
<div class="erp-page">
<div class="erp-inner">

    <!-- Alertas de feedback -->
    <?php if ($exito): ?>
        <div class="alerta alerta-ok">✅ Cliente registrado correctamente.</div>
    <?php endif; ?>
    <?php if (!empty($errores)): ?>
        <div class="alerta alerta-err">
            <strong>⛔ Corrige los siguientes errores:</strong>
            <ul><?php foreach ($errores as $e) echo "<li>$e</li>"; ?></ul>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alerta alerta-ok">🗑️ Cliente eliminado correctamente.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="alerta alerta-ok">✅ Cliente actualizado correctamente.</div>
    <?php endif; ?>

    <!-- Cabecera -->
    <div class="page-hdr">
        <h1>👥 Clientes</h1>
        <a href="clientes.php?export=csv" class="btn-csv">⬇️ Exportar CSV</a>
    </div>

    <!-- KPIs del módulo -->
    <div class="kpi-strip">
        <div class="kpi-box">
            <span class="kpi-label">Total clientes</span>
            <span class="kpi-valor"><?= (int)$kpis['total'] ?></span>
        </div>
        <div class="kpi-box">
            <span class="kpi-label">Con email</span>
            <span class="kpi-valor"><?= (int)$kpis['con_email'] ?></span>
        </div>
        <div class="kpi-box">
            <span class="kpi-label">Con teléfono</span>
            <span class="kpi-valor"><?= (int)$kpis['con_telefono'] ?></span>
        </div>
    </div>

    <!-- Toolbar: buscador + botón nuevo -->
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
            <button
                class="btn-limpiar-busqueda <?= $busqueda ? 'visible' : '' ?>"
                id="btnLimpiarBusqueda"
                type="button"
                title="Limpiar búsqueda"
            >✕</button>
        </div>
        <button class="toggle-form-btn <?= !empty($errores) ? 'abierto' : '' ?>" id="toggleFormBtn" type="button">
            <span class="arrow">＋</span> Nuevo cliente
        </button>
    </div>

    <!-- Formulario colapsable de alta -->
    <div class="form-collapsible <?= !empty($errores) ? 'abierto' : '' ?>" id="formCollapsible">
        <div class="form-card">
            <h3>➕ Añadir nuevo cliente</h3>
            <form method="POST">
                <!-- Campo oculto para identificar la acción en el handler PHP -->
                <input type="hidden" name="accion" value="guardar">

                <!-- FIX #3: Token CSRF necesario para proteger el formulario de alta -->
                <?php csrf_field(); ?>

                <div class="form-grid">
                    <div class="campo">
                        <label for="c_nombre_fiscal">Nombre Fiscal *</label>
                        <!-- Repoblamos si hubo error para no perder lo escrito -->
                        <input type="text" name="nombre_fiscal" id="c_nombre_fiscal" maxlength="150" required
                               value="<?= htmlspecialchars($_POST['nombre_fiscal'] ?? '') ?>"
                               placeholder="Razón social o nombre">
                    </div>
                    <div class="campo">
                        <label for="c_nif_cif">NIF / CIF *</label>
                        <input type="text" name="nif_cif" id="c_nif_cif" maxlength="20" required
                               value="<?= htmlspecialchars($_POST['nif_cif'] ?? '') ?>"
                               placeholder="B12345678">
                    </div>
                    <div class="campo">
                        <label for="c_email">Email</label>
                        <input type="email" name="email" id="c_email"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               placeholder="contacto@empresa.com">
                    </div>
                    <div class="campo">
                        <label for="c_telefono">Teléfono</label>
                        <input type="text" name="telefono" id="c_telefono"
                               value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>"
                               placeholder="600 000 000">
                    </div>
                    <div class="campo">
                        <label for="c_direccion">Dirección</label>
                        <input type="text" name="direccion" id="c_direccion"
                               value="<?= htmlspecialchars($_POST['direccion'] ?? '') ?>">
                    </div>
                    <div class="campo">
                        <label for="c_ciudad">Ciudad</label>
                        <input type="text" name="ciudad" id="c_ciudad"
                               value="<?= htmlspecialchars($_POST['ciudad'] ?? '') ?>">
                    </div>
                    <div class="campo">
                        <label for="c_codigo_postal">Código Postal</label>
                        <input type="text" name="codigo_postal" id="c_codigo_postal" maxlength="10"
                               value="<?= htmlspecialchars($_POST['codigo_postal'] ?? '') ?>">
                    </div>
                    <div class="campo">
                        <label for="c_provincia">Provincia</label>
                        <input type="text" name="provincia" id="c_provincia"
                               value="<?= htmlspecialchars($_POST['provincia'] ?? '') ?>">
                    </div>
                    <div class="campo">
                        <label for="c_pais">País</label>
                        <input type="text" name="pais" id="c_pais"
                               value="<?= htmlspecialchars($_POST['pais'] ?? 'España') ?>">
                    </div>
                </div>

                <div class="form-footer">
                    <button type="submit" class="toggle-form-btn" style="background:var(--c-verde)">
                        💾 Guardar cliente
                    </button>
                    <button type="button" id="cancelarFormBtn"
                            style="background:none;border:1.5px solid var(--c-border);color:#64748b;border-radius:8px;padding:9px 16px;font-size:14px;cursor:pointer;">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla de clientes -->
    <div class="tabla-card">
        <div class="tabla-scroll">
            <table class="tabla-clientes">
                <thead>
                    <tr>
                        <!-- Cabeceras clicables para ordenar -->
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
                <?php if (empty($clientes)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <span>👤</span>
                                <p>
                                    <?= $busqueda
                                        ? 'No se encontraron clientes con "' . htmlspecialchars($busqueda) . '".'
                                        : 'No hay clientes registrados aún.' ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($clientes as $c): ?>
                    <tr>
                        <td data-label="Nombre">
                            <span class="nombre-cliente"><?= htmlspecialchars($c['nombre_fiscal']) ?></span>
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
                                <!-- Editar: GET, no necesita CSRF -->
                                <a href="editar_cliente.php?id=<?= $c['id'] ?>" class="btn-sm btn-editar">✏️ Editar</a>

                                <!-- Eliminar: POST con confirmación JS y token CSRF -->
                                <form method="POST" style="display:inline"
                                      onsubmit="return confirm('¿Eliminar a <?= htmlspecialchars($c['nombre_fiscal'], ENT_QUOTES) ?>?')">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <?php csrf_field(); ?>
                                    <button type="submit" class="btn-sm btn-eliminar">🗑️ Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <?php if ($total_paginas > 1 || $busqueda): ?>
        <div class="paginacion">
            <span class="info">
                Pág. <?= $pagina ?>/<?= $total_paginas ?> · <?= $total_registros ?>
                <?= $total_registros === 1 ? 'cliente' : 'clientes' ?>
            </span>

            <?php
            // Construimos la URL conservando los filtros activos
            $query_params = [];
            if ($busqueda)              $query_params['q']    = $busqueda;
            if ($sort_col !== 'nombre_fiscal') $query_params['sort'] = $sort_col;
            if ($sort_dir !== 'ASC')    $query_params['dir']  = $sort_dir;

            // Helper local para generar URL de página
            function build_url_cli(array $bp, int $page): string {
                $bp['pagina'] = $page;
                return '?' . http_build_query($bp);
            }
            ?>

            <?php if ($pagina > 1): ?>
                <a href="<?= build_url_cli($query_params, $pagina - 1) ?>" class="pag-btn">← Ant.</a>
            <?php endif; ?>

            <?php for ($p = max(1, $pagina - 2); $p <= min($total_paginas, $pagina + 2); $p++): ?>
                <a href="<?= build_url_cli($query_params, $p) ?>"
                   class="pag-btn <?= $p === $pagina ? 'activo' : '' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>

            <?php if ($pagina < $total_paginas): ?>
                <a href="<?= build_url_cli($query_params, $pagina + 1) ?>" class="pag-btn">Sig. →</a>
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

    const toggleBtn   = document.getElementById('toggleFormBtn');
    const cancelBtn   = document.getElementById('cancelarFormBtn');
    const collapsible = document.getElementById('formCollapsible');
    const campo       = document.getElementById('campoBusqueda');
    const btnLimpiar  = document.getElementById('btnLimpiarBusqueda');

    // ── Toggle del formulario colapsable ────────────────────────────────────
    function abrirForm()  {
        collapsible.classList.add('abierto');
        toggleBtn.classList.add('abierto');
        // El CSS rota el icono "＋" 45° para mostrar "✕" sin cambiar el carácter
        toggleBtn.querySelector('.arrow').textContent = '＋';
    }
    function cerrarForm() {
        collapsible.classList.remove('abierto');
        toggleBtn.classList.remove('abierto');
    }

    toggleBtn.addEventListener('click', () =>
        collapsible.classList.contains('abierto') ? cerrarForm() : abrirForm()
    );
    if (cancelBtn) cancelBtn.addEventListener('click', cerrarForm);

    // Si hubo errores de validación PHP, abrimos el formulario automáticamente
    <?php if (!empty($errores)): ?> abrirForm(); <?php endif; ?>

    // ── Búsqueda con debounce ────────────────────────────────────────────────
    let timer = null;

    campo.addEventListener('input', function () {
        // Mostramos u ocultamos el botón limpiar según el contenido del campo
        btnLimpiar.classList.toggle('visible', this.value.length > 0);

        // Esperamos 420 ms después de la última tecla para no spamear el servidor
        clearTimeout(timer);
        timer = setTimeout(() => {
            const params = new URLSearchParams(window.location.search);
            if (this.value.trim()) {
                params.set('q', this.value.trim());
                params.delete('pagina'); // Volvemos a pág 1 al cambiar la búsqueda
            } else {
                params.delete('q');
            }
            window.location.href = '?' + params.toString();
        }, 420);
    });

    // Botón limpiar: borra el campo y elimina el filtro de la URL
    btnLimpiar.addEventListener('click', function () {
        campo.value = '';
        this.classList.remove('visible');
        const params = new URLSearchParams(window.location.search);
        params.delete('q');
        params.delete('pagina');
        window.location.href = '?' + params.toString();
    });

    // Si el formulario está cerrado, ponemos el foco en el buscador
    if (!collapsible.classList.contains('abierto')) {
        campo.focus();
    }
})();
</script>
</body>
<?php // Busca la carpeta 'includes' partiendo de la base de Laragon de forma absoluta  
require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php';  ?>
</html>    

