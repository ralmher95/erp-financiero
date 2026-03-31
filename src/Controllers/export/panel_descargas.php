<?php
// views/exports/panel_descargas.php
// Incluir en cualquier vista con: require_once __DIR__ . '/../../views/exports/panel_descargas.php';
// O acceder directamente desde el navegador como página independiente.
require_once __DIR__ . '/../../config/db_connect.php';

$anio_actual    = (int)date('Y');
$anios          = range($anio_actual, $anio_actual - 4); // últimos 5 años
$base_export    = URL_BASE . 'src/Controllers/export/';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exportar Documentos PDF — ERP Financiero</title>
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #27ae60;
            --warning: #e67e22; --danger: #e74c3c; --purple: #8e44ad;
            --bg: #f4f6f9;
        }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; background: var(--bg); color: #333; }
        .container { max-width: 960px; margin: 30px auto; padding: 0 20px; }
        h1 { color: var(--primary); margin: 0 0 6px; }
        .intro { color: #7f8c8d; font-size: 14px; margin-bottom: 28px; }

        /* Secciones */
        .seccion { background: white; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,.06);
                   margin-bottom: 24px; overflow: hidden; }
        .seccion-header { padding: 14px 22px; display: flex; align-items: center; gap: 12px; }
        .seccion-header h2 { margin: 0; font-size: 15px; color: white; }
        .seccion-header .badge-num { background: rgba(255,255,255,.25); border-radius: 20px;
                                     font-size: 11px; padding: 2px 9px; color: white; font-weight: 700; }
        .s-contabilidad .seccion-header { background: var(--primary); }
        .s-fiscal       .seccion-header { background: var(--purple); }
        .s-ventas       .seccion-header { background: var(--success); }
        .s-compras      .seccion-header { background: var(--warning); }

        .seccion-body { padding: 18px 22px; }

        /* Filtros */
        .filtros { display: flex; gap: 12px; align-items: flex-end; margin-bottom: 16px; flex-wrap: wrap; }
        .filtros label { font-size: 12px; font-weight: 600; color: #555; display: block; margin-bottom: 4px; }
        .filtros input[type=date],
        .filtros select { padding: 7px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 13px; }

        /* Botones de descarga */
        .btns { display: flex; flex-wrap: wrap; gap: 10px; }
        .btn-pdf {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 18px; border-radius: 6px; font-size: 13px; font-weight: 600;
            text-decoration: none; color: white; cursor: pointer;
            transition: opacity .15s, transform .1s;
            border: none;
        }
        .btn-pdf:hover  { opacity: .88; transform: translateY(-1px); }
        .btn-pdf:active { transform: translateY(0); }
        .btn-pdf svg { width: 15px; height: 15px; flex-shrink: 0; }

        .c-primary  { background: var(--primary); }
        .c-accent   { background: var(--accent); }
        .c-success  { background: var(--success); }
        .c-warning  { background: var(--warning); }
        .c-danger   { background: var(--danger); }
        .c-purple   { background: var(--purple); }

        /* Nota */
        .nota { font-size: 11px; color: #95a5a6; margin-top: 10px; }
        .nota strong { color: #7f8c8d; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container">
    <h1>📥 Exportar documentos en PDF</h1>
    <p class="intro">Descarga los informes contables, fiscales y comerciales del ejercicio en formato PDF.</p>

    <!-- ══════════════════════════════════════
         01 · CONTABILIDAD
    ═══════════════════════════════════════ -->
    <div class="seccion s-contabilidad">
        <div class="seccion-header">
            <span style="font-size:20px">📒</span>
            <h2>01 · Contabilidad <?= $anio_actual ?></h2>
            <span class="badge-num">Diario · Mayor · Balances</span>
        </div>
        <div class="seccion-body">
            <div class="filtros">
                <div>
                    <label>Desde</label>
                    <input type="date" id="cont-desde" value="<?= $anio_actual ?>-01-01">
                </div>
                <div>
                    <label>Hasta</label>
                    <input type="date" id="cont-hasta" value="<?= $anio_actual ?>-12-31">
                </div>
            </div>
            <div class="btns">
                <a class="btn-pdf c-primary" id="btn-diario" href="#" onclick="descargar('pdf_libro_diario.php', {desde:'cont-desde', hasta:'cont-hasta'}, this); return false;">
                    <?= icoPdf() ?> Libro Diario
                </a>
                <a class="btn-pdf c-accent" id="btn-mayor" href="#" onclick="abrirMayor(); return false;">
                    <?= icoPdf() ?> Libro Mayor (por cuenta)
                </a>
                <a class="btn-pdf c-purple" id="btn-balance" href="#" onclick="descargar('pdf_balances.php', {desde:'cont-desde', hasta:'cont-hasta'}, this); return false;">
                    <?= icoPdf() ?> Balance de Sumas y Saldos
                </a>
            </div>

            <!-- Selector cuenta para Libro Mayor -->
            <div id="panel-mayor" style="display:none;margin-top:14px;padding:14px;background:#f8f9fa;border-radius:6px">
                <label style="font-size:12px;font-weight:600;color:#555;display:block;margin-bottom:6px">Selecciona una cuenta contable:</label>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <select id="sel-cuenta" style="padding:7px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;min-width:280px">
                        <option value="">— Elige una cuenta —</option>
                        <?php
                        $cuentas = $pdo->query("SELECT id, codigo_pgc, descripcion FROM cuentas_contables ORDER BY codigo_pgc ASC")->fetchAll();
                        foreach ($cuentas as $c):
                        ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['codigo_pgc']) ?> — <?= htmlspecialchars($c['descripcion']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn-pdf c-accent" onclick="descargarMayor()">
                        <?= icoPdf() ?> Descargar Mayor
                    </button>
                </div>
            </div>

            <p class="nota">Los PDFs se generan con los datos filtrados por el período indicado.</p>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         02 · FISCAL / IVA
    ═══════════════════════════════════════ -->
    <div class="seccion s-fiscal">
        <div class="seccion-header">
            <span style="font-size:20px">🧾</span>
            <h2>02 · Fiscal — IVA</h2>
            <span class="badge-num">Modelos Trimestrales · Libros de IVA</span>
        </div>
        <div class="seccion-body">
            <div class="filtros">
                <div>
                    <label>Año</label>
                    <select id="iva-anio">
                        <?php foreach ($anios as $a): ?>
                            <option value="<?= $a ?>" <?= $a === $anio_actual ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Trimestre</label>
                    <select id="iva-trimestre">
                        <option value="0">Anual completo</option>
                        <option value="1">T1 — Enero / Marzo</option>
                        <option value="2">T2 — Abril / Junio</option>
                        <option value="3">T3 — Julio / Septiembre</option>
                        <option value="4">T4 — Octubre / Diciembre</option>
                    </select>
                </div>
            </div>
            <div class="btns">
                <a class="btn-pdf c-purple" href="#" onclick="descargarIva('ambos'); return false;">
                    <?= icoPdf() ?> Liquidación IVA + Libros
                </a>
                <a class="btn-pdf c-accent" href="#" onclick="descargarIva('repercutido'); return false;">
                    <?= icoPdf() ?> Solo Libro IVA Repercutido
                </a>
                <a class="btn-pdf c-success" href="#" onclick="descargarIva('soportado'); return false;">
                    <?= icoPdf() ?> Solo Libro IVA Soportado
                </a>
            </div>
            <p class="nota">El PDF incluye el resumen de liquidación, el Libro de IVA Repercutido y el Libro de IVA Soportado.</p>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         03 · VENTAS
    ═══════════════════════════════════════ -->
    <div class="seccion s-ventas">
        <div class="seccion-header">
            <span style="font-size:20px">📑</span>
            <h2>03 · Ventas</h2>
            <span class="badge-num">Facturas Emitidas · Listado de Clientes</span>
        </div>
        <div class="seccion-body">
            <div class="filtros">
                <div>
                    <label>Desde</label>
                    <input type="date" id="ven-desde" value="<?= $anio_actual ?>-01-01">
                </div>
                <div>
                    <label>Hasta</label>
                    <input type="date" id="ven-hasta" value="<?= $anio_actual ?>-12-31">
                </div>
            </div>
            <div class="btns">
                <a class="btn-pdf c-success" href="#" onclick="descargar('pdf_ventas.php', {desde:'ven-desde', hasta:'ven-hasta', seccion:'facturas'}, this); return false;">
                    <?= icoPdf() ?> Facturas Emitidas
                </a>
                <a class="btn-pdf c-accent" href="#" onclick="descargar('pdf_ventas.php', {seccion:'clientes'}, this); return false;">
                    <?= icoPdf() ?> Listado de Clientes
                </a>
                <a class="btn-pdf c-primary" href="#" onclick="descargar('pdf_ventas.php', {desde:'ven-desde', hasta:'ven-hasta', seccion:'ambas'}, this); return false;">
                    <?= icoPdf() ?> Facturas + Clientes (completo)
                </a>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         04 · COMPRAS
    ═══════════════════════════════════════ -->
    <div class="seccion s-compras">
        <div class="seccion-header">
            <span style="font-size:20px">🏭</span>
            <h2>04 · Compras</h2>
            <span class="badge-num">Facturas Recibidas · Listado de Proveedores</span>
        </div>
        <div class="seccion-body">
            <div class="filtros">
                <div>
                    <label>Desde</label>
                    <input type="date" id="com-desde" value="<?= $anio_actual ?>-01-01">
                </div>
                <div>
                    <label>Hasta</label>
                    <input type="date" id="com-hasta" value="<?= $anio_actual ?>-12-31">
                </div>
            </div>
            <div class="btns">
                <a class="btn-pdf c-warning" href="#" onclick="descargar('pdf_compras.php', {desde:'com-desde', hasta:'com-hasta', seccion:'facturas'}, this); return false;">
                    <?= icoPdf() ?> Facturas Recibidas
                </a>
                <a class="btn-pdf c-accent" href="#" onclick="descargar('pdf_compras.php', {seccion:'proveedores'}, this); return false;">
                    <?= icoPdf() ?> Listado de Proveedores
                </a>
                <a class="btn-pdf c-primary" href="#" onclick="descargar('pdf_compras.php', {desde:'com-desde', hasta:'com-hasta', seccion:'ambas'}, this); return false;">
                    <?= icoPdf() ?> Facturas + Proveedores (completo)
                </a>
            </div>
        </div>
    </div>

</div><!-- /container -->

<script>
const BASE = '<?= URL_BASE ?>src/Controllers/export/';

/**
 * Construye la URL del controlador de exportación con los parámetros
 * de los campos de filtro y abre la descarga en una nueva pestaña.
 */
function descargar(controlador, campos, btn) {
    const params = new URLSearchParams();

    for (const [param, idOValor] of Object.entries(campos)) {
        // Si el valor es el ID de un input/select, leemos su valor actual
        const el = document.getElementById(idOValor);
        params.set(param, el ? el.value : idOValor);
    }

    const url = BASE + controlador + '?' + params.toString();
    window.open(url, '_blank');
}

/** Muestra/oculta el panel de selección de cuenta para el Libro Mayor */
function abrirMayor() {
    const panel = document.getElementById('panel-mayor');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}

/** Descarga el PDF del Libro Mayor para la cuenta seleccionada */
function descargarMayor() {
    const cuentaId = document.getElementById('sel-cuenta').value;
    if (!cuentaId) { alert('Selecciona una cuenta contable primero.'); return; }
    const desde = document.getElementById('cont-desde').value;
    const hasta  = document.getElementById('cont-hasta').value;
    const params = new URLSearchParams({ cuenta_id: cuentaId, desde, hasta });
    window.open(BASE + 'pdf_libro_mayor.php?' + params.toString(), '_blank');
}

/** Descarga el PDF de IVA con el año y trimestre seleccionados */
function descargarIva(seccion) {
    const anio      = document.getElementById('iva-anio').value;
    const trimestre = document.getElementById('iva-trimestre').value;
    const params    = new URLSearchParams({ anio, trimestre, seccion });
    window.open(BASE + 'pdf_iva.php?' + params.toString(), '_blank');
}
</script>
</body>
</html>

<?php
// Función auxiliar: icono PDF inline SVG
function icoPdf(): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="9" y1="13" x2="15" y2="13"/>
        <line x1="9" y1="17" x2="15" y2="17"/>
        <polyline points="9 9 10 9"/>
    </svg>';
}
?>