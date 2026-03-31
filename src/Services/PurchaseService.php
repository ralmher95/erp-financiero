<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * PurchaseService
 * 
 * Orquesta el registro de tickets de compra (gastos), incluyendo
 * la gestión del archivo físico y el asiento contable.
 */
class PurchaseService
{
    private readonly AccountingService $accounting;

    public function __construct(
        private readonly PDO $pdo
    ) {
        $this->accounting = new AccountingService($pdo);
    }

    /**
     * Registra un ticket de compra con su asiento asociado.
     * 
     * @param array  $datos     {proveedor_id, fecha, base_imponible, cuota_iva, total, concepto}
     * @param string $rutaArchivo Ruta física del archivo guardado
     * @return int ID del ticket creado
     * @throws RuntimeException
     */
    public function registrarTicket(array $datos, string $rutaArchivo): int
    {
        $this->validarDatos($datos);

        try {
            $this->pdo->beginTransaction();

            // 1. Insertar ticket
            $stmt = $this->pdo->prepare(
                "INSERT INTO tickets_compra 
                    (proveedor_id, fecha, concepto, base_imponible, cuota_iva, total, archivo)
                 VALUES 
                    (:prov, :fecha, :concepto, :base, :iva, :total, :archivo)"
            );
            
            $stmt->execute([
                ':prov'     => (int)$datos['proveedor_id'],
                ':fecha'    => $datos['fecha'],
                ':concepto' => trim($datos['concepto']),
                ':base'     => $datos['base_imponible'],
                ':iva'      => $datos['cuota_iva'],
                ':total'    => $datos['total'],
                ':archivo'  => $rutaArchivo,
            ]);

            $ticketId = (int)$this->pdo->lastInsertId();

            // 2. Generar asiento contable
            // (600) Compras — DEBE por base
            // (472) IVA soportado — DEBE por IVA
            // (400) Proveedores — HABER por total
            $lineasContables = [
                [
                    'cuenta_id' => $this->obtenerCuentaId('60'),
                    'debe'      => (string)$datos['base_imponible'],
                    'haber'     => '0.00',
                ],
                [
                    'cuenta_id' => $this->obtenerCuentaId('472'),
                    'debe'      => (string)$datos['cuota_iva'],
                    'haber'     => '0.00',
                ],
                [
                    'cuenta_id' => $this->obtenerCuentaId('400'),
                    'debe'      => '0.00',
                    'haber'     => (string)$datos['total'],
                ],
            ];

            $numAsiento = $this->accounting->registrarAsiento(
                $datos['fecha'],
                "Gasto: " . trim($datos['concepto']),
                $lineasContables
            );

            // 3. Vincular asiento
            $this->pdo->prepare("UPDATE tickets_compra SET numero_asiento = ? WHERE id = ?")
                      ->execute([$numAsiento, $ticketId]);

            $this->pdo->commit();

            if (function_exists('log_info')) {
                log_info('PurchaseService', "Ticket #{$ticketId} registrado con asiento #{$numAsiento}.");
            }

            return $ticketId;

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[PurchaseService::registrarTicket] ' . $e->getMessage());
            throw new RuntimeException('Error al registrar el ticket de compra: ' . $e->getMessage());
        }
    }

    /**
     * Elimina un ticket y su asiento asociado.
     */
    public function eliminarTicket(int $id): bool
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("SELECT numero_asiento, archivo FROM tickets_compra WHERE id = ?");
            $stmt->execute([$id]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ticket) {
                $this->pdo->rollBack();
                return false;
            }

            if (!empty($ticket['numero_asiento'])) {
                $this->accounting->eliminarAsiento((int)$ticket['numero_asiento']);
            }

            $this->pdo->prepare("DELETE FROM tickets_compra WHERE id = ?")->execute([$id]);

            $this->pdo->commit();

            if (!empty($ticket['archivo']) && file_exists($ticket['archivo'])) {
                @unlink($ticket['archivo']);
            }

            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[PurchaseService::eliminarTicket] ' . $e->getMessage());
            return false;
        }
    }

    private function validarDatos(array $datos): void
    {
        $requeridos = ['proveedor_id', 'fecha', 'base_imponible', 'cuota_iva', 'total', 'concepto'];
        foreach ($requeridos as $campo) {
            if (!isset($datos[$campo]) || (string)$datos[$campo] === '') {
                throw new InvalidArgumentException("Campo obligatorio faltante: '$campo'");
            }
        }
    }

    private function obtenerCuentaId(string $codigoPgc): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM cuentas_contables WHERE codigo_pgc LIKE ? ORDER BY codigo_pgc ASC LIMIT 1");
        $stmt->execute([$codigoPgc . '%']);
        $id = $stmt->fetchColumn();

        if (!$id) {
            throw new RuntimeException("Cuenta PGC no encontrada: '$codigoPgc'");
        }

        return (int)$id;
    }
}
