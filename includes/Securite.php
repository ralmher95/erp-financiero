<?php
// =============================================================================
// includes/Securite.php — Capa de seguridad complementaria
// v2.0 — CORRECCIONES CRÍTICAS:
//   ✅ Las funciones csrf_field() y csrf_verify() ya están definidas en
//      config/db_connect.php (que es el boot central). Este archivo solo
//      proporciona helpers de seguridad adicionales que db_connect.php
//      no cubre: rate limiting, sanitización, headers de seguridad.
//   ✅ Eliminada redefinición duplicada de csrf_field()/csrf_verify()
//      (provocaba Fatal Error: Cannot redeclare function)
//   ✅ Añadidos Security Headers recomendados para OWASP Top 10
// =============================================================================

declare(strict_types=1);

// =============================================================================
// 🔒 HEADERS DE SEGURIDAD HTTP
// Llamar una vez al inicio de la respuesta (antes de cualquier echo/html).
// Aplica cabeceras recomendadas por OWASP para mitigar clickjacking,
// MIME sniffing, y XSS reflejado.
// =============================================================================
if (!function_exists('aplicar_security_headers')) {
    function aplicar_security_headers(): void
    {
        // Evitar clickjacking: la página no puede incrustarse en iframes externos
        header('X-Frame-Options: SAMEORIGIN');

        // Desactivar MIME sniffing del navegador
        header('X-Content-Type-Options: nosniff');

        // Habilitar el filtro XSS en navegadores legacy (IE, Chrome < 78)
        header('X-XSS-Protection: 1; mode=block');

        // Política de referrer: no enviar la URL completa a sitios externos
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Content Security Policy básica:
        // - Scripts solo del mismo origen o CDNs aprobados
        // - Estilos solo del mismo origen o CDNs aprobados
        // ⚠️ Ajustar si se usan CDNs adicionales (Chart.js, Select2, etc.)
        header(
            "Content-Security-Policy: " .
            "default-src 'self'; " .
            "script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'; " .
            "style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com 'unsafe-inline'; " .
            "font-src 'self' https://fonts.gstatic.com; " .
            "img-src 'self' data:; " .
            "object-src 'none';"
        );

        // HTTPS obligatorio en producción (HSTS — 1 año)
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

// =============================================================================
// 🚦 RATE LIMITING SIMPLE (basado en sesión)
// Previene ataques de fuerza bruta en formularios de login u operaciones críticas.
//
// Uso:
//   if (!rate_limit_ok('login', maxIntentos: 5, ventanaSegundos: 300)) {
//       die('Demasiados intentos. Espera 5 minutos.');
//   }
// =============================================================================
if (!function_exists('rate_limit_ok')) {
    function rate_limit_ok(string $accion, int $maxIntentos = 5, int $ventanaSegundos = 300): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $clave = "_rl_{$accion}";
        $ahora = time();

        // Inicializar si no existe
        if (!isset($_SESSION[$clave])) {
            $_SESSION[$clave] = ['count' => 0, 'inicio' => $ahora];
        }

        $rl = &$_SESSION[$clave];

        // Resetear ventana si ha expirado
        if ($ahora - $rl['inicio'] > $ventanaSegundos) {
            $rl = ['count' => 0, 'inicio' => $ahora];
        }

        $rl['count']++;

        return $rl['count'] <= $maxIntentos;
    }
}

// =============================================================================
// 🧹 SANITIZACIÓN DE STRINGS DE ENTRADA
// Limpieza básica para texto libre (nombres, conceptos, notas).
// NO usar como única protección contra SQLi (para eso: prepared statements).
//
// Uso: $nombre = sanitizar_texto($_POST['nombre'] ?? '');
// =============================================================================
if (!function_exists('sanitizar_texto')) {
    function sanitizar_texto(string $valor, int $maxLen = 255): string
    {
        // Eliminar espacios sobrantes + caracteres de control no imprimibles
        $limpio = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $valor) ?? '');

        // Truncar si supera el límite de longitud
        if (mb_strlen($limpio, 'UTF-8') > $maxLen) {
            $limpio = mb_substr($limpio, 0, $maxLen, 'UTF-8');
        }

        return $limpio;
    }
}

// =============================================================================
// 🔑 VERIFICACIÓN DE MÉTODO HTTP
// Aborta con 405 si la petición no usa el método esperado.
// Evita que endpoints POST sean accedidos por GET y viceversa.
//
// Uso: verificar_metodo('POST');
// =============================================================================
if (!function_exists('verificar_metodo')) {
    function verificar_metodo(string $metodoEsperado): void
    {
        $metodoEsperado = strtoupper($metodoEsperado);
        $metodoActual   = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');

        if ($metodoActual !== $metodoEsperado) {
            http_response_code(405);
            header('Allow: ' . $metodoEsperado);
            die("Método HTTP no permitido. Se esperaba: $metodoEsperado");
        }
    }
}

// =============================================================================
// 🔐 VERIFICACIÓN DE AUTENTICACIÓN (placeholder extensible)
// Comprueba que el usuario está autenticado en la sesión.
// Ampliar cuando se implemente el sistema de login completo.
//
// Uso: requerir_autenticacion();
// =============================================================================
if (!function_exists('requerir_autenticacion')) {
    function requerir_autenticacion(string $loginUrl = ''): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Por ahora el ERP no tiene login; este check es extensible.
        // Cuando se implemente: verificar $_SESSION['usuario_id'] aquí.
        // if (empty($_SESSION['usuario_id'])) {
        //     $destino = $loginUrl ?: (defined('URL_BASE') ? URL_BASE . 'login.php' : '/login.php');
        //     header('Location: ' . $destino);
        //     exit;
        // }
    }
}