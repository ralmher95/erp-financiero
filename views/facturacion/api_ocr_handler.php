<?php
/**
 * API HANDLER: api_ocr_handler.php
 * UBICACIÓN: /views/facturacion/
 * PROPÓSITO: Endpoint AJAX para el procesamiento OCR de facturas emitidas.
 */

declare(strict_types=1);

// Capturar output inesperado (warnings, notices) para no romper el JSON
ob_start();

// Forzar Content-Type antes de cualquier output
header('Content-Type: application/json; charset=utf-8');

// ── Bootstrap ─────────────────────────────────────────────────────────────────
try {
    // Estamos en /views/facturacion/api_ocr_handler.php, así que subimos 2 niveles hasta la raíz
    $rootPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    
    // db_connect.php inicializa sesión, autoload y variables .env
    require_once $rootPath . 'config/db_connect.php';

} catch (Throwable $e) {
    // Si falla el arranque (ej: .env no encontrado o error en db_connect)
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error crítico de arranque: ' . $e->getMessage()
    ]);
    exit;
}

use App\Services\OcrService;

// ── Función helper: respuesta JSON + salida limpia ────────────────────────────
/**
 * Emite un JSON puro al cliente y termina la ejecución.
 */
function jsonResponse(array $payload, int $httpCode = 200): never
{
    ob_end_clean(); 
    http_response_code($httpCode);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    echo ($json === false) ? json_encode(['status' => 'error', 'message' => 'Error JSON']) : $json;
    exit;
}

// ── Guard: solo POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}

// ── Validación del archivo recibido ──────────────────────────────────────────
$archivo = $_FILES['factura_img'] ?? null;
if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['status' => 'error', 'message' => 'Error en la subida del archivo.'], 400);
}

// ── Mover a directorio temporal ───────────────────────────────────────────────
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp_ocr_factura' . DIRECTORY_SEPARATOR;
if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);

$ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
$rutaTemporal = $tempDir . 'ocr_' . bin2hex(random_bytes(8)) . '.' . $ext;

if (!move_uploaded_file($archivo['tmp_name'], $rutaTemporal)) {
    jsonResponse(['status' => 'error', 'message' => 'No se pudo mover el archivo temporal.'], 500);
}

// ── Procesamiento OCR ─────────────────────────────────────────────────────────
try {
    $ocrService = new OcrService($tempDir);
    $data = $ocrService->procesarFactura($rutaTemporal);

    jsonResponse([
        'status' => 'success',
        'data'   => [
            'emisor'         => trim((string)($data['emisor'] ?? '')),
            'nif'            => strtoupper(trim((string)($data['nif'] ?? ''))),
            'numero_factura' => strtoupper(trim((string)($data['numero_factura'] ?? ''))),
            'fecha'          => trim((string)($data['fecha'] ?? '')),
            'base'           => round((float)($data['base']  ?? 0.0), 2),
            'iva'            => round((float)($data['iva']   ?? 0.0), 2),
            'total'          => round((float)($data['total'] ?? 0.0), 2),
            'tipo_iva'       => (int)($data['tipo_iva'] ?? 21),
            'lineas'         => is_array($data['lineas']) ? $data['lineas'] : []
        ],
    ]);

} catch (Throwable $e) {
    error_log('[api_ocr_handler] Error crítico: ' . $e->getMessage());
    $mensaje = env_bool('APP_DEBUG') ? $e->getMessage() : 'Error al procesar la imagen.';
    jsonResponse(['status' => 'error', 'message' => $mensaje], 500);
} finally {
    if (file_exists($rutaTemporal)) @unlink($rutaTemporal);
}
