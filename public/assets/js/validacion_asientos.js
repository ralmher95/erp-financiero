/**
 * validacion_asientos.js
 * Validación en tiempo real de asientos contables (partida doble).
 *
 * Responsabilidades:
 *  - Comprobar que Debe == Haber antes de permitir guardar.
 *  - Validar que cada línea tenga cuenta e importe.
 *  - Mostrar mensajes de error descriptivos sin bloquear la UX.
 *  - Formateado automático de importes (separador decimal coma/punto).
 */

'use strict';

/* ============================================================
   Constantes
   ============================================================ */
const TOLERANCIA_CUADRE = 0.01; // margen por redondeo flotante

/* ============================================================
   Selectores (centralizados para facilitar cambios de HTML)
   ============================================================ */
const SEL = {
    formAsiento:    '#form-asiento',
    btnGuardar:     '#btnGuardar',
    barraEstado:    '#barra-totales',
    valDebe:        '#val-debe',
    valHaber:       '#val-haber',
    inputesDebe:    'input[name="debe_importe[]"]',
    inputesHaber:   'input[name="haber_importe[]"]',
    selectsCuenta:  'select[name^="cuenta"]',
    inputConcepto:  'input[name="concepto"]',
    inputFecha:     'input[name="fecha"]',
};

/* ============================================================
   Utilidades numéricas
   ============================================================ */

/**
 * Convierte una cadena de texto (acepta coma o punto decimal)
 * a número flotante. Devuelve 0 si no es válido.
 * @param {string} valor
 * @returns {number}
 */
function parsearImporte(valor) {
    const limpio = String(valor).trim().replace(',', '.');
    const num = parseFloat(limpio);
    return isNaN(num) || num < 0 ? 0 : num;
}

/**
 * Formatea un número como importe en euros (2 decimales, coma decimal).
 * @param {number} num
 * @returns {string}
 */
function formatearEuros(num) {
    return num.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
}

/* ============================================================
   Lógica de cuadre
   ============================================================ */

/**
 * Suma todos los importes de un conjunto de inputs.
 * @param {NodeList|HTMLElement[]} inputs
 * @returns {number}
 */
function sumarInputs(inputs) {
    let total = 0;
    inputs.forEach(input => { total += parsearImporte(input.value); });
    return Math.round(total * 100) / 100; // evitar errores de punto flotante
}

/**
 * Determina si el asiento está cuadrado (Debe == Haber y ambos > 0).
 * @param {number} debe
 * @param {number} haber
 * @returns {boolean}
 */
function estaCuadrado(debe, haber) {
    return debe > 0 && haber > 0 && Math.abs(debe - haber) <= TOLERANCIA_CUADRE;
}

/* ============================================================
   Validaciones de campos
   ============================================================ */

/**
 * Valida que todas las líneas de un tipo (debe/haber) tengan
 * cuenta seleccionada e importe mayor que cero.
 * @param {string} tipo  'debe' | 'haber'
 * @returns {{ valido: boolean, errores: string[] }}
 */
function validarLineas(tipo) {
    const errores = [];
    const contenedor = document.getElementById('lineas-' + tipo);
    if (!contenedor) return { valido: true, errores };

    const lineas = contenedor.querySelectorAll('.linea-asiento');
    lineas.forEach((linea, idx) => {
        const num      = idx + 1;
        const select   = linea.querySelector('select');
        const importe  = linea.querySelector('input.input-importe');

        if (select && !select.value) {
            errores.push(`Línea ${tipo.toUpperCase()} #${num}: selecciona una cuenta contable.`);
        }
        if (importe && parsearImporte(importe.value) <= 0) {
            errores.push(`Línea ${tipo.toUpperCase()} #${num}: el importe debe ser mayor que cero.`);
        }
    });

    return { valido: errores.length === 0, errores };
}

/**
 * Valida los campos de cabecera del asiento (fecha y concepto).
 * @returns {{ valido: boolean, errores: string[] }}
 */
function validarCabecera() {
    const errores = [];
    const concepto = document.querySelector(SEL.inputConcepto);
    const fecha    = document.querySelector(SEL.inputFecha);

    if (concepto && concepto.value.trim() === '') {
        errores.push('El concepto del asiento es obligatorio.');
    }
    if (fecha && fecha.value === '') {
        errores.push('La fecha del asiento es obligatoria.');
    }
    if (fecha && fecha.value) {
        const hoy     = new Date();
        const fechaAsi = new Date(fecha.value);
        const anioMin = 2000;
        if (fechaAsi.getFullYear() < anioMin) {
            errores.push(`La fecha no puede ser anterior al año ${anioMin}.`);
        }
        if (fechaAsi > hoy) {
            errores.push('La fecha del asiento no puede ser futura.');
        }
    }
    return { valido: errores.length === 0, errores };
}

/* ============================================================
   Actualización de UI
   ============================================================ */

/**
 * Recalcula totales, actualiza la barra de estado y
 * habilita/deshabilita el botón de guardar.
 */
function recalcular() {
    const inputsDebe  = document.querySelectorAll(SEL.inputesDebe);
    const inputsHaber = document.querySelectorAll(SEL.inputesHaber);

    const totalDebe  = sumarInputs(inputsDebe);
    const totalHaber = sumarInputs(inputsHaber);
    const cuadrado   = estaCuadrado(totalDebe, totalHaber);

    // Actualizar totales en pantalla
    const elDebe  = document.querySelector(SEL.valDebe);
    const elHaber = document.querySelector(SEL.valHaber);
    if (elDebe)  elDebe.textContent  = formatearEuros(totalDebe);
    if (elHaber) elHaber.textContent = formatearEuros(totalHaber);

    // Actualizar barra de estado
    const barra = document.querySelector(SEL.barraEstado);
    if (barra) {
        barra.classList.toggle('cuadrado',  cuadrado);
        barra.classList.toggle('descuadre', !cuadrado);
    }

    // Habilitar botón solo si cuadrado Y sin errores de cabecera/líneas
    const btnGuardar = document.querySelector(SEL.btnGuardar);
    if (btnGuardar) {
        const { valido: cabeceraOk } = validarCabecera();
        btnGuardar.disabled = !(cuadrado && cabeceraOk);
    }
}

/**
 * Muestra u oculta la caja de errores de validación.
 * @param {string[]} errores
 */
function mostrarErrores(errores) {
    let caja = document.getElementById('errores-validacion');

    if (errores.length === 0) {
        if (caja) caja.remove();
        return;
    }

    if (!caja) {
        caja = document.createElement('div');
        caja.id = 'errores-validacion';
        caja.className = 'alerta alerta-err';
        const form = document.querySelector(SEL.formAsiento);
        if (form) form.insertAdjacentElement('beforebegin', caja);
    }

    const lista = errores.map(e => `<li>${e}</li>`).join('');
    caja.innerHTML = `<strong>⛔ Corrige los siguientes errores:</strong><ul>${lista}</ul>`;
}

/* ============================================================
   Formateo automático de importes al salir del campo
   ============================================================ */

/**
 * Al perder el foco un input de importe, normaliza el valor
 * (reemplaza coma por punto y fuerza 2 decimales).
 * @param {Event} e
 */
function formatearAlSalir(e) {
    if (!e.target.classList.contains('input-importe')) return;
    const num = parsearImporte(e.target.value);
    e.target.value = num > 0 ? num.toFixed(2) : '';
    recalcular();
}

/* ============================================================
   Validación completa al intentar guardar
   ============================================================ */

/**
 * Ejecuta todas las validaciones antes de enviar el formulario.
 * Si hay errores, los muestra y cancela el envío.
 * @param {Event} e
 */
function validarAntesDEnviar(e) {
    const errores = [];

    const cab  = validarCabecera();
    const debe  = validarLineas('debe');
    const haber = validarLineas('haber');

    errores.push(...cab.errores, ...debe.errores, ...haber.errores);

    const inputsDebe  = document.querySelectorAll(SEL.inputesDebe);
    const inputsHaber = document.querySelectorAll(SEL.inputesHaber);
    const totalDebe   = sumarInputs(inputsDebe);
    const totalHaber  = sumarInputs(inputsHaber);

    if (!estaCuadrado(totalDebe, totalHaber)) {
        errores.push(
            `El asiento no cuadra: Debe ${formatearEuros(totalDebe)} ≠ Haber ${formatearEuros(totalHaber)}.`
        );
    }

    if (errores.length > 0) {
        e.preventDefault();
        mostrarErrores(errores);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
        mostrarErrores([]);
    }
}

/* ============================================================
   Inicialización
   ============================================================ */

document.addEventListener('DOMContentLoaded', () => {
    // Recalcular al escribir en cualquier importe
    document.addEventListener('input', e => {
        if (e.target.classList.contains('input-importe') ||
            e.target.name === 'concepto' ||
            e.target.name === 'fecha') {
            recalcular();
        }
    });

    // Formatear importe al perder el foco
    document.addEventListener('focusout', formatearAlSalir);

    // Validación completa al enviar
    const form = document.querySelector(SEL.formAsiento);
    if (form) {
        form.addEventListener('submit', validarAntesDEnviar);
    }

    // Estado inicial
    recalcular();
});