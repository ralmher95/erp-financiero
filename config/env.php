<?php
// =============================================================================
// config/env.php — Cargador de variables .env sin dependencias externas
// v2.0 — MEJORAS:
//   - die() reemplazado por Exception (no expone rutas del servidor)
//   - Helper env() con tipado estricto y soporte para bool/int casting
//   - Ignorar líneas en blanco y comentarios inline
//   - Función cargar_env() idempotente (no re-procesa si ya está cargado)
// =============================================================================

declare(strict_types=1);

/**
 * Carga un archivo .env en $_ENV y putenv().
 *
 * @param  string $rutaEnv  Ruta absoluta al archivo .env
 * @throws RuntimeException Si el archivo no existe
 */
function cargar_env(string $rutaEnv): void
{
    // Idempotente: si ya cargamos el .env en esta petición, no repetir
    static $cargado = false;
    if ($cargado) {
        return;
    }

    if (!file_exists($rutaEnv)) {
        // ⚠️ SEGURIDAD: No exponemos la ruta en el mensaje al usuario,
        // pero sí la logueamos internamente para el desarrollador.
        error_log("[ERP][env] Archivo .env no encontrado en: $rutaEnv");
        throw new RuntimeException(
            'Configuración de entorno no encontrada. Copia .env.example a .env y rellena tus credenciales.'
        );
    }

    $lineas = file($rutaEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lineas === false) {
        throw new RuntimeException('No se pudo leer el archivo .env (permisos insuficientes).');
    }

    foreach ($lineas as $linea) {
        $linea = trim($linea);

        // Ignorar comentarios (inicio de línea con #)
        if (str_starts_with($linea, '#') || $linea === '') {
            continue;
        }

        // Ignorar líneas sin el separador =
        if (!str_contains($linea, '=')) {
            continue;
        }

        // Separar clave y valor (solo en el primer =)
        [$clave, $valor] = explode('=', $linea, 2);
        $clave = trim($clave);
        $valor = trim($valor);

        // Ignorar claves vacías
        if ($clave === '') {
            continue;
        }

        // Eliminar comentarios inline: KEY=valor # esto es un comentario
        if (str_contains($valor, ' #')) {
            $valor = trim(explode(' #', $valor, 2)[0]);
        }

        // Eliminar comillas delimitadoras opcionales ("valor" o 'valor')
        if (strlen($valor) >= 2) {
            $primero = $valor[0];
            $ultimo  = $valor[-1];
            if (($primero === '"' && $ultimo === '"') || ($primero === "'" && $ultimo === "'")) {
                $valor = substr($valor, 1, -1);
            }
        }

        // Solo setear si no existe ya (permite que el entorno del SO tenga prioridad)
        if (!array_key_exists($clave, $_ENV)) {
            $_ENV[$clave] = $valor;
            putenv("$clave=$valor");
        }
    }

    $cargado = true;
}

/**
 * Lee una variable de entorno con soporte para casting de tipos.
 *
 * Uso:
 *   env('DB_HOST')                → string|null
 *   env('DB_PORT', 3306)          → string|int (valor raw o default)
 *   env('APP_DEBUG', false)       → valor raw, default false
 *   env_bool('APP_DEBUG')         → bool estricto
 *   env_int('DB_PORT', 3306)      → int estricto
 *
 * @param  string $clave
 * @param  mixed  $defecto  Valor por defecto si la clave no existe
 * @return mixed
 */
function env(string $clave, mixed $defecto = null): mixed
{
    // Prioridad: $_ENV → getenv() → defecto
    if (array_key_exists($clave, $_ENV)) {
        return $_ENV[$clave];
    }

    $valor = getenv($clave);
    if ($valor !== false) {
        return $valor;
    }

    return $defecto;
}

/**
 * Lee una variable de entorno y la convierte estrictamente a bool.
 * Valores "truthy": 'true', '1', 'yes', 'on' (case-insensitive)
 * Valores "falsy":  'false', '0', 'no', 'off', '' y cualquier otro
 */
function env_bool(string $clave, bool $defecto = false): bool
{
    $valor = env($clave);
    if ($valor === null) {
        return $defecto;
    }
    return in_array(strtolower((string)$valor), ['true', '1', 'yes', 'on'], true);
}

/**
 * Lee una variable de entorno y la convierte estrictamente a int.
 */
function env_int(string $clave, int $defecto = 0): int
{
    $valor = env($clave);
    if ($valor === null) {
        return $defecto;
    }
    return (int)$valor;
}