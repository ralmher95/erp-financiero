<?php
// includes/navbar.php
$pagina_actual = basename($_SERVER['PHP_SELF']);

function nav_activo(string $pagina, string $pagina_actual): string {
    return $pagina_actual === $pagina ? 'active' : '';
}
?>
<style>
    .navbar-custom {
        background-color: #2c3e50;
        padding: 0.8rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        position: relative;
    }
    .navbar-brand {
        font-size: 20px;
        font-weight: 700;
        color: white;
        text-decoration: none;
        letter-spacing: 0.5px;
    }
    .nav-links { display: flex; gap: 4px; flex-wrap: wrap; }
    .nav-links a {
        color: #ecf0f1;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        padding: 7px 13px;
        border-radius: 6px;
    }
    .nav-links a:hover  { background: #3498db; }
    .nav-links a.active { background: rgba(255,255,255,0.15); }

    /* Botón hamburguesa — oculto por defecto */
    #nav-toggle {
        display: none;
        background: none;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
        padding: 4px 8px;
        line-height: 1;
    }

    @media (max-width: 768px) {
        #nav-toggle { display: block; }
        .nav-links {
            display: none;
            flex-direction: column;
            width: 100%;
            position: absolute;
            top: 100%;
            left: 0;
            background: #2c3e50;
            padding: 10px 20px 16px;
            box-shadow: 0 6px 12px rgba(0,0,0,0.2);
            z-index: 100;
        }
        .nav-links.open { display: flex; }
        .nav-links a { padding: 9px 10px; }
    }
</style>

<nav class="navbar-custom">
    <!-- CORRECCIÓN #1: URL del brand usa URL_BASE en lugar de ruta hardcodeada incorrecta -->
    <a href="<?= URL_BASE ?>public/index.php" class="navbar-brand">📊 ERP Financiero</a>

    <!-- MEJORA #9: Botón hamburguesa para móvil -->
    <button id="nav-toggle" aria-label="Abrir menú">☰</button>

    <div class="nav-links" id="nav-links">
        <a href="<?= URL_BASE ?>public/index.php"
           class="<?= nav_activo('index.php', $pagina_actual) ?>">Inicio</a>
        <a href="<?= URL_BASE ?>views/contabilidad/libro_diario.php"
           class="<?= nav_activo('libro_diario.php', $pagina_actual) ?>">Libro Diario</a>
        <a href="<?= URL_BASE ?>views/contabilidad/libro_mayor.php"
           class="<?= nav_activo('libro_mayor.php', $pagina_actual) ?>">Libro Mayor</a>
        <a href="<?= URL_BASE ?>views/contabilidad/liquidacion_iva.php"
           class="<?= nav_activo('liquidacion_iva.php', $pagina_actual) ?>">Impuestos</a>
        <a href="<?= URL_BASE ?>views/contabilidad/balances.php"
            class="<?= nav_activo('balances.php', $pagina_actual) ?>">Balances</a>
        <a href="<?= URL_BASE ?>views/facturacion/listar_facturas.php"
           class="<?= nav_activo('listar_facturas.php', $pagina_actual) ?>">Facturación</a>
        <a href="<?= URL_BASE ?>views/clientes/clientes.php"
           class="<?= nav_activo('clientes.php', $pagina_actual) ?>">Clientes</a>
        <a href="<?= URL_BASE ?>views/proveedores/proveedores.php"
           class="<?= nav_activo('proveedores.php', $pagina_actual) ?>">Proveedores</a>
        <a href="<?= URL_BASE ?>views/exports/panel_descargas.php"
            class="<?= nav_activo('panel_descargas.php', $pagina_actual) ?>">📥 Exportar PDF</a>
    </div>
</nav>

<script>
(function () {
    const btn   = document.getElementById('nav-toggle');
    const links = document.getElementById('nav-links');
    if (!btn || !links) return;
    btn.addEventListener('click', function () {
        const open = links.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.textContent = open ? '✕' : '☰';
    });
    // Cerrar al hacer clic en un enlace
    links.addEventListener('click', function (e) {
        if (e.target.tagName === 'A') {
            links.classList.remove('open');
            btn.textContent = '☰';
        }
    });
})();
</script>
