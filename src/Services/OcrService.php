<?php
// =============================================================================
// ARCHIVO:  src/Services/OcrService.php
//
// MEJORAS RESPECTO A LA VERSIÓN ANTERIOR:
//   ✅ NUEVO: extraerEmisor()       — nombre de la empresa emisora
//   ✅ NUEVO: extraerNumeroFactura()— número/serie del documento (A-2024-001)
//   ✅ NUEVO: extraerTipoIva()      — porcentaje de IVA (0, 4, 10, 21)
//   ✅ NUEVO: extraerLineas()       — tabla de conceptos con cant/precio/subtotal
//   ✅ MEJORADO: extraerFecha()     — convierte DD/MM/YYYY a YYYY-MM-DD
//   ✅ MEJORADO: extraerImporte()   — mayor cobertura de palabras clave
//   ✅ MEJORADO: normalizarNumero() — extraída como método reutilizable
// =============================================================================

declare(strict_types=1);

namespace App\Services;

use Exception;
use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * OcrService
 *
 * Extrae y estructura los datos contables de una imagen o PDF de factura
 * usando Tesseract OCR como motor de reconocimiento de texto.
 *
 * Devuelve un array con la siguiente estructura:
 * [
 *   'emisor'         => string,   // Nombre de la empresa emisora
 *   'nif'            => string,   // NIF/CIF del emisor
 *   'numero_factura' => string,   // Número/serie de la factura
 *   'fecha'          => string,   // Fecha en formato YYYY-MM-DD
 *   'base'           => float,    // Base imponible en euros
 *   'iva'            => float,    // Cuota de IVA en euros
 *   'total'          => float,    // Importe total en euros
 *   'tipo_iva'       => int,      // Porcentaje de IVA (0, 4, 10 o 21)
 *   'lineas'         => array,    // Filas de la tabla de detalle
 * ]
 */
class OcrService
{
    /** Directorio temporal donde se procesan los archivos */
    private string $tempPath;

    /** Ruta al ejecutable de Tesseract (solo necesario en Windows) */
    private ?string $executable = null;

    /**
     * @param string|null $tempPath Directorio temporal. Si es null, usa el del sistema.
     */
    public function __construct(?string $tempPath = null)
    {
        // En Windows, sys_get_temp_dir() devuelve C:\Users\...\AppData\Local\Temp
        $this->tempPath = $tempPath
            ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocr_processing' . DIRECTORY_SEPARATOR;

        // Crear el directorio si no existe
        if (!is_dir($this->tempPath)) {
            @mkdir($this->tempPath, 0777, true);
        }

        // En Windows, Tesseract no está en el PATH por defecto.
        // Buscamos en las rutas de instalación más comunes (Laragon, instalador oficial).
        if (str_contains(strtoupper(PHP_OS), 'WIN')) {
            $rutasComunes = [
                'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
                'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
                'C:\\laragon\\bin\\tesseract\\tesseract.exe',
            ];
            foreach ($rutasComunes as $ruta) {
                if (file_exists($ruta)) {
                    $this->executable = $ruta;
                    break;
                }
            }
        }
    }

    // =========================================================================
    // MÉTODO PÚBLICO
    // =========================================================================

    /**
     * Procesa un archivo de factura y devuelve todos sus datos estructurados.
     *
     * @param  string $imagePath Ruta absoluta al archivo (JPG, PNG o PDF).
     * @return array             Datos completos de la factura.
     * @throws Exception         Si el archivo no existe o el OCR no puede leerlo.
     */
    public function procesarFactura(string $imagePath): array
    {
        try {
            if (!file_exists($imagePath)) {
                throw new Exception("El archivo no existe en la ruta: $imagePath");
            }

            // Configurar el objeto OCR
            $ocr = new TesseractOCR($imagePath);

            // Inyectar el ejecutable si lo encontramos (Windows)
            if ($this->executable) {
                $ocr->executable($this->executable);
            }

            // Usamos español como idioma principal, inglés como fallback.
            // Requiere tener instalados los paquetes de idioma:
            //   Windows: descargar spa.traineddata y eng.traineddata
            //            y colocarlos en C:\Program Files\Tesseract-OCR\tessdata\
            //   Linux:   sudo apt install tesseract-ocr-spa
            $textoExtraido = $ocr->lang('spa', 'eng')->run();

            if (empty(trim($textoExtraido))) {
                throw new Exception(
                    "El OCR no pudo extraer texto. Prueba con una imagen más nítida o mayor resolución."
                );
            }

            // Extraer todas las entidades del texto
            $data = $this->extraerEntidades($textoExtraido);

            // Auditar que los importes cuadren (base + iva ≈ total)
            $this->validarCuadre($data);

            return $data;

        } catch (Exception $e) {
            // Re-lanzar con prefijo para facilitar el diagnóstico en los logs
            error_log('[OcrService::procesarFactura] ' . $e->getMessage());
            throw new Exception("Error en el motor OCR: " . $e->getMessage());
        }
    }

    // =========================================================================
    // EXTRACCIÓN DE ENTIDADES (orquestador)
    // =========================================================================

    /**
     * Coordina la extracción de todos los campos a partir del texto bruto del OCR.
     *
     * @param string $texto Texto completo extraído por Tesseract.
     * @return array        Mapa de campos extraídos.
     */
    private function extraerEntidades(string $texto): array
    {
        // Necesitamos las líneas originales para:
        //   - extraerEmisor(): las primeras líneas suelen ser el nombre de la empresa
        //   - extraerLineas(): recorrer la tabla de detalle fila a fila
        $lineas = preg_split('/\r?\n/', $texto);

        // Versión "plana" del texto (sin saltos de línea) para los patrones de cabecera.
        // Colapsamos también múltiples espacios en uno.
        $textoPlano = preg_replace('/\s+/', ' ', str_replace(["\n", "\r", "\t"], ' ', $texto));

        return [
            // ── Datos de cabecera ────────────────────────────────────────────
            'emisor'         => $this->extraerEmisor($lineas),
            'nif'            => $this->extraerNif($textoPlano),
            'numero_factura' => $this->extraerNumeroFactura($textoPlano),
            'fecha'          => $this->extraerFecha($textoPlano),

            // ── Importes ─────────────────────────────────────────────────────
            // El orden de las palabras clave importa: la más específica primero
            // para evitar que "Base" capture lo que debería capturar "Base Imponible"
            'base'  => $this->extraerImporte($textoPlano, [
                'Base Imponible', 'Base Imp.', 'Importe Neto', 'Subtotal', 'Base',
            ]),
            'iva'   => $this->extraerImporte($textoPlano, [
                'Cuota IVA', 'Importe IVA', 'IVA Repercutido', 'IVA', 'Impuesto',
            ]),
            'total' => $this->extraerImporte($textoPlano, [
                'Total Factura', 'Importe Total', 'Total a Pagar', 'A Pagar', 'Total',
            ]),

            // ── Tipo de IVA (porcentaje) ──────────────────────────────────────
            'tipo_iva' => $this->extraerTipoIva($textoPlano),

            // ── Líneas de detalle (tabla de conceptos) ────────────────────────
            'lineas' => $this->extraerLineas($lineas),
        ];
    }

    // =========================================================================
    // EXTRACCIÓN DE CAMPOS INDIVIDUALES
    // =========================================================================

    /**
     * Extrae el nombre del emisor.
     *
     * Estrategia: el nombre de la empresa suele estar en las primeras líneas
     * del documento, antes de que aparezcan campos estructurados como NIF,
     * Factura, Fecha, etc. Tomamos las dos primeras líneas que parezcan texto.
     *
     * @param  string[] $lineas Líneas del texto OCR.
     * @return string           Nombre del emisor o cadena vacía.
     */
    private function extraerEmisor(array $lineas): string
    {
        $candidatos = [];

        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if (empty($linea)) continue;

            // Paramos al encontrar la primera línea estructurada de la factura
            if (preg_match('/NIF|CIF|N\.I\.F|C\.I\.F|Factura|FACTURA|Fecha|FECHA|Total|IVA/i', $linea)) {
                break;
            }

            // Ignorar líneas demasiado cortas o que sean solo números
            if (mb_strlen($linea, 'UTF-8') < 4 || is_numeric($linea)) {
                continue;
            }

            $candidatos[] = $linea;

            // Con dos líneas tenemos suficiente (razón social + subtítulo opcional)
            if (count($candidatos) >= 2) break;
        }

        // Unir con separador visual si hay varios fragmentos
        return implode(' · ', $candidatos);
    }

    /**
     * Extrae el NIF/CIF del emisor.
     *
     * Patrones soportados:
     *   "NIF: B-12345678"  →  "B12345678"
     *   "CIF A28015865"    →  "A28015865"
     *   "N.I.F: 12345678Z" →  "12345678Z"
     *
     * @param  string $texto Texto plano del OCR.
     * @return string        NIF/CIF en mayúsculas o cadena vacía.
     */
    private function extraerNif(string $texto): string
    {
        $resultado = $this->matchPattern(
            $texto,
            '/(?:NIF|CIF|N\.I\.F\.?|C\.I\.F\.?|VAT|ID)[:\s]*([A-Z]-?\d{7,8}[A-Z0-9])/i'
        );
        
        // Si no se encuentra con prefijo, buscar cualquier patrón que parezca un NIF/CIF español
        if (empty($resultado)) {
            $resultado = $this->matchPattern($texto, '/([A-Z]\d{8}|\d{8}[A-Z])/i');
        }

        return strtoupper($resultado);
    }

    /**
     * Extrae el número/serie de la factura.
     *
     * Patrones soportados:
     *   "Factura Nº A-2024-001"  →  "A-2024-001"
     *   "Fra. F2025/042"         →  "F2025/042"
     *   "Nº Factura: 2024-0001"  →  "2024-0001"
     *   "Invoice: INV-001"       →  "INV-001"
     *
     * @param  string $texto Texto plano del OCR.
     * @return string        Número de factura en mayúsculas o cadena vacía.
     */
    private function extraerNumeroFactura(string $texto): string
    {
        // Intentar los patrones del más específico al más genérico
        $patrones = [
            // "Factura Nº", "Fra. Nº", "Nº Factura"
            '/(?:Factura|Fra\.?|Invoice)[:\s#Nº°nNo\.]*\s*([A-Z0-9][\w\/\-]{2,20})/i',
            // "Nº:" o "Número:" seguido del código
            '/N[º°ú](?:mero)?\.?\s*(?:de\s+factura)?[:\s]*([A-Z0-9][\w\/\-]{2,20})/i',
        ];

        foreach ($patrones as $patron) {
            $resultado = $this->matchPattern($texto, $patron);
            if ($resultado !== '') {
                return strtoupper(trim($resultado));
            }
        }

        return '';
    }

    /**
     * Extrae y normaliza la fecha del documento.
     *
     * Formatos de entrada soportados:
     *   DD/MM/YYYY, DD-MM-YYYY, DD.MM.YYYY
     *
     * Formato de salida:
     *   YYYY-MM-DD (compatible con input[type=date] de HTML5)
     *
     * @param  string $texto Texto plano del OCR.
     * @return string        Fecha en formato YYYY-MM-DD o cadena vacía.
     */
    private function extraerFecha(string $texto): string
    {
        // Buscar fecha con contexto (opcional) para mayor precisión
        // Ejemplo: "Fecha: 15/03/2025" o simplemente "15-03-2025"
        $raw = $this->matchPattern(
            $texto,
            '/(?:Fecha|Date|Emisi[oó]n)?[:\s]*(\d{2}[\\/\-\.]\d{2}[\\/\-\.]\d{4})/'
        );

        if ($raw === '') return '';

        // Dividir por cualquier separador (/, -, .)
        $partes = preg_split('/[\\/\-\.]/', $raw);
        if (count($partes) !== 3) return $raw;

        [$dia, $mes, $anio] = $partes;

        // Validar que la fecha resultante sea real
        if (!checkdate((int)$mes, (int)$dia, (int)$anio)) return '';

        // Devolver en formato ISO 8601 para el input[type=date]
        return sprintf('%04d-%02d-%02d', (int)$anio, (int)$mes, (int)$dia);
    }

    /**
     * Detecta el porcentaje de IVA aplicado en la factura.
     *
     * Estrategia: buscar patrones como "IVA 21%", "21 % IVA", etc.
     * Si no se encuentra, devuelve 21 (tipo general español, el más común).
     *
     * @param  string $texto Texto plano del OCR.
     * @return int           Porcentaje de IVA normalizado: 0, 4, 10 o 21.
     */
    private function extraerTipoIva(string $texto): int
    {
        $porcentaje = null;

        // Patrón 1: "IVA 21%", "IVA: 21 %", "IVA(21%)"
        if (preg_match('/IVA[:\s(]*(\d{1,2})\s*%/i', $texto, $m)) {
            $porcentaje = (int)$m[1];
        }
        // Patrón 2: "21% IVA", "21 % de IVA"
        elseif (preg_match('/(\d{1,2})\s*%\s*(?:de\s+)?IVA/i', $texto, $m)) {
            $porcentaje = (int)$m[1];
        }

        if ($porcentaje === null) {
            return 21; // Tipo general como default
        }

        // Normalizar al valor PGC más cercano (España: 0, 4, 10, 21)
        return $this->normalizarTipoIva($porcentaje);
    }

    /**
     * Redondea un porcentaje de IVA al valor PGC español más cercano.
     *
     * @param  int $pct Porcentaje detectado (puede estar ligeramente desviado por el OCR).
     * @return int      Valor PGC válido: 0, 4, 10 o 21.
     */
    private function normalizarTipoIva(int $pct): int
    {
        $valoresPgc = [0, 4, 10, 21];

        // Elegir el valor de la lista cuya diferencia absoluta con $pct sea menor
        return (int)array_reduce(
            $valoresPgc,
            static fn($prev, $curr) => abs($curr - $pct) < abs($prev - $pct) ? $curr : $prev,
            21
        );
    }

    /**
     * Extrae las líneas de detalle de la factura (tabla de conceptos).
     *
     * Estrategia:
     *   1. Buscar la cabecera de la tabla (palabras como "Concepto", "Descripción"...)
     *   2. A partir de ahí, procesar cada línea que contenga al menos 2 valores numéricos
     *   3. Parar cuando llegamos a los totales (Base, Subtotal, IVA, Total...)
     *
     * Heurística de columnas (de derecha a izquierda):
     *   Último número  → subtotal
     *   Penúltimo      → precio unitario
     *   Antepenúltimo  → cantidad (si existe; si no, asumimos 1)
     *
     * @param  string[] $lineas Líneas del texto OCR.
     * @return array            Array de [ concepto, cantidad, precio, subtotal ].
     */
    private function extraerLineas(array $lineas): array
    {
        $resultados     = [];
        $enSeccion      = false; // Bandera: estamos dentro de la tabla de detalle

        // 1. Identificar la cabecera de la tabla para empezar a procesar
        foreach ($lineas as $i => $linea) {
            $linea = trim($linea);
            if (empty($linea)) continue;

            // Ignorar líneas que contengan "Fecha" o "Nº Factura" ya que son cabecera de factura, no de tabla
            if (preg_match('/Fecha\*|Nº Factura\*|Cliente\*/i', $linea)) {
                continue;
            }

            // Detectar inicio de la sección de detalle (cabecera de la tabla)
            if (!$enSeccion && preg_match(
                '/(?:CONCEPTO|DESCRIPCI[OÓ]N|DETALLE|PRODUCTO|SERVICIO|ART[IÍ]CULO).*?(?:CANT|CANTIDAD).*?(?:PRECIO|IMPORTE|UNITARIO)/i',
                $linea
            )) {
                $enSeccion = true;
                continue;
            }

            // Detectar fin de la sección de detalle (totales)
            // Quitamos el anclaje ^ para que sea más flexible si hay espacios
            if ($enSeccion && preg_match(
                '/(?:Base Imponible|Subtotal|IVA Total|TOTAL FACTURA|Descuento|Dto\.)/i',
                $linea
            )) {
                break;
            }

            if (!$enSeccion) continue;

            // 2. Extraer valores numéricos de la línea (Precio, Cantidad, Subtotal)
            // Buscamos números al final de la línea (típico de tablas)
            preg_match_all('/\d+(?:[.,]\d+)*/', $linea, $coincidencias);
            $numeros = $coincidencias[0];

            // Necesitamos al menos 2 números para que sea una línea válida
            if (count($numeros) < 2) continue;

            // Normalizar a float
            $flotantes = array_map([$this, 'normalizarNumero'], $numeros);

            // Heurística de columnas de derecha a izquierda:
            // [..., Cantidad, Precio, IVA%, Subtotal] o similar
            $subtotal = array_pop($flotantes); // El último suele ser el subtotal de línea
            
            // Si el penúltimo es un IVA (0, 4, 10, 21), lo saltamos para llegar al precio
            $ultimo = end($flotantes);
            if ($ultimo !== false && in_array((int)$ultimo, [0, 4, 10, 21])) {
                array_pop($flotantes);
            }
            
            $precio   = !empty($flotantes) ? array_pop($flotantes) : $subtotal;
            $cantidad = !empty($flotantes) ? array_pop($flotantes) : 1.0;

            // Si la cantidad parece un año (ej. 2024) o es incoherente, la forzamos a 1
            if ($cantidad > 500 || $cantidad <= 0) {
                $cantidad = 1.0;
            }

            // 3. Extraer la descripción (todo lo que no sea el bloque numérico final)
            $descripcion = trim(preg_replace('/\d+(?:[.,]\d+)*.*$/', '', $linea));
            
            // Limpieza de ruido común del OCR en descripciones
            $descripcion = preg_replace('/^[\s\|\-\•\*]+/', '', $descripcion);
            
            if (empty($descripcion) || strlen($descripcion) < 3) {
                $descripcion = 'Concepto extraído';
            }

            // Evitar duplicar líneas que sean solo etiquetas de la tabla
            if (preg_match('/^(?:CONCEPTO|CANT|PRECIO|IVA|SUBTOTAL)$/i', $descripcion)) {
                continue;
            }

            $resultados[] = [
                'concepto' => $descripcion,
                'cantidad' => $cantidad,
                'precio'   => $precio,
                'subtotal' => $subtotal,
            ];
        }

        return $resultados;
    }

    // =========================================================================
    // UTILIDADES
    // =========================================================================

    /**
     * Busca un importe monetario precedido por una de las palabras clave dadas.
     *
     * Soporta formatos de número:
     *   Formato europeo:   1.234,56  →  1234.56
     *   Formato americano: 1,234.56  →  1234.56
     *   Decimal con coma:  1234,56   →  1234.56
     *   Entero:            1234      →  1234.00
     *
     * @param  string   $texto    Texto plano del OCR.
     * @param  string[] $keywords Palabras clave a buscar (de más específica a más general).
     * @return float              Importe encontrado o 0.0 si no se localiza.
     */
    private function extraerImporte(string $texto, array $keywords): float
    {
        foreach ($keywords as $keyword) {
            // Patrón: keyword + separadores opcionales + número
            // Ejemplo: "Base Imponible: 1.500,00" o "Total   1500.00 €"
            $patron = '/' . preg_quote($keyword, '/') . '[:\s€$]*([\d.,]+)/i';

            if (preg_match($patron, $texto, $coincidencias)) {
                return $this->normalizarNumero($coincidencias[1]);
            }
        }

        return 0.0;
    }

    /**
     * Normaliza un string numérico a float, soportando formatos europeos y americanos.
     *
     * Casos:
     *   "1.234,56" (europeo)   → 1234.56  (punto=miles, coma=decimal)
     *   "1,234.56" (americano) → 1234.56  (coma=miles, punto=decimal)
     *   "1.234"    (ambiguo)   → 1234.0   (interpretamos punto como miles si no hay coma)
     *   "1234,56"  (ES simple) → 1234.56  (coma=decimal)
     *   "1234.56"  (US simple) → 1234.56  (sin conversión)
     *
     * @param  string $valor String numérico a convertir.
     * @return float         Valor float normalizado.
     */
    private function normalizarNumero(string $valor): float
    {
        $valor = trim($valor);

        // Caso 1: tiene TANTO punto como coma → europeo (punto=miles, coma=decimal)
        if (str_contains($valor, '.') && str_contains($valor, ',')) {
            $valor = str_replace('.', '', $valor); // Quitar separador de miles
            $valor = str_replace(',', '.', $valor); // Coma decimal → punto decimal
        }
        // Caso 2: solo coma → decimal europeo simple (1234,56)
        elseif (str_contains($valor, ',')) {
            $valor = str_replace(',', '.', $valor);
        }
        // Caso 3: solo punto → puede ser decimal (1234.56) o miles europeo (1.234)
        // Heurística: si hay exactamente 3 dígitos después del punto, asumimos miles
        elseif (preg_match('/^\d{1,3}\.\d{3}$/', $valor)) {
            $valor = str_replace('.', '', $valor); // Tratar como separador de miles
        }
        // Caso 4: sin separadores ni comas → número entero (1234)

        return (float)$valor;
    }

    /**
     * Aplica un patrón regex al texto y devuelve el primer grupo de captura.
     *
     * @param  string $texto   Texto donde buscar.
     * @param  string $patron  Patrón regex (con al menos un grupo de captura).
     * @return string          Primer grupo capturado o cadena vacía.
     */
    private function matchPattern(string $texto, string $patron): string
    {
        if (preg_match($patron, $texto, $coincidencias)) {
            return trim($coincidencias[1] ?? $coincidencias[0] ?? '');
        }
        return '';
    }

    // =========================================================================
    // AUDITORÍA ARITMÉTICA
    // =========================================================================

    /**
     * Verifica que base + iva ≈ total (tolerancia de ±0.05 € para redondeos).
     *
     * No lanza excepción: solo registra un warning en el log del servidor.
     * El usuario puede corregir los valores manualmente en el formulario.
     *
     * @param array<string, mixed> $data Datos extraídos por extraerEntidades().
     */
    private function validarCuadre(array $data): void
    {
        $base  = (float)($data['base']  ?? 0);
        $iva   = (float)($data['iva']   ?? 0);
        $total = (float)($data['total'] ?? 0);

        // Si no se leyó el total, no podemos validar nada
        if ($total <= 0.0) return;

        $sumaCalculada = round($base + $iva, 2);
        $totalLeido    = round($total, 2);

        if (abs($sumaCalculada - $totalLeido) > 0.05) {
            error_log(sprintf(
                '[OcrService] ⚠️  Descuadre detectado: Base(%.2f) + IVA(%.2f) = %.2f ≠ Total(%.2f). ' .
                'Diferencia: %.2f €. Puede ser un redondeo del OCR.',
                $base, $iva, $sumaCalculada, $totalLeido,
                abs($sumaCalculada - $totalLeido)
            ));
        }
    }
}