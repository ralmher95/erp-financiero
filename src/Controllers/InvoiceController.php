<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AccountingService;
use PDO;
use Exception;
use InvalidArgumentException;

class InvoiceController {
    private PDO $pdo;
    private AccountingService $accounting;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->accounting = new AccountingService($pdo);
    }

    /**
     * Orquesta la inserción de factura y contabilidad bajo una única transacción ACID.
     */
    public function procesarNuevaFactura(array $postData): array {
        $this->validarPayloadEstructural($postData);

        try {
            $this->pdo->beginTransaction();

            $serie = date('Y');
            
            // Resolución de Race Condition mediante row-level lock (FOR UPDATE)
            $stmtNumFac = $this->pdo->prepare("SELECT COALESCE(MAX(numero_factura), 0) FROM facturas WHERE numero_serie = ? FOR UPDATE");
            $stmtNumFac->execute([$serie]);
            $numFactura = (int)$stmtNumFac->fetchColumn() + 1;

            $stmtCabecera = $this->pdo->prepare("
                INSERT INTO facturas (cliente_id, numero_serie, numero_factura, fecha_emision, base_imponible, cuota_iva, total)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmtCabecera->execute([
                $postData['cliente_id'],
                $serie,
                $numFactura,
                $postData['fecha'],
                $postData['base_imponible'],
                $postData['cuota_iva'],
                $postData['total']
            ]);
            $facturaId = (int)$this->pdo->lastInsertId();

            $insLinea = $this->pdo->prepare("INSERT INTO factura_lineas (factura_id, descripcion, cantidad, precio, iva, total) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($postData['lineas'] as $linea) {
                $insLinea->execute([
                    $facturaId, 
                    $linea['desc'], 
                    $linea['cant'], 
                    $linea['precio'], 
                    $linea['iva'] ?? 21,
                    $linea['total'] ?? ($linea['cant'] * $linea['precio'] * 1.21)
                ]);
            }

            // Mapeo contable estructurado (Strings para garantizar precisión BCMath en AccountingService)
            $asiento = [
                ['cuenta_id' => $this->obtenerCuentaSegura('4300'), 'debe' => (string)$postData['total'], 'haber' => '0.00'],
                ['cuenta_id' => $this->obtenerCuentaSegura('7000'), 'debe' => '0.00', 'haber' => (string)$postData['base_imponible']],
                ['cuenta_id' => $this->obtenerCuentaSegura('4770'), 'debe' => '0.00', 'haber' => (string)$postData['cuota_iva']]
            ];

            $numAsiento = $this->accounting->registrarAsiento($postData['fecha'], "Fra. Emitida $serie/$numFactura", $asiento);

            $this->pdo->prepare("UPDATE facturas SET numero_asiento = ? WHERE id = ?")
                      ->execute([$numAsiento, $facturaId]);

            $this->pdo->commit();
            return ['status' => 'success', 'id' => $facturaId];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log('[InvoiceController] Aborto de Transacción: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Integridad comprometida: ' . $e->getMessage()];
        }
    }

    /**
     * Defensa contra payload sucio o malicioso.
     */
    private function validarPayloadEstructural(array $data): void {
        $requeridos = ['cliente_id', 'fecha', 'base_imponible', 'cuota_iva', 'total', 'lineas'];
        foreach ($requeridos as $campo) {
            if (!isset($data[$campo])) {
                throw new InvalidArgumentException("Defecto de contrato: Falta el campo [$campo]");
            }
        }
    }

    /**
     * Mapeo estricto de cuentas PGC.
     */
    private function obtenerCuentaSegura(string $codigo): int {
        $stmt = $this->pdo->prepare("SELECT id FROM cuentas_contables WHERE codigo_pgc = ? LIMIT 1");
        $stmt->execute([$codigo]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new InvalidArgumentException("Cuenta PGC crítica no encontrada en el sistema: $codigo");
        }
        return (int)$id;
    }
}