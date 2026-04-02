<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/db_connect.php';

// Seguridad: Token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Obtener clientes y proveedores
try {
    $clientes = $pdo->query("SELECT id, nombre_fiscal, nif_cif FROM clientes ORDER BY nombre_fiscal")->fetchAll();
    $proveedores = $pdo->query("SELECT id, nombre_fiscal, nif_cif FROM proveedores ORDER BY nombre_fiscal")->fetchAll();
} catch (PDOException $e) {
    error_log("Error al obtener entidades: " . $e->getMessage());
    $clientes = [];
    $proveedores = [];
}

// Gestión de mensajes de error
$error_code = $_GET['error'] ?? null;
$mensajes_error = [
    '1'         => '❌ Error interno al guardar la factura.',
    'cliente'   => '❌ El cliente/proveedor seleccionado no es válido.',
    'sinlineas' => '❌ Debes añadir al menos una línea válida a la factura.',
    'duplicado' => '❌ Ya existe una factura con ese número.',
    'csrf'      => '❌ Sesión expirada. Recarga la página.',
    'debug'     => $_SESSION['error_save'] ?? '❌ Error técnico desconocido al guardar.',
];
$error_msg = $mensajes_error[$error_code] ?? null;
// Limpiar el error de sesión después de mostrarlo
if ($error_code === 'debug') unset($_SESSION['error_save']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Crear Factura - ERP Financiero</title>
    <style>
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/public/assets/css/styles.css'; ?>
        
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container">
    <header style="margin-bottom: 2rem;">
        <h1 id="titulo-form">📄 Nueva Factura Emitida</h1>
        <p style="color: #64748b;">Registra facturas de venta o gasto y asienta automáticamente en el libro diario.</p>
    </header>

    <section class="ocr-container" style="background: #f8fafc; border: 2px dashed #4f46e5; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem;">
        <h3 style="margin-top: 0; font-size: 1rem; color: #4338ca;">🚀 Contabilización Inteligente (OCR)</h3>
        <div style="display: flex; align-items: center; gap: 1rem;">
            <input type="file" id="ocr_file" accept="image/*,application/pdf" style="display: none;">
            <button type="button" id="btn_ocr_trigger" class="btn-primary" style="background: #4f46e5; color: white; padding: 0.6rem 1.2rem; border-radius: 6px; cursor: pointer; border: none; font-weight: 600;">
                Subir Imagen/PDF de Factura
            </button>
            <span id="ocr_status" style="font-size: 0.875rem; color: #64748b;">Formatos aceptados: JPG, PNG, PDF</span>
        </div>
    </section>

    <?php if ($error_msg): ?>
        <div class="alerta-err" style="background: #fee2e2; color: #b91c1c; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; border: 1px solid #fecaca;">
            <?= htmlspecialchars($error_msg) ?>
        </div>
    <?php endif; ?>

    <form action="guardar_facturas.php" method="POST" id="form_factura" onsubmit="return validarFormulario(event)">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

        <div style="background: #f1f5f9; padding: 1rem; border-radius: 8px; margin-bottom: 2rem; display: flex; align-items: center; gap: 2rem;">
            <div style="font-weight: bold; color: #1e293b;">Tipo de Factura:</div>
            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="radio" name="tipo" value="emitida" checked onchange="cambiarTipoFactura(this.value)"> 
                📤 Emitida (Venta/Ingreso)
            </label>
            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="radio" name="tipo" value="recibida" onchange="cambiarTipoFactura(this.value)"> 
                📥 Recibida (Gasto/Compra)
            </label>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
            <div>
                <label for="entidad_id" id="label-entidad">Cliente *</label>
                <div style="display: flex; gap: 0.5rem;">
                    <select name="entidad_id" id="entidad_id" required style="flex-grow: 1; padding:8px;">
                        <option value="">-- Selecciona --</option>
                        <?php foreach ($clientes as $c): ?>
                            <option value="<?= $c['id'] ?>" 
                                    class="opt-cliente"
                                    data-nif="<?= htmlspecialchars($c['nif_cif']) ?>"
                                    data-nombre="<?= htmlspecialchars(strtolower($c['nombre_fiscal'])) ?>">
                                <?= htmlspecialchars($c['nombre_fiscal']) ?> (<?= htmlspecialchars($c['nif_cif']) ?>)
                            </option>
                        <?php endforeach; ?>
                        <?php foreach ($proveedores as $p): ?>
                            <option value="<?= $p['id'] ?>" 
                                    class="opt-proveedor"
                                    style="display:none;"
                                    data-nif="<?= htmlspecialchars($p['nif_cif']) ?>"
                                    data-nombre="<?= htmlspecialchars(strtolower($p['nombre_fiscal'])) ?>">
                                <?= htmlspecialchars($p['nombre_fiscal']) ?> (<?= htmlspecialchars($p['nif_cif']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="abrirModalNuevaEntidad()" title="Crear nuevo" style="background: #4f46e5; color: white; border: none; padding: 0 12px; border-radius: 6px; cursor: pointer; font-size: 1.2rem; font-weight: bold;">+</button>
                </div>
            </div>
            <div>
                <label for="numero_factura">Nº Factura *</label>
                <input type="text" name="numero_factura" id="numero_factura" required placeholder="F-2024-001">
            </div>
            <div>
                <label for="fecha">Fecha *</label>
                <input type="date" name="fecha" id="fecha" required value="<?= date('Y-m-d') ?>">
            </div>
        </div>

        <table id="tabla_lineas">
            <thead>
                <tr>
                    <th style="width: 45%;">Concepto</th>
                    <th style="width: 10%;">Cant.</th>
                    <th style="width: 15%;">Precio €</th>
                    <th style="width: 10%;">IVA %</th>
                    <th style="width: 15%;">Subtotal</th>
                    <th style="width: 5%;"></th>
                </tr>
            </thead>
            <tbody>
                <!-- Las líneas se añaden por JS -->
            </tbody>
        </table>

        <button type="button" onclick="agregarLinea()" class="btn-secundario" style="margin-top: 1rem;">
            + Añadir concepto
        </button>

        <div class="totales-container" style="margin-top: 2rem; display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem;">
            <div class="total-item">
                <span>Base Imponible:</span>
                <input type="text" name="base_imponible" id="base_imponible" readonly class="input-total">
            </div>
            <div class="total-item">
                <span>IVA Total:</span>
                <input type="text" name="iva_total" id="iva_total" readonly class="input-total">
            </div>
            <div class="total-item total-final" style="font-size: 1.25rem; font-weight: bold; color: #1e293b; border-top: 2px solid #e2e8f0; padding-top: 0.5rem; margin-top: 0.5rem;">
                <span>TOTAL FACTURA:</span>
                <span id="total_factura_label" style="margin-left: 2rem; color: #4f46e5;">0.00 €</span>
                <input type="hidden" name="total" id="total_input">
            </div>
        </div>

        <div style="margin-top: 3rem; display: flex; gap: 1rem; justify-content: flex-end;">
            <a href="listar_facturas.php" class="btn-secundario" style="text-decoration: none; padding: 0.75rem 1.5rem;">Cancelar</a>
            <button type="submit" class="btn-primary" style="padding: 0.75rem 2.5rem; background: #10b981;">Guardar y Contabilizar</button>
        </div>
    </form>
</div>

<!-- Modal Nueva Entidad -->
<div id="modal_entidad" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
    <div style="background-color: white; margin: 10% auto; padding: 2rem; border-radius: 8px; width: 400px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <h2 id="modal_titulo" style="margin-top: 0;">Nueva Entidad</h2>
        <form id="form_nueva_entidad">
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem;">Nombre Fiscal *</label>
                <input type="text" id="modal_nombre" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem;">NIF/CIF *</label>
                <input type="text" id="modal_nif" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem;">Email</label>
                <input type="email" id="modal_email" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 1.5rem;">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" id="modal_vinculado"> 🤝 Es parte vinculada (socios, adm...)
                </label>
            </div>
            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                <button type="button" onclick="cerrarModal()" style="padding: 0.6rem 1.2rem; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; background: white;">Cancelar</button>
                <button type="submit" style="padding: 0.6rem 1.2rem; background: #4f46e5; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Scripts de lógica -->
<script src="<?= URL_BASE ?>public/assets/js/calculos_automaticos.js"></script>
<script src="<?= URL_BASE ?>public/assets/js/ocr_handler.js"></script>

<script>
    /**
     * Validación básica antes de enviar
     */
    function validarFormulario(e) {
        const total = parseFloat(document.getElementById('total_input').value || 0);
        if (total <= 0) {
            alert("La factura debe tener al menos una línea con importe.");
            return false;
        }
        return true;
    }

    // Garantizamos que siempre haya al menos una línea al empezar
    document.addEventListener('DOMContentLoaded', () => {
        if (document.querySelectorAll('.linea-item').length === 0) {
            agregarLinea();
        }
        // Forzar estado inicial del dropdown
        cambiarTipoFactura('emitida');
    });

    /**
     * Cambia etiquetas y opciones del dropdown según el tipo de factura
     */
    function cambiarTipoFactura(tipo) {
        const titulo = document.getElementById('titulo-form');
        const labelEntidad = document.getElementById('label-entidad');
        const select = document.getElementById('entidad_id');
        
        // Reiniciar selección
        select.value = "";

        if (tipo === 'emitida') {
            titulo.textContent = '📄 Nueva Factura Emitida';
            labelEntidad.textContent = 'Cliente *';
            // Mostrar clientes, ocultar proveedores
            document.querySelectorAll('.opt-cliente').forEach(el => el.style.display = 'block');
            document.querySelectorAll('.opt-proveedor').forEach(el => el.style.display = 'none');
        } else {
            titulo.textContent = '🧾 Nueva Factura Recibida';
            labelEntidad.textContent = 'Proveedor *';
            // Mostrar proveedores, ocultar clientes
            document.querySelectorAll('.opt-cliente').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.opt-proveedor').forEach(el => el.style.display = 'block');
        }
    }

    /**
     * Modal logic
     */
    function abrirModalNuevaEntidad() {
        const tipo = document.querySelector('input[name="tipo"]:checked').value;
        const titulo = document.getElementById('modal_titulo');
        titulo.textContent = (tipo === 'emitida') ? 'Nuevo Cliente' : 'Nuevo Proveedor';
        document.getElementById('modal_entidad').style.display = 'block';
    }

    function cerrarModal() {
        document.getElementById('modal_entidad').style.display = 'none';
        document.getElementById('form_nueva_entidad').reset();
    }

    // Cerrar modal al hacer clic fuera
    window.onclick = function(event) {
        const modal = document.getElementById('modal_entidad');
        if (event.target == modal) cerrarModal();
    }

    // AJAX para guardar nueva entidad
    document.getElementById('form_nueva_entidad').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const tipo = document.querySelector('input[name="tipo"]:checked').value;
        const nombre = document.getElementById('modal_nombre').value;
        const nif = document.getElementById('modal_nif').value;
        const email = document.getElementById('modal_email').value;
        const vinculada = document.getElementById('modal_vinculado').checked ? 1 : 0;

        try {
            const response = await fetch('api_crear_entidad.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tipo, nombre, nif, email, vinculada })
            });

            const result = await response.json();

            if (result.success) {
                // Añadir al select
                const select = document.getElementById('entidad_id');
                const newOpt = document.createElement('option');
                newOpt.value = result.id;
                newOpt.textContent = `${nombre} (${nif})`;
                newOpt.className = (tipo === 'emitida') ? 'opt-cliente' : 'opt-proveedor';
                newOpt.setAttribute('data-nif', nif);
                newOpt.setAttribute('data-nombre', nombre.toLowerCase());
                
                select.appendChild(newOpt);
                select.value = result.id;
                
                cerrarModal();
                alert(`✅ ${tipo === 'emitida' ? 'Cliente' : 'Proveedor'} creado con éxito.`);
            } else {
                alert('❌ Error: ' + (result.error || 'No se pudo crear la entidad.'));
            }
        } catch (error) {
            console.error('Error AJAX:', error);
            alert('❌ Error de conexión al servidor.');
        }
    });
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php'; ?>
</body>
</html>
