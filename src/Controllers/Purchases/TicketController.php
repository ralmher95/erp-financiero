<?php
// =============================================================================
// src/Controllers/Purchases/TicketController.php — Gestión de tickets OCR
// FIX V4: Path Traversal en upload de archivos
// =============================================================================

declare(strict_types=1);

namespace App\Controllers\Purchases;

use App\Services\OcrService;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use PDO;

class TicketController
{
    private PDO $pdo;
    private OcrService $ocr;
    private string $storageDir;

    private const EXTENSIONES_PERMITIDAS = ['pdf', 'jpg', 'jpeg', 'png', 'tiff'];
    private const TAMAÑO_MAXIMO_MB = 8;
    private const MIME_TYPES_PERMITIDOS = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/tiff',
    ];

    public function __construct(PDO $pdo, OcrService $ocr, string $storageDir)
    {
        $this->pdo = $pdo;
        $this->ocr = $ocr;
        $this->storageDir = rtrim($storageDir, DIRECTORY_SEPARATOR);

        // Validar que el directorio existe y es escribible
        if (!is_dir($this->storageDir) || !is_writable($this->storageDir)) {
            throw new RuntimeException("Storage directory no es escribible: {$this->storageDir}");
        }
    }

    /**
     * Procesa upload de ticket (factura de compra).
     * 
     * @param array $files $_FILES array
     * @return array{ok: bool, ticket_id: int|null, errores: string[]}
     */
    public function subirTicket(array $files): array
    {
        $errores = [];

        try {
            // ✅ VALIDAR: archivo presente
            if (empty($files['ticket']) || $files['ticket']['error'] === UPLOAD_ERR_NO_FILE) {
                throw new InvalidArgumentException('No se subió ningún archivo.');
            }

            // ✅ VALIDAR: errores de upload
            if ($files['ticket']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException($this->getUploadErrorMessage($files['ticket']['error']));
            }

            // ✅ VALIDAR: tamaño
            $tamañoMB = $files['ticket']['size'] / (1024 * 1024);
            if ($tamañoMB > self::TAMAÑO_MAXIMO_MB) {
                throw new InvalidArgumentException(
                    "Archivo demasiado grande. Máximo: " . self::TAMAÑO_MAXIMO_MB . " MB"
                );
            }

            $archivo = $files['ticket'];
            $nombreOriginal = $archivo['name'];
            $rutaTemporal = $archivo['tmp_name'];

            // ✅ VALIDAR: extensión de archivo
            $info = pathinfo($nombreOriginal);
            $extensión = strtolower($info['extension'] ?? '');

            if (!in_array($extensión, self::EXTENSIONES_PERMITIDAS, true)) {
                throw new InvalidArgumentException(
                    "Extensión no permitida: $extensión. Permitidas: " . 
                    implode(', ', self::EXTENSIONES_PERMITIDAS)
                );
            }

            // ✅ VALIDAR: MIME type real del archivo
            $mimeType = $this->detectarMimeType($rutaTemporal);
            if (!in_array($mimeType, self::MIME_TYPES_PERMITIDOS, true)) {
                throw new InvalidArgumentException(
                    "Tipo MIME no permitido: $mimeType"
                );
            }

            // ✅ GENERAR nombre seguro: UUID + extensión original
            $nombreSeguro = bin2hex(random_bytes(16)) . '.' . $extensión;
            $rutaDestino = $this->storageDir . DIRECTORY_SEPARATOR . $nombreSeguro;

            // ✅ VALIDAR path traversal: asegurarse que destino está dentro de storageDir
            $rutaDestinoReal = realpath(dirname($rutaDestino));
            $storageDirReal = realpath($this->storageDir);

            if ($rutaDestinoReal === false || strpos($rutaDestinoReal, $storageDirReal) !== 0) {
                throw new RuntimeException('Path traversal detected. Ruta destino no permitida.');
            }

            // ✅ MOVER archivo
            if (!move_uploaded_file($rutaTemporal, $rutaDestino)) {
                throw new RuntimeException('Error al guardar el archivo en el servidor.');
            }

            // ✅ PERMISOS seguros: 0640 (lectura/escritura propietario, solo lectura grupo)
            chmod($rutaDestino, 0640);

            // ✅ INSERTAR en BD: guardar SOLO el nombre seguro, no la ruta completa
            $ticketId = $this->guardarTicketEnBd($nombreSeguro, $nombreOriginal);

            // ✅ EJECUTAR OCR en background (opcional)
            $this->ocr->procesarAsync($ticketId, $rutaDestino);

            return [
                'ok' => true,
                'ticket_id' => $ticketId,
                'errores' => [],
            ];

        } catch (InvalidArgumentException | RuntimeException $e) {
            return [
                'ok' => false,
                'ticket_id' => null,
                'errores' => [$e->getMessage()],
            ];
        } catch (Exception $e) {
            error_log('[TicketController::subirTicket] Error inesperado: ' . $e->getMessage());
            return [
                'ok' => false,
                'ticket_id' => null,
                'errores' => ['Error interno al procesar el archivo.'],
            ];
        }
    }

    /**
     * Descarga un ticket (solo el propietario).
     * 
     * @param int $ticketId
     * @param int $usuarioId  Usuario que solicita descargar
     * @return void
     */
    public function descargarTicket(int $ticketId, int $usuarioId): void
    {
        try {
            // ✅ Verificar propiedad: solo el propietario puede descargar
            $stmt = $this->pdo->prepare(
                "SELECT archivo FROM tickets_compra 
                 WHERE id = ? AND usuario_id = ?"
            );
            $stmt->execute([$ticketId, $usuarioId]);
            $ticket = $stmt->fetch();

            if (!$ticket) {
                http_response_code(403);
                die('Acceso denegado.');
            }

            $nombreSeguro = $ticket['archivo'];

            // ✅ VALIDAR: nombre es simple (sin slashes, etc)
            if (!preg_match('/^[a-f0-9]{32}\.[a-z]{3,4}$/', $nombreSeguro)) {
                http_response_code(400);
                die('Nombre de archivo inválido.');
            }

            $rutaCompleta = $this->storageDir . DIRECTORY_SEPARATOR . $nombreSeguro;

            // ✅ VALIDAR path traversal
            $rutaCompleta = realpath($rutaCompleta);
            $storageDirReal = realpath($this->storageDir);

            if ($rutaCompleta === false || strpos($rutaCompleta, $storageDirReal) !== 0) {
                http_response_code(403);
                die('Acceso denegado.');
            }

            // ✅ Verificar existencia
            if (!file_exists($rutaCompleta) || !is_file($rutaCompleta)) {
                http_response_code(404);
                die('Archivo no encontrado.');
            }

            // ✅ Headers seguros para descarga
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($rutaCompleta) . '"');
            header('Content-Length: ' . filesize($rutaCompleta));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');

            // ✅ Enviar archivo
            readfile($rutaCompleta);
            exit;

        } catch (Exception $e) {
            error_log('[TicketController::descargarTicket] ' . $e->getMessage());
            http_response_code(500);
            die('Error al descargar archivo.');
        }
    }

    /**
     * Detecta el MIME type real del archivo usando fileinfo.
     * 
     * @param string $rutaArchivo
     * @return string
     */
    private function detectarMimeType(string $rutaArchivo): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $rutaArchivo);
        finfo_close($finfo);
        return $mimeType ?: 'application/octet-stream';
    }

    /**
     * Obtiene mensaje de error de upload.
     * 
     * @param int $code
     * @return string
     */
    private function getUploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'El archivo excede el tamaño máximo del servidor.',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo excede el tamaño máximo del formulario.',
            UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente.',
            UPLOAD_ERR_NO_FILE    => 'No se subió ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal del servidor.',
            UPLOAD_ERR_CANT_WRITE => 'No se puede escribir en el disco.',
            UPLOAD_ERR_EXTENSION  => 'Una extensión PHP detuvo la subida del archivo.',
            default               => 'Error desconocido en la subida.',
        };
    }

    /**
     * Guarda el ticket en BD (solo nombre seguro).
     * 
     * @param string $nombreSeguro Nombre generado (UUID.ext)
     * @param string $nombreOriginal Nombre original del archivo
     * @return int ID del ticket
     */
    private function guardarTicketEnBd(string $nombreSeguro, string $nombreOriginal): int
    {
        // Sanitizar nombre original para display
        $nombreOriginalSeguro = htmlspecialchars($nombreOriginal, ENT_QUOTES, 'UTF-8');
        $nombreOriginalSeguro = mb_substr($nombreOriginalSeguro, 0, 255, 'UTF-8');

        $stmt = $this->pdo->prepare(
            "INSERT INTO tickets_compra (archivo, nombre_original, fecha_subida) 
             VALUES (?, ?, NOW())"
        );
        $stmt->execute([$nombreSeguro, $nombreOriginalSeguro]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Elimina un ticket (solo propietario o admin).
     * 
     * @param int $ticketId
     * @param int $usuarioId
     * @return bool
     */
    public function eliminarTicket(int $ticketId, int $usuarioId): bool
    {
        try {
            // ✅ Verificar propiedad
            $stmt = $this->pdo->prepare(
                "SELECT archivo FROM tickets_compra 
                 WHERE id = ? AND usuario_id = ?"
            );
            $stmt->execute([$ticketId, $usuarioId]);
            $ticket = $stmt->fetch();

            if (!$ticket) {
                throw new RuntimeException('Ticket no encontrado o sin acceso.');
            }

            $rutaArchivo = $this->storageDir . DIRECTORY_SEPARATOR . $ticket['archivo'];

            // ✅ Eliminar archivo físico
            if (file_exists($rutaArchivo)) {
                unlink($rutaArchivo);
            }

            // ✅ Eliminar de BD
            $stmt = $this->pdo->prepare("DELETE FROM tickets_compra WHERE id = ?");
            $stmt->execute([$ticketId]);

            return true;
        } catch (Exception $e) {
            error_log('[TicketController::eliminarTicket] ' . $e->getMessage());
            return false;
        }
    }
}