<?php
// config/app.php
// MEJORA 6.2 — Separar la configuración de aplicación de db_connect.php.
// URL_BASE, DEBUG, APP_NAME y TIMEZONE en un único lugar.
// Incluir ANTES de db_connect.php o junto a él.

declare(strict_types=1);
require_once __DIR__ . '/../includes/helpers.php';

// ── Entorno ────────────────────────────────────────────────────────────────
define('APP_ENV',  getenv('APP_ENV') ?: 'production'); // 'development' | 'production'
define('APP_NAME', 'ERP Financiero');

// ── URL base ───────────────────────────────────────────────────────────────
// Detecta automáticamente el protocolo y host.
// Puedes sobreescribirla con una variable de entorno en producción:
//   APP_URL=https://mi-erp.com php ...
if (getenv('APP_URL')) {
    define('URL_BASE', rtrim(getenv('APP_URL'), '/') . '/');
} else {
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Sube dos niveles desde /config/app.php hasta la raíz del proyecto
    $root_dir = rtrim(dirname(dirname(__FILE__)), DIRECTORY_SEPARATOR);
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', DIRECTORY_SEPARATOR);
    $base_path = str_replace($doc_root, '', $root_dir);
    $base_path = str_replace(DIRECTORY_SEPARATOR, '/', $base_path);
    define('URL_BASE', $scheme . '://' . $host . $base_path . '/');
}

// ── Timezone ───────────────────────────────────────────────────────────────
define('APP_TIMEZONE', 'Europe/Madrid');
date_default_timezone_set(APP_TIMEZONE);

// ── Sesión ─────────────────────────────────────────────────────────────────
// Arrancar sesión de forma segura si no está activa ya.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (APP_ENV === 'production'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── CSRF Token (C-01) ──────────────────────────────────────────────────────
// Se genera una vez por sesión y se reutiliza en todos los formularios.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Helper CSRF ────────────────────────────────────────────────────────────
/**
 * Imprime un campo hidden con el token CSRF.
 * Usar dentro de cualquier <form> que realice acciones destructivas.
 */
function csrf_field(): void
{
    $token = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES);
    echo "<input type=\"hidden\" name=\"csrf_token\" value=\"$token\">";
}

/**
 * Verifica el token CSRF enviado en POST.
 * Aborta con 403 si no coincide.
 */
function csrf_verify(): void
{
    $enviado = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $enviado)) {
        http_response_code(403);
        die('⛔ Acción no autorizada. Token CSRF inválido.');
    }
}
