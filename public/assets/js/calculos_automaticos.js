/**
 * ARCHIVO: public/assets/js/calculos_automaticos.js
 * Función: Gestión de cálculos en tiempo real y manipulación segura del DOM.
 */

// Índice global para que los nombres de los inputs sean únicos (lineas[0], lineas[1]...)
let lineaIndex = document.querySelectorAll('#lineas tr').length;

/**
 * Calcula los subtotales de cada fila y los totales globales del pie de factura.
 */
function calcular() {
    const filas = document.querySelectorAll('.linea-item');
    
    let base_exacta  = 0;
    let iva_exacto   = 0;
    let total_exacto = 0;

    filas.forEach(fila => {
        // Obtenemos valores. Usamos parseInt para la cantidad (unidades enteras).
        const cantidad = parseInt(fila.querySelector('.cantidad')?.value) || 0;
        const precio   = parseFloat(fila.querySelector('.precio')?.value)   || 0;
        const iva_pct  = parseFloat(fila.querySelector('.iva')?.value)      || 0;
        const inputTotalLinea = fila.querySelector('.total-linea');

        // Cálculos de la línea
        const subtotal  = cantidad * precio;
        const iva_linea = subtotal * (iva_pct / 100);
        const total_con_iva = subtotal + iva_linea;

        // Actualizamos el total visual de la fila
        if (inputTotalLinea) {
            inputTotalLinea.value = (Math.round(total_con_iva * 100) / 100).toFixed(2);
        }

        // Acumulación de valores exactos para evitar errores de redondeo
        base_exacta  += subtotal;
        iva_exacto   += iva_linea;
        total_exacto += total_con_iva;
    });

    // Redondeo final único (Consistente con la lógica del servidor PHP)
    const base_final  = Math.round(base_exacta  * 100) / 100;
    const iva_final   = Math.round(iva_exacto   * 100) / 100;
    const total_final = Math.round(total_exacto * 100) / 100;

    // Actualizamos los campos del pie de factura
    const baseEl  = document.getElementById('base');
    const ivaEl   = document.getElementById('iva_total');
    const totalEl = document.getElementById('total_factura');

    if (baseEl)  baseEl.value  = base_final.toFixed(2);
    if (ivaEl)   ivaEl.value   = iva_final.toFixed(2);
    if (totalEl) totalEl.value = total_final.toFixed(2);
}

/**
 * Añade una nueva fila a la tabla de conceptos usando DOM puro (createElement).
 */
function agregarLinea() {
    const tbody = document.getElementById('lineas');
    if (!tbody) return;

    const fila = document.createElement('tr');
    fila.className = 'linea-item';

    // Configuración de las celdas
    const columnas = [
        { type: 'text',   name: `lineas[${lineaIndex}][concepto]`, ph: 'Concepto...', req: true },
        { type: 'number', name: `lineas[${lineaIndex}][cantidad]`, cls: 'cantidad', val: '1', step: '1', isInt: true },
        { type: 'number', name: `lineas[${lineaIndex}][precio]`,   cls: 'precio', ph: '0.00', step: '0.01', req: true },
        { type: 'number', name: `lineas[${lineaIndex}][iva]`,      cls: 'iva', val: '21' },
        { type: 'text',   name: `lineas[${lineaIndex}][total]`,    cls: 'total-linea', readOnly: true }
    ];

    columnas.forEach(col => {
        const td = document.createElement('td');
        const input = document.createElement('input');
        
        input.type = col.type;
        input.name = col.name;
        if (col.cls)      input.className = col.cls;
        if (col.ph)       input.placeholder = col.ph;
        if (col.val)      input.value = col.val;
        if (col.step)     input.step = col.step;
        if (col.req)      input.required = true;
        
        if (col.readOnly) {
            input.readOnly = true;
            input.style.cssText = 'border:none; background:transparent; font-weight:bold;';
        }

        // --- Validación de Cantidad Entera ---
        if (col.isInt) {
            input.addEventListener('input', function() {
                // Elimina cualquier carácter que no sea número
                this.value = this.value.replace(/[^0-9]/g, '');
            });
            input.addEventListener('blur', function() {
                // Asegura mínimo 1 al salir del campo
                if (this.value === '' || parseInt(this.value) < 1) this.value = '1';
                calcular();
            });
        }

        td.appendChild(input);
        fila.appendChild(td);
    });

    // Botón de eliminar fila
    const tdAccion = document.createElement('td');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = '✕';
    btn.style.cssText = 'background:#e74c3c; color:white; border:none; border-radius:4px; padding:6px 12px; cursor:pointer;';
    btn.onclick = () => { fila.remove(); calcular(); };
    
    tdAccion.appendChild(btn);
    fila.appendChild(tdAccion);

    tbody.appendChild(fila);
    lineaIndex++; // Incrementamos el índice para la próxima fila
}

/**
 * Delegación de eventos para capturar cambios en cualquier input relevante.
 */
document.addEventListener('input', (e) => {
    if (e.target.matches('.cantidad, .precio, .iva')) {
        calcular();
    }
});

/**
 * Al cargar la página, inicializamos los cálculos.
 */
document.addEventListener('DOMContentLoaded', calcular);