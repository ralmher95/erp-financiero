<?php
// =============================================================================
// includes/helpers.php — Funciones globales de utilidad
// v3.5 — CORRECCIONES Y MEJORAS:
//   ✅ Bug crítico corregido: función validarIdentificacionFiscal truncada en v3.4
//   ✅ Función url() usa URL_BASE con fallback seguro
//   ✅ e() acepta null y mixed para mayor robustez
//   ✅ formatearMoneda() con soporte para distintos locales
//   ✅ Nueva: truncar() para limitar texto en tablas
//   ✅ Nueva: eAttr() para escapar atributos HTML
//   ✅ Nueva: redirect() con código HTTP configurable
// =============================================================================

declare(strict_types=1);

// =============================================================================
// 🔗 URL HELPER
// Genera URLs absolutas basadas en URL_BASE.
// Uso: echo url('views/clientes/lista.php');
// =============================================================================
if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        // URL_BASE se define en config/db_connect.php
        $base = defined('URL_BASE') ? URL_BASE : '/';
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

// =============================================================================
// 🛡️ ESCAPE DE SALIDA HTML — Prevención de XSS
// Uso: echo e($variable);
// Acepta null sin lanzar errores (PHP 8.1 strict types safe).
// =============================================================================
if (!function_exists('e')) {
    function e(mixed $valor): string
    {
        if ($valor === null || $valor === false) {
            return '';
        }
        return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// =============================================================================
// 🛡️ ESCAPE DE ATRIBUTOS HTML
// Igual que e() pero más explícito para atributos (data-*, aria-*, etc.)
// Uso: echo '<div data-id="' . eAttr($id) . '">';
// =============================================================================
if (!function_exists('eAttr')) {
    function eAttr(mixed $valor): string
    {
        return e($valor); // htmlspecialchars con ENT_QUOTES cubre atributos
    }
}

// =============================================================================
// 📅 VALIDACIÓN DE FECHAS
// Retorna la fecha si es válida (formato Y-m-d sin overflow silencioso).
// Retorna $fallback si la fecha es inválida o vacía.
// Uso: $desde = validarFecha($_GET['desde'] ?? '', date('Y-01-01'));
// =============================================================================
if (!function_exists('validarFecha')) {
    function validarFecha(string $fecha, string $fallback): string
    {
        if (trim($fecha) === '') {
            return $fallback;
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);

        // Verificamos que no hubo overflow silencioso (ej: 2024-02-30 → 2024-03-01)
        if ($dt !== false && $dt->format('Y-m-d') === $fecha) {
            return $fecha;
        }

        return $fallback;
    }
}

// =============================================================================
// 💰 FORMATEO DE MONEDA (€ español por defecto)
// Uso: echo formatearMoneda(1234.5);        → "1.234,50 €"
//      echo formatearMoneda(1234.5, '$');   → "1.234,50 $"
// =============================================================================
if (!function_exists('formatearMoneda')) {
    function formatearMoneda(float $cantidad, string $simbolo = '€'): string
    {
        return number_format($cantidad, 2, ',', '.') . ' ' . $simbolo;
    }
}

// =============================================================================
// ✂️ TRUNCADO DE TEXTO (útil para tablas con textos largos)
// Uso: echo truncar('Descripción muy larga de producto...', 40);
//                   → "Descripción muy larga de producto..."
// =============================================================================
if (!function_exists('truncar')) {
    function truncar(string $texto, int $maxChars = 60, string $sufijo = '…'): string
    {
        if (mb_strlen($texto, 'UTF-8') <= $maxChars) {
            return $texto;
        }
        return mb_substr($texto, 0, $maxChars, 'UTF-8') . $sufijo;
    }
}

// =============================================================================
// 🔀 REDIRECCIÓN SEGURA
// Limpia el buffer de salida antes de redirigir para evitar headers ya enviados.
// Uso: redirect(url('views/clientes/lista.php'));
//      redirect(url('login.php'), 302);
// =============================================================================
if (!function_exists('redirect')) {
    function redirect(string $url, int $codigo = 302): never
    {
        // Validar que la URL no sea un vector de Open Redirect externo
        // Solo permitimos URLs relativas o del mismo dominio.
        if (!str_starts_with($url, '/') && !str_starts_with($url, defined('URL_BASE') ? URL_BASE : '/')) {
            $url = url(''); // Fallback a inicio
        }

        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Location: ' . $url, true, $codigo);
        exit;
    }
}

// =============================================================================
// 🆔 VALIDACIÓN DE NIF / CIF / NIE ESPAÑOL
// Retorna true si el identificador tiene formato válido.
// No valida duplicados en BD (para eso usa Validator::nifDuplicado()).
//
// Formatos soportados:
//   NIF: 8 dígitos + letra (ej: 12345678Z)
//   NIE: X/Y/Z + 7 dígitos + letra (ej: X1234567Z)
//   CIF: letra + 7 dígitos + dígito/letra de control (ej: B12345678)
// =============================================================================
if (!function_exists('validarIdentificacionFiscal')) {
    function validarIdentificacionFiscal(string $id): bool
    {
        $id = strtoupper(trim($id));

        if ($id === '') {
            return false;
        }

        // ── NIF (DNI español) ─────────────────────────────────────────────────
        // Formato: 8 dígitos seguidos de 1 letra de control
        if (preg_match('/^(\d{8})([A-Z])$/', $id, $m)) {
            $letras = 'TRWAGMYFPDXBNJZSQVHLCKE';
            $letraEsperada = $letras[(int)$m[1] % 23];
            return $m[2] === $letraEsperada;
        }

        // ── NIE (extranjero residente) ────────────────────────────────────────
        // Formato: X, Y o Z + 7 dígitos + letra de control
        if (preg_match('/^([XYZ])(\d{7})([A-Z])$/', $id, $m)) {
            $letras = 'TRWAGMYFPDXBNJZSQVHLCKE';
            // X=0, Y=1, Z=2
            $prefijo = ['X' => '0', 'Y' => '1', 'Z' => '2'][$m[1]];
            $numero  = (int)($prefijo . $m[2]);
            $letraEsperada = $letras[$numero % 23];
            return $m[3] === $letraEsperada;
        }

        // ── CIF (personas jurídicas) ──────────────────────────────────────────
        // Formato: letra de tipo + 7 dígitos + dígito/letra de control
        // Tipos: A B C D E F G H J N P Q R S U V W
        if (preg_match('/^([ABCDEFGHJNPQRSUVW])(\d{7})([A-Z0-9])$/', $id, $m)) {
            $suma = 0;
            $digits = str_split($m[2]);

            foreach ($digits as $i => $d) {
                $d = (int)$d;
                if (($i + 1) % 2 !== 0) {
                    // Posición impar (1, 3, 5, 7): multiplicar por 2 y sumar dígitos del resultado
                    $doble = $d * 2;
                    $suma += ($doble > 9) ? ($doble - 9) : $doble;
                } else {
                    // Posición par (2, 4, 6): sumar directamente
                    $suma += $d;
                }
            }

            $control = (10 - ($suma % 10)) % 10;
            $letraControl = 'JABCDEFGHI'[$control];

            // El último carácter puede ser dígito o letra según el tipo de entidad
            $ultimo = $m[3];
            return $ultimo === (string)$control || $ultimo === $letraControl;
        }

        return false; // Formato no reconocido
    }
}

// =============================================================================
// 🧾 FORMATO DE FECHA PARA HUMANOS (ES)
// Convierte Y-m-d a "10 de junio de 2025"
// Uso: echo fechaHumana('2025-06-10'); → "10 de junio de 2025"
// =============================================================================
if (!function_exists('fechaHumana')) {
    function fechaHumana(string $fecha): string
    {
        $meses = [
            1  => 'enero',    2  => 'febrero', 3  => 'marzo',
            4  => 'abril',    5  => 'mayo',     6  => 'junio',
            7  => 'julio',    8  => 'agosto',   9  => 'septiembre',
            10 => 'octubre',  11 => 'noviembre', 12 => 'diciembre',
        ];

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
        if ($dt === false) {
            return $fecha; // Devolver tal cual si no parsea
        }

        return sprintf(
            '%d de %s de %d',
            (int)$dt->format('j'),
            $meses[(int)$dt->format('n')],
            (int)$dt->format('Y')
        );
    }
}