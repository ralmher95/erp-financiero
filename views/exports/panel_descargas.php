<?php
// views/informes/panel_descargas.php
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php'; // Para validar fechas

$titulo = 'Centro de Descargas';
require_once __DIR__ . '/../../includes/layout_header.php';

// Definimos el año actual para los filtros por defecto
$anio_actual = date('Y');
?>

<style><?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?></style>

<div class="erp-page">
    <div class="erp-inner">
        <div class="page-hdr">
            <h1>📥 Centro de Descargas y Reportes</h1>
            <p style="color: #64748b;">Genera y exporta la información oficial de tu empresa.</p>
        </div>

        <div class="download-grid">
            
            <div class="download-card contabilidad">
                <h3>📖 Contabilidad Oficial</h3>
                
                <div class="filter-row">
                    <label>Periodo:</label>
                    <input type="date" id="fecha_desde" value="<?= $anio_actual ?>-01-01">
                    <input type="date" id="fecha_hasta" value="<?= $anio_actual ?>-12-31">
                </div>

                <div class="report-item">
                    <div class="report-info">
                        <span class="report-name">Libro Diario</span>
                        <span class="report-desc">Registro cronológico de todos los asientos.</span>
                    </div>
                    <div class="report-actions">
                        <button onclick="descargar('pdf_libro_diario.php')" class="btn-download">PDF</button>
                    </div>
                </div>

                <div class="report-item">
                    <div class="report-info">
                        <span class="report-name">Libro Mayor</span>
                        <span class="report-desc">Movimientos desglosados por cuenta PGC.</span>
                    </div>
                    <div class="report-actions">
                        <button onclick="descargar('pdf_libro_mayor.php')" class="btn-download">PDF</button>
                    </div>
                </div>
            </div>

            <div class="download-card facturacion">
                <h3>fac_ Facturación e Impuestos</h3>
                <div class="report-item">
                    <div class="report-info">
                        <span class="report-name">Libro de IVA Soportado/Repercutido</span>
                        <span class="report-desc">Resumen trimestral para modelos fiscales.</span>
                    </div>
                    <div class="report-actions">
                        <button onclick="descargar('pdf_iva.php')" class="btn-download">PDF</button>
                    </div>
                </div>

                <div class="report-item">
                    <div class="report-info">
                        <span class="report-name">Listado de Facturas</span>
                        <span class="report-desc">Exportación completa de facturas emitidas.</span>
                    </div>
                    <div class="report-actions">
                        <button onclick="descargar('pdf_ventas.php?seccion=facturas')" class="btn-download">PDF</button>
                        <button onclick="window.location.href='../facturacion/facturas.php?export=csv'" class="btn-download">CSV</button>
                    </div>
                </div>
            </div>

            <div class="download-card">
                <h3>👥 Entidades y Contactos</h3>
                <div class="report-item">
                    <div class="report-info">
                        <span class="report-name">Base de Datos de Clientes</span>
                        <span class="report-desc">NIF, direcciones y datos de contacto.</span>
                    </div>
                    <div class="report-actions">
                        <a href="../clientes/clientes.php?export=csv" class="btn-download">CSV</a>
                    </div>
                </div>

                <div class="report-item">
                    <div class="report-info">
                        <span class="report-name">Base de Datos de Proveedores</span>
                        <span class="report-desc">Listado completo para gestión de compras.</span>
                    </div>
                    <div class="report-actions">
                        <a href="../proveedores/proveedores.php?export=csv" class="btn-download">CSV</a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
/**
 * Función para centralizar las descargas con parámetros de fecha dinámicos
 */
function descargar(endpoint) {
    const desde = document.getElementById('fecha_desde').value;
    const hasta = document.getElementById('fecha_hasta').value;
    
    // Construimos la URL con los filtros
    const separador = endpoint.includes('?') ? '&' : '?';
    const url = `../../src/Controllers/export/${endpoint}${separador}desde=${desde}&hasta=${hasta}`;
    
    window.location.href = url;
}
</script>

<?php require_once __DIR__ . '/../../includes/layout_footer.php'; ?>