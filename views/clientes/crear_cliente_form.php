<?php
// views/clientes/crear_cliente_form.php
// Equivalente PHP del CrearClienteForm.tsx original.
// Formulario que llama a la API REST (ClienteApiController.php)
// mediante fetch, igual que hacía el componente React.

require_once __DIR__ . '/../../config/db_connect.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Añadir Cliente — ERP Financiero</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; background: #f4f6f9; }
        .container { max-width: 520px; margin: 40px auto; padding: 0 20px; }

        .form-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.07);
            padding: 32px;
        }
        .form-card h3 {
            margin: 0 0 24px;
            color: #2c3e50;
            font-size: 18px;
        }

        .campo { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
        .campo label { font-weight: 600; font-size: 13px; color: #555; }
        .campo input {
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color .2s, box-shadow .2s;
        }
        .campo input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52,152,219,0.18);
        }

        .btn-submit {
            width: 100%;
            padding: 11px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
            margin-top: 8px;
        }
        .btn-submit:hover:not(:disabled) { background: #2980b9; }
        .btn-submit:disabled { opacity: 0.55; cursor: not-allowed; }

        /* Mensajes de estado (equivalente al useState mensaje del TSX) */
        .mensaje {
            padding: 11px 16px;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 20px;
            display: none;
        }
        .mensaje.ok  { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .mensaje.err { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .mensaje.visible { display: block; }

        .volver { display: block; text-align: center; margin-top: 18px;
                  color: #3498db; font-size: 13px; text-decoration: none; }
        .volver:hover { text-decoration: underline; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container">
    <div class="form-card">
        <h3>➕ Añadir Nuevo Cliente</h3>

        <!-- Mensaje de estado (equivalente al <p>{mensaje}</p> del TSX) -->
        <div id="mensaje" class="mensaje"></div>

        <!-- Formulario (equivalente al <form onSubmit={handleSubmit}> del TSX) -->
        <form id="formCliente">
            <div class="campo">
                <label for="nombre_fiscal">Nombre Fiscal *</label>
                <input type="text" id="nombre_fiscal" name="nombre_fiscal"
                       placeholder="Razón social o nombre completo" required>
            </div>

            <div class="campo">
                <label for="nif_cif">NIF / CIF *</label>
                <input type="text" id="nif_cif" name="nif_cif"
                       placeholder="B12345678" maxlength="20" required>
            </div>

            <div class="campo">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" placeholder="contacto@empresa.com">
            </div>

            <div class="campo">
                <label for="telefono">Teléfono</label>
                <input type="text" id="telefono" name="telefono" placeholder="600 000 000">
            </div>

            <button type="submit" class="btn-submit" id="btnGuardar">
                Guardar Cliente
            </button>
        </form>

        <a href="clientes.php" class="volver">← Volver al listado</a>
    </div>
</div>

<script>
// Equivalente al handleSubmit del CrearClienteForm.tsx
// Llama a ClienteApiController.php mediante fetch (igual que el TSX llamaba a /api/clientes)

const API_URL = '<?= URL_BASE ?>src/Controllers/api/ClienteApiController.php';

document.getElementById('formCliente').addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn     = document.getElementById('btnGuardar');
    const mensaje = document.getElementById('mensaje');

    // Estado "loading" (equivalente al setLoading(true) del TSX)
    btn.disabled     = true;
    btn.textContent  = 'Guardando...';
    mensaje.className = 'mensaje';
    mensaje.textContent = '';

    const formData = {
        nombre_fiscal: document.getElementById('nombre_fiscal').value.trim(),
        nif_cif:       document.getElementById('nif_cif').value.trim(),
        email:         document.getElementById('email').value.trim(),
        telefono:      document.getElementById('telefono').value.trim(),
    };

    try {
        const res  = await fetch(API_URL, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(formData)
        });

        const data = await res.json();

        if (data.success) {
            // Equivalente al setMensaje('✅ ' + data.message) + limpiar form del TSX
            mostrarMensaje('ok', '✅ ' + data.message);
            document.getElementById('formCliente').reset();
        } else {
            mostrarMensaje('err', '❌ ' + data.message);
        }

    } catch (error) {
        // Equivalente al catch del TSX
        mostrarMensaje('err', '❌ Error de conexión con el servidor.');
    } finally {
        // Equivalente al setLoading(false) del TSX
        btn.disabled    = false;
        btn.textContent = 'Guardar Cliente';
    }
});

function mostrarMensaje(tipo, texto) {
    const el = document.getElementById('mensaje');
    el.className    = 'mensaje ' + tipo + ' visible';
    el.textContent  = texto;
}
</script>
</body>
</html>
