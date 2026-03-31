<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/db_connect.php';

// Seguridad: Token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Obtener clientes
try {
    $clientes = $pdo->query("SELECT id, nombre_fiscal, nif_cif FROM clientes ORDER BY nombre_fiscal ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Error al obtener clientes: " . $e->getMessage());
    $clientes = [];
}

// Gestión de mensajes de error
$error_code = $_GET['error'] ?? null;
$mensajes_error = [
    '1'         => '❌ Error interno al guardar la factura.',
    'cliente'   => '❌ El cliente seleccionado no es válido.',
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
        <h1>📄 Nueva Factura Emitida</h1>
        <p style="color: #64748b;">Genera facturas legales y asienta automáticamente en el libro diario.</p>
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

        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
            <div>
                <label for="cliente_id">Cliente *</label>
                <select name="cliente_id" id="cliente_id" required style="width:100%; padding:8px;">
                    <option value="">-- Selecciona un cliente --</option>
                    <?php foreach ($clientes as $c): ?>
                        <option value="<?= $c['id'] ?>" 
                                data-nif="<?= htmlspecialchars($c['nif_cif']) ?>"
                                data-nombre="<?= htmlspecialchars(strtolower($c['nombre_fiscal'])) ?>">
                            <?= htmlspecialchars($c['nombre_fiscal']) ?> (<?= htmlspecialchars($c['nif_cif']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="numero_factura">Nº Factura *</label>
                <input type="text" name="numero_factura" id="numero_factura" required placeholder="F-2024-001" style="padding:8px;">
            </div>
            <div>
                <label for="fecha">Fecha *</label>
                <input type="date" name="fecha" id="fecha" required value="<?= date('Y-m-d') ?>" style="padding:8px;">
            </div>
        </div>

        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f1f5f9;">
                    <th style="padding: 10px; text-align: left;">Concepto</th>
                    <th style="width: 80px;">Cant.</th>
                    <th style="width: 120px;">Precio €</th>
                    <th style="width: 80px;">IVA %</th>
                    <th style="width: 120px;">Subtotal</th>
                    <th style="width: 50px;"></th>
                </tr>
            </thead>
            <tbody id="lineas">
                </tbody>
        </table>

        <button type="button" class="btn-add" onclick="agregarLinea()">＋ Añadir concepto</button>

        <div class="totales">
            <div>
                <label for="base">Base Imponible:</label>
                <input type="text" name="base_imponible" id="base" readonly>
            </div>
            <div>
                <label for="iva_total">IVA Total:</label>
                <input type="text" name="iva_total" id="iva_total" readonly>
            </div>
            <div style="border-top: 2px solid #4f46e5; padding-top: 10px;">
                <label for="total_factura" style="font-size: 1.2rem;">TOTAL FACTURA:</label>
                <input type="text" name="total" id="total_factura" readonly style="font-size: 1.2rem; color: #4f46e5; border:none; background: transparent;">
            </div>
        </div>

        <div style="text-align: right; margin-top: 2rem; border-top: 1px solid #e2e8f0; padding-top: 2rem;">
            <a href="listar_facturas.php" style="text-decoration: none; color: #64748b; margin-right: 2rem;">Cancelar</a>
            <button type="submit" style="background: #059669; color: white; padding: 12px 30px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold;">
                💾 Guardar y Contabilizar
            </button>
        </div>
    </form>
</div>

<script src="/PHP/erp-financiero/public/assets/js/calculos_automaticos.js"></script>

<script>
    // Proporcionar la URL base al script de OCR para que sea robusto en cualquier entorno.
    // Como api_ocr_handler.php está en la misma carpeta que esta vista, lo llamamos directamente.
    window.OCR_ENDPOINT = 'api_ocr_handler.php';
</script>
<script src="/PHP/erp-financiero/public/assets/js/ocr_handler.js"></script>

<script>
    /**
     * Validación de formulario antes de enviar
     */
    function validarFormulario(e) {
        const clienteId = document.getElementById('cliente_id').value;
        if (!clienteId) {
            alert('❌ Debes seleccionar un cliente de la lista antes de guardar la factura.');
            document.getElementById('cliente_id').focus();
            return false;
        }
        
        // Verificar que hay al menos una línea con concepto y precio
        const lineas = document.querySelectorAll('.linea-item');
        let valida = false;
        lineas.forEach(l => {
            const desc = l.querySelector('input[name*="[concepto]"]').value;
            const precio = l.querySelector('input[name*="[precio]"]').value;
            if (desc.trim() !== '' && precio > 0) valida = true;
        });

        if (!valida) {
            alert('❌ Debes añadir al menos una línea con concepto y precio.');
            return false;
        }

        return true;
    }

    // Garantizamos que siempre haya al menos una línea al empezar
    document.addEventListener('DOMContentLoaded', () => {
        if (document.querySelectorAll('.linea-item').length === 0) {
            agregarLinea();
        }
    });
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/PHP/erp-financiero/includes/layout_footer.php'; ?>
</body>
</html>