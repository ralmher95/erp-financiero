<?php
// =============================================================================
// includes/logger.php — Logger centralizado del ERP
// v2.0 — MEJORAS:
//   ✅ Type hints completos (PHP 8.1+)
//   ✅ Niveles de log como constantes (evita strings mágicos)
//   ✅ Contexto adicional como array (structured logging)
//   ✅ Filtro de nivel mínimo configurable (DEBUG/INFO/WARNING/ERROR)
//   ✅ Rotación básica: el log no crece indefinidamente
//   ✅ Compatible con error_log() estándar (sin dependencias externas)
// =============================================================================

declare(strict_types=1);

// ── Niveles de severidad (orden ascendente) ───────────────────────────────────
// Usar estas constantes en lugar de strings literales para evitar typos.
// CORRECCIÓN v2.1: envueltas con defined() para que logger.php sea seguro
// de incluir múltiples veces (require_once no siempre es suficiente cuando
// hay rutas de include distintas que apuntan al mismo archivo físico).
defined('LOG_DEBUG')   || define('LOG_DEBUG',   'DEBUG');
defined('LOG_INFO')    || define('LOG_INFO',    'INFO');
defined('LOG_WARNING') || define('LOG_WARNING', 'WARNING');
defined('LOG_ERROR')   || define('LOG_ERROR',   'ERROR');

/**
 * Mapa de prioridad numérica para cada nivel.
 * Permite filtrar: si el nivel mínimo es INFO, los DEBUG no se escriben.
 */
defined('LOG_PRIORIDAD') || define('LOG_PRIORIDAD', [
    LOG_DEBUG   => 0,
    LOG_INFO    => 1,
    LOG_WARNING => 2,
    LOG_ERROR   => 3,
]);

/**
 * Registra un evento en el log del servidor (error_log).
 *
 * Formato de salida:
 *   [2025-06-10 14:32:01][INFO][Facturas][IP:192.168.1.1] Factura #42 creada. | ctx={"cliente_id":5}
 *
 * @param string  $nivel    Uno de LOG_DEBUG, LOG_INFO, LOG_WARNING, LOG_ERROR
 * @param string  $modulo   Nombre del módulo o clase que origina el evento
 * @param string  $mensaje  Descripción del evento
 * @param array   $contexto Datos adicionales serializables (ej: IDs, valores)
 */
function log_erp(string $nivel, string $modulo, string $mensaje, array $contexto = []): void
{
    // ── Validar que el nivel sea conocido ─────────────────────────────────────
    if (!array_key_exists($nivel, LOG_PRIORIDAD)) {
        $nivel = LOG_WARNING;
        $mensaje = "[nivel_desconocido] $mensaje";
    }

    // ── Filtro por nivel mínimo ────────────────────────────────────────────────
    // En producción suprimimos DEBUG para no saturar el log.
    // El nivel mínimo se configura en .env: LOG_LEVEL=INFO
    $nivelMinimo    = strtoupper((string)(defined('APP_ENV') && getenv('LOG_LEVEL')
        ? getenv('LOG_LEVEL')
        : (defined('APP_ENV') && APP_ENV === 'production' ? LOG_INFO : LOG_DEBUG)));

    $prioridadMinima = LOG_PRIORIDAD[$nivelMinimo] ?? 0;

    if (LOG_PRIORIDAD[$nivel] < $prioridadMinima) {
        return; // Nivel insuficiente: descartar silenciosamente
    }

    // ── Construir la línea de log ──────────────────────────────────────────────
    $timestamp = date('Y-m-d H:i:s');
    $ip        = $_SERVER['REMOTE_ADDR'] ?? 'cli';

    // Sanitizar el módulo para evitar caracteres de control en el log
    $moduloSanitizado = preg_replace('/[^\w\\\\\/:.-]/', '', $modulo);

    // Serializar el contexto solo si hay datos
    $ctxStr = '';
    if (!empty($contexto)) {
        // JSON_UNESCAPED_UNICODE para no romper caracteres españoles
        $ctxStr = ' | ctx=' . json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $linea = "[$timestamp][$nivel][$moduloSanitizado][IP:$ip] $mensaje$ctxStr";

    // ── Escribir en el log del servidor ───────────────────────────────────────
    error_log($linea);

    // ── Rotación básica (opcional, solo si LOG_FILE está definido) ────────────
    // Si se define LOG_FILE en .env, también escribimos en un archivo propio.
    // Ejemplo: LOG_FILE=/var/log/erp/app.log
    $logFile = getenv('LOG_FILE');
    if ($logFile) {
        escribir_log_archivo($logFile, $linea);
    }
}

/**
 * Escribe una línea en un archivo de log personalizado con rotación básica.
 * El archivo se rota automáticamente cuando supera LOG_MAX_SIZE bytes (default 5MB).
 *
 * @param string $archivo  Ruta absoluta al archivo de log
 * @param string $linea    Línea ya formateada
 */
function escribir_log_archivo(string $archivo, string $linea): void
{
    $maxBytes = (int)(getenv('LOG_MAX_SIZE') ?: 5 * 1024 * 1024); // 5 MB por defecto

    // Rotación: si el archivo supera el límite, lo renombramos con timestamp
    if (file_exists($archivo) && filesize($archivo) > $maxBytes) {
        $backup = $archivo . '.' . date('YmdHis') . '.bak';
        rename($archivo, $backup);
    }

    // Escritura atómica con bloqueo exclusivo para evitar race conditions
    $handle = fopen($archivo, 'a');
    if ($handle) {
        flock($handle, LOCK_EX);
        fwrite($handle, $linea . PHP_EOL);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// ── Helpers de conveniencia ───────────────────────────────────────────────────
// Evitan tener que recordar el nombre de la constante en llamadas frecuentes.

function log_debug(string $modulo, string $mensaje, array $ctx = []): void
{
    log_erp(LOG_DEBUG, $modulo, $mensaje, $ctx);
}

function log_info(string $modulo, string $mensaje, array $ctx = []): void
{
    log_erp(LOG_INFO, $modulo, $mensaje, $ctx);
}

function log_warning(string $modulo, string $mensaje, array $ctx = []): void
{
    log_erp(LOG_WARNING, $modulo, $mensaje, $ctx);
}

function log_error(string $modulo, string $mensaje, array $ctx = []): void
{
    log_erp(LOG_ERROR, $modulo, $mensaje, $ctx);
}
