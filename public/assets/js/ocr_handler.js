/**
 * =============================================================================
 * ARCHIVO:  public/assets/js/ocr_handler.js
 *
 * PROPÓSITO:
 *   Gestiona el flujo completo del módulo "Contabilización Inteligente (OCR)"
 *   en crear_factura.php:
 *     1. El usuario sube una imagen/PDF de factura
 *     2. Se envía al endpoint api_ocr_handler.php via AJAX (fetch)
 *     3. La respuesta JSON se vuelca en TODOS los campos del formulario:
 *        · Cabecera:  fecha, número de factura, emisor, NIF
 *        · Importes:  base imponible, cuota IVA, total
 *        · Líneas:    una fila por cada concepto detectado (concepto, cant., precio, IVA%)
 *
 * MEJORAS RESPECTO A LA VERSIÓN ANTERIOR:
 *   ✅ Vuelca emisor, NIF y número de factura (antes ignorados)
 *   ✅ Crea tantas filas de concepto como líneas devuelva el OCR
 *   ✅ Modo fallback si el OCR no detecta líneas estructuradas
 *   ✅ Busca inputs por clase Y por name (más robusto que querySelector genérico)
 *   ✅ Dispara eventos input+change para que calculos_automaticos.js recalcule
 *   ✅ IIFE con 'use strict' para no contaminar el scope global
 *   ✅ Ruta del endpoint centralizada en una constante (fácil de cambiar)
 *
 * DEPENDENCIAS:
 *   · calculos_automaticos.js debe definir la función global agregarLinea()
 *   · El formulario debe tener los IDs y clases descritos en los comentarios
 *
 * COMPATIBILIDAD:
 *   · Chrome 80+, Firefox 75+, Edge 80+ (usa fetch, async/await, ES2020)
 * =============================================================================
 */

(function () {
    'use strict';

    // ── Configuración ─────────────────────────────────────────────────────────

    /**
     * Ruta al endpoint OCR.
     *
     * OPCIÓN A (ruta relativa desde views/facturacion/crear_factura.php):
     *   '../../src/Controllers/api/api_ocr_handler.php'
     *
     * OPCIÓN B (URL absoluta usando URL_BASE desde PHP — más robusta con Laragon):
     *   Añade en crear_factura.php antes de incluir este JS:
     *     <script>const OCR_ENDPOINT = '<?= URL_BASE ?>src/Controllers/api/api_ocr_handler.php';</script>
     *   Y aquí usa: const OCR_ENDPOINT = window.OCR_ENDPOINT || '../../...';
     *
     * Usamos la opción A por defecto. Cambia según tu estructura.
     */
    const OCR_ENDPOINT = window.OCR_ENDPOINT || '../../src/Controllers/api/api_ocr_handler.php';

    // ── Referencias al DOM ────────────────────────────────────────────────────

    /** Input file oculto que recibe el archivo del usuario */
    const inputArchivo = document.getElementById('ocr_file');

    /** Botón visible que dispara el click en el input file */
    const btnOcr = document.getElementById('btn_ocr_trigger');

    /** Elemento donde se muestra el estado del proceso ("Procesando...", "✅", "❌") */
    const etiquetaEstado = document.getElementById('ocr_status');

    // Si no hay input OCR en la página, este módulo no hace nada
    if (!inputArchivo) return;

    // El botón visual delega el click en el input file oculto
    if (btnOcr) {
        btnOcr.addEventListener('click', () => inputArchivo.click());
    }

    // =========================================================================
    // EVENTO PRINCIPAL: cuando el usuario selecciona un archivo
    // =========================================================================

    inputArchivo.addEventListener('change', async function (evento) {
        const archivo = evento.target.files[0];
        if (!archivo) return;

        // Mostrar spinner de procesamiento
        _setEstado('⏳ Procesando factura con OCR…', 'procesando');

        // Preparar el FormData con el archivo
        const formData = new FormData();
        formData.append('factura_img', archivo);

        try {
            // ── Llamada al endpoint ───────────────────────────────────────────
            const respuesta = await fetch(OCR_ENDPOINT, {
                method: 'POST',
                body: formData,
                // No establecer Content-Type: el navegador lo hace automáticamente
                // con el boundary correcto para multipart/form-data
            });

            // Obtener el texto crudo primero para poder depurar si falla el parse
            const textoRaw = await respuesta.text();
            let resultado;

            try {
                resultado = JSON.parse(textoRaw);
            } catch (e) {
                // Si el servidor devolvió HTML de error PHP en lugar de JSON
                console.error('[OCR] Respuesta no-JSON recibida:', textoRaw);
                const snippet = textoRaw.length > 150 ? textoRaw.substring(0, 150) + '...' : textoRaw;
                throw new Error(
                    `El servidor devolvió una respuesta inesperada: "${snippet}". ` +
                    'Revisa la consola del navegador (F12) para ver el error completo.'
                );
            }

            // Verificar que el OCR tuvo éxito
            if (resultado.status !== 'success') {
                throw new Error(resultado.message || 'Error desconocido en el servidor OCR.');
            }

            // ── Volcar todos los datos en el formulario ────────────────────────
            _volcarDatos(resultado.data);

            _setEstado('✅ Datos extraídos y cargados en el formulario.', 'ok');

        } catch (error) {
            console.error('[OCR] Error en el proceso:', error);
            _setEstado(`❌ ${error.message}`, 'error');

        } finally {
            // Limpiar el input para permitir subir el mismo archivo si el usuario quiere reintentar
            inputArchivo.value = '';
        }
    });

    // =========================================================================
    // VOLCADO DE DATOS EN EL FORMULARIO
    // =========================================================================

    /**
     * Recibe el objeto `data` del endpoint y rellena todos los campos del formulario.
     *
     * Estructura esperada de `data`:
     * {
     *   emisor:          string,   // "Telefónica España, S.A."
     *   nif:             string,   // "A28015865"
     *   numero_factura:  string,   // "A-2024-0042"
     *   fecha:           string,   // "2024-03-15"  (YYYY-MM-DD)
     *   base:            number,   // 1000.00
     *   iva:             number,   //  210.00
     *   total:           number,   // 1210.00
     *   tipo_iva:        number,   //   21
     *   lineas:          Array<{concepto, cantidad, precio, subtotal}>
     * }
     *
     * @param {Object} data - Datos devueltos por api_ocr_handler.php
     */
    function _volcarDatos(data) {

        // ── 1. Campos de cabecera ─────────────────────────────────────────────

        // Fecha: input[type=date] espera YYYY-MM-DD (ya lo devuelve el backend)
        _setValorCampo('fecha', data.fecha);

        // Número de factura (puede ser un campo de referencia en el formulario)
        _setValorCampo('numero_factura', data.numero_factura);
        _setValorCampo('referencia',     data.numero_factura); // alias común

        // Emisor y NIF (campos informativos, no siempre presentes en el formulario)
        _setValorCampo('emisor',     data.emisor);
        _setValorCampo('nif_emisor', data.nif);

        // Intentar seleccionar automáticamente al cliente por NIF o Nombre
        if (data.nif || data.emisor) {
            const selectCliente = document.getElementById('cliente_id');
            if (selectCliente) {
                const nifLimpio = (data.nif || '').replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
                const nombreLimpio = (data.emisor || '').toLowerCase().trim();
                let clienteEncontrado = false;

                // 1. Prioridad: Buscar por NIF
                if (nifLimpio) {
                    for (let i = 0; i < selectCliente.options.length; i++) {
                        const opt = selectCliente.options[i];
                        const nifOpcion = (opt.getAttribute('data-nif') || '').replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
                        if (nifOpcion && nifOpcion === nifLimpio) {
                            selectCliente.selectedIndex = i;
                            clienteEncontrado = true;
                            console.log(`[OCR] Cliente seleccionado por NIF: ${opt.text}`);
                            break;
                        }
                    }
                }

                // 2. Fallback: Buscar por nombre (coincidencia parcial)
                if (!clienteEncontrado && nombreLimpio) {
                    for (let i = 0; i < selectCliente.options.length; i++) {
                        const opt = selectCliente.options[i];
                        const nombreOpcion = (opt.getAttribute('data-nombre') || '');
                        // Si el nombre extraído contiene el nombre del cliente o viceversa
                        if (nombreOpcion && (nombreLimpio.includes(nombreOpcion) || nombreOpcion.includes(nombreLimpio))) {
                            selectCliente.selectedIndex = i;
                            clienteEncontrado = true;
                            console.log(`[OCR] Cliente seleccionado por nombre (fallback): ${opt.text}`);
                            break;
                        }
                    }
                }
                
                if (!clienteEncontrado) {
                    console.warn(`[OCR] No se pudo emparejar cliente para NIF: ${data.nif} o Nombre: ${data.emisor}`);
                }
            }
        }

        // ── 2. Totales de cabecera (campos de resumen) ────────────────────────
        // Buscamos los IDs reales en el formulario de crear_factura.php:
        // 'base', 'iva_total', 'total_factura'
        if (data.base  > 0) _setValorCampo('base',      Number(data.base).toFixed(2));
        if (data.iva   > 0) _setValorCampo('iva_total', Number(data.iva).toFixed(2));
        if (data.total > 0) _setValorCampo('total_factura', Number(data.total).toFixed(2));

        // ── 3. Líneas de detalle ──────────────────────────────────────────────
        const tipoIvaDetectado = data.tipo_iva ?? 21;

        if (Array.isArray(data.lineas) && data.lineas.length > 0) {
            // Filtrar líneas de ruido (como etiquetas del formulario que el OCR capturó por error)
            const lineasFiltradas = data.lineas.filter(l => {
                const desc = l.concepto.toLowerCase();
                // Ignorar si la descripción contiene etiquetas comunes del formulario
                const esRuido = desc.includes('fecha*') || 
                                desc.includes('nº factura*') || 
                                desc.includes('cliente*') ||
                                desc.includes('subir imagen') ||
                                (l.cantidad === 1 && l.precio === 2024); // El año 2024 capturado como precio
                return !esRuido;
            });

            if (lineasFiltradas.length > 0) {
                _rellenarLineas(lineasFiltradas, tipoIvaDetectado);
            } else {
                _crearLineaVaciaConTotales(data, tipoIvaDetectado);
            }
        } else {
            // No se detectaron líneas estructuradas → modo fallback:
            // Crear una sola fila con la base imponible como precio unitario
            _rellenarLineaFallback(data, tipoIvaDetectado);
        }
    }

    // ── 3a. Hay líneas de detalle detectadas ──────────────────────────────────

    /**
     * Rellena el formulario con las líneas de detalle detectadas por el OCR.
     * Limpia las filas existentes (excepto la primera) y crea las necesarias.
     *
     * @param {Array}  lineas          Array de líneas devueltas por el OCR.
     * @param {number} tipoIvaDefecto  Porcentaje de IVA a aplicar en cada fila.
     */
    function _rellenarLineas(lineas, tipoIvaDefecto) {
        // Eliminar todas las filas sobrantes del formulario (dejar la primera para reutilizarla)
        const filasActuales = document.querySelectorAll('.linea-item');
        filasActuales.forEach((fila, indice) => {
            if (indice > 0) fila.remove();
        });

        lineas.forEach(function (lineaOcr, indice) {
            let fila;

            if (indice === 0) {
                // Reutilizar la primera fila que ya existe en el DOM
                fila = document.querySelector('.linea-item');
            } else {
                // Crear una nueva fila llamando a la función de calculos_automaticos.js
                if (typeof agregarLinea === 'function') {
                    agregarLinea();
                } else {
                    console.warn('[OCR] agregarLinea() no está definida. ' +
                        'Asegúrate de incluir calculos_automaticos.js antes que ocr_handler.js.');
                    return;
                }
                // Obtener la fila recién añadida (la última del DOM)
                const todasLasFilas = document.querySelectorAll('.linea-item');
                fila = todasLasFilas[todasLasFilas.length - 1];
            }

            if (!fila) {
                console.error(`[OCR] No se encontró la fila #${indice} en el DOM.`);
                return;
            }

            _rellenarFila(fila, {
                concepto: lineaOcr.concepto || 'Línea de factura',
                cantidad: lineaOcr.cantidad ?? 1,
                precio:   lineaOcr.precio   ?? 0,
                tipoIva:  tipoIvaDefecto,
            });
        });
    }

    // ── 3b. Sin líneas: usar base imponible en una sola fila ──────────────────

    /**
     * Modo fallback: cuando el OCR no detecta la tabla de conceptos,
     * volcamos la base imponible como precio de una fila única.
     *
     * @param {Object} data           Datos completos del OCR.
     * @param {number} tipoIva        Porcentaje de IVA detectado.
     */
    function _rellenarLineaFallback(data, tipoIva) {
        // Buscar la primera fila o crearla si no existe
        let fila = document.querySelector('.linea-item');
        if (!fila && typeof agregarLinea === 'function') {
            agregarLinea();
            fila = document.querySelector('.linea-item');
        }

        if (!fila) {
            console.error('[OCR] No se encontró ninguna fila .linea-item en el formulario.');
            return;
        }

        // Construir un concepto descriptivo usando el emisor si está disponible
        const concepto = data.emisor
            ? `Factura ${data.emisor}${data.numero_factura ? ' · ' + data.numero_factura : ''}`
            : 'Concepto extraído vía OCR';

        _rellenarFila(fila, {
            concepto: concepto,
            cantidad: 1,
            precio:   data.base > 0 ? data.base : 0,
            tipoIva:  tipoIva,
        });
    }

    // ── Rellena una fila individual del formulario ────────────────────────────

    /**
     * Rellena los campos de una fila .linea-item con los datos proporcionados.
     * Busca los inputs por clase CSS específica y también por atributo name.
     * Después de rellenar, dispara eventos para que calculos_automaticos.js recalcule.
     *
     * @param {HTMLElement} fila     Elemento DOM de la fila (.linea-item).
     * @param {Object}      datos    { concepto, cantidad, precio, tipoIva }
     */
    function _rellenarFila(fila, { concepto, cantidad, precio, tipoIva }) {
        // Función auxiliar: busca un input dentro de la fila por clase o name
        const buscar = (selectores) => {
            for (const selector of selectores) {
                const el = fila.querySelector(selector);
                if (el) return el;
            }
            return null;
        };

        // ── Campo: CONCEPTO ───────────────────────────────────────────────────
        // Buscamos el primer input de texto que no sea readonly
        const inputConcepto = buscar([
            'input.concepto',
            'input[name*="concepto"]',
            'input[name*="descripcion"]',
            'input[type="text"]:not([readonly])',
        ]);
        if (inputConcepto) inputConcepto.value = concepto;

        // ── Campo: CANTIDAD ───────────────────────────────────────────────────
        const inputCantidad = buscar([
            'input.cantidad',
            'input[name*="cantidad"]',
            'input[name*="qty"]',
        ]);
        if (inputCantidad) inputCantidad.value = cantidad;

        // ── Campo: PRECIO UNITARIO ────────────────────────────────────────────
        const inputPrecio = buscar([
            'input.precio',
            'input[name*="precio"]',
            'input[name*="price"]',
            'input[name*="importe"]',
        ]);
        if (inputPrecio) inputPrecio.value = Number(precio).toFixed(2);

        // ── Campo: IVA % ──────────────────────────────────────────────────────
        // Puede ser un input numérico o un <select>
        const inputIva = buscar([
            'input.iva',
            'select.iva',
            'input[name*="iva"]',
            'select[name*="iva"]',
        ]);
        if (inputIva) inputIva.value = tipoIva;

        // ── Disparar recálculo automático ─────────────────────────────────────
        // calculos_automaticos.js escucha eventos 'input' y 'change' en los campos
        // de precio, cantidad e IVA para recalcular el subtotal y los totales.
        [inputPrecio, inputCantidad, inputIva].forEach(input => {
            if (!input) return;
            input.dispatchEvent(new Event('input',  { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    // =========================================================================
    // UTILIDADES
    // =========================================================================

    /**
     * Asigna un valor a un campo del formulario identificado por su ID.
     * Solo actúa si el campo existe Y el valor no está vacío.
     * Dispara un evento 'change' para notificar a Select2 y otros listeners.
     *
     * @param {string} idCampo  ID del elemento HTML (sin #).
     * @param {*}      valor    Valor a asignar. Se ignora si es undefined/null/''.
     */
    function _setValorCampo(idCampo, valor) {
        if (valor === undefined || valor === null || valor === '') return;

        const elemento = document.getElementById(idCampo);
        if (!elemento) return; // El campo no existe en este formulario → silencio

        elemento.value = String(valor);

        // Notificar a Select2, Flatpickr y otros plugins de UI
        elemento.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * Actualiza el mensaje de estado del módulo OCR.
     *
     * @param {string} mensaje  Texto a mostrar.
     * @param {string} tipo     'procesando' | 'ok' | 'error'
     */
    function _setEstado(mensaje, tipo) {
        if (!etiquetaEstado) return;

        const coloresPorTipo = {
            procesando: '#4f46e5', // Indigo
            ok:         '#16a34a', // Verde
            error:      '#dc2626', // Rojo
        };

        etiquetaEstado.textContent = mensaje;
        etiquetaEstado.style.color = coloresPorTipo[tipo] ?? '#333333';
    }

})(); // Fin del IIFE