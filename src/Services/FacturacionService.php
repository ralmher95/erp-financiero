<?php
// =============================================================================
// src/Services/FacturacionService.php — Servicio de Facturación
// v2.0 — CORRECCIONES CRÍTICAS:
//   ✅ BUG: registrarAsientoContable() usaba columna 'cuenta' (no existe),
//      ahora usa 'cuenta_id' con lookup por codigo_pgc → int
//   ✅ BUG: no declaraba strict_types ni namespace
//   ✅ Delegado a AccountingService (DRY — no duplicar lógica contable)
//   ✅ Transacción ACID consolidada: factura + líneas + asiento en un solo commit
//   ✅ Validación de campos antes de persistir
//   ✅ log_erp() para trazabilidad
// =============================================================================

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use Exception;
use InvalidArgumentException;
use RuntimeException;

class FacturacionService
{
    private readonly AccountingService $accounting;

    public function __construct(
        private readonly PDO $db
    ) {
        // Inyectamos AccountingService usando el mismo PDO (misma conexión = misma TX)
        $this->accounting = new AccountingService($db);
    }

    // =========================================================================
    // CREAR FACTURA (operación principal)
    // =========================================================================

    /**
     * Crea una factura completa con sus líneas y el asiento contable asociado.
     * Opera bajo una única transacción ACID: todo o nada.
     *
     * @param array $datosFactura {
     *   numero:          string  — Número de factura (ej: "2025/0042")
     *   fecha:           string  — Fecha en formato Y-m-d
     *   cliente_id:      int
     *   base_imponible:  float
     *   iva_total:       float
     *   total:           float
     * }
     * @param array $lineas [{
     *   concepto:         string
     *   cantidad:         float
     *   precio_unitario:  float
     * }, ...]
     *
     * @return int  ID de la factura creada
     *
     * @throws InvalidArgumentException Si los datos son incompletos
     * @throws RuntimeException         Si falla la persistencia
     */
    public function crearFactura(array $datosFactura, array $lineas): int
    {
        // ── Guardia de contrato ────────────────────────────────────────────────
        $this->validarDatosFactura($datosFactura);

        if (empty($lineas)) {
            throw new InvalidArgumentException('Una factura debe tener al menos una línea de detalle.');
        }

        try {
            $this->db->beginTransaction();

            // ── 1. Procesar número de factura (Serie / Número) ────────────────
            // Formato esperado: "F-2025-001" o similar. 
            // Si no tiene guiones, usamos el año actual como serie.
            $partesNum = explode('-', $datosFactura['numero']);
            $serie  = (count($partesNum) >= 2) ? $partesNum[1] : date('Y');
            $numero = (count($partesNum) >= 3) ? (int)$partesNum[2] : (int)preg_replace('/[^0-9]/', '', $datosFactura['numero']);

            // ── 2. Insertar cabecera de factura ───────────────────────────────
            $stmt = $this->db->prepare(
                "INSERT INTO facturas
                    (tipo, cliente_id, proveedor_id, numero_serie, numero_factura, fecha_emision, base_imponible, cuota_iva, total)
                 VALUES
                    (:tipo, :cliente_id, :proveedor_id, :serie, :numero, :fecha, :base, :iva, :total)"
            );
            $stmt->execute([
                ':tipo'         => $datosFactura['tipo'] ?? 'emitida',
                ':cliente_id'   => $datosFactura['cliente_id'] ?? null,
                ':proveedor_id' => $datosFactura['proveedor_id'] ?? null,
                ':serie'        => $serie,
                ':numero'       => $numero,
                ':fecha'        => $datosFactura['fecha'],
                ':base'         => $datosFactura['base_imponible'],
                ':iva'          => $datosFactura['iva_total'],
                ':total'        => $datosFactura['total'],
            ]);

            $facturaId = (int)$this->db->lastInsertId();

            // ── 3. Insertar líneas de factura ─────────────────────────────────
            $stmtLinea = $this->db->prepare(
                "INSERT INTO factura_lineas
                    (factura_id, descripcion, cantidad, precio, iva, total)
                 VALUES
                    (:f_id, :desc, :cant, :precio, :iva_pct, :total_linea)"
            );

            foreach ($lineas as $i => $linea) {
                $this->validarLinea($linea, $i);
                
                $cant     = (float)$linea['cantidad'];
                $precio   = (float)$linea['precio_unitario'];
                $ivaPct   = (float)($linea['iva'] ?? 21.00);
                
                $subtotal = round($cant * $precio, 2);
                $cuotaIva = round($subtotal * ($ivaPct / 100), 2);
                $totalL   = round($subtotal + $cuotaIva, 2);

                $stmtLinea->execute([
                    ':f_id'        => $facturaId,
                    ':desc'        => trim($linea['concepto']),
                    ':cant'        => $cant,
                    ':precio'      => $precio,
                    ':iva_pct'     => $ivaPct,
                    ':total_linea' => $totalL,
                ]);
            }

            // ── 4. Generar asiento contable (delegado a AccountingService) ────
            // AccountingService detecta que ya hay TX activa y NO llama beginTransaction()
            $lineasContables = $this->construirLineasContables($datosFactura);
            $numAsiento = $this->accounting->registrarAsiento(
                $datosFactura['fecha'],
                "Factura emitida nº " . $datosFactura['numero'],
                $lineasContables
            );

            // ── 4. Vincular el asiento a la factura ───────────────────────────
            $this->db->prepare("UPDATE facturas SET asiento_id = ? WHERE id = ?")
                     ->execute([$numAsiento, $facturaId]);

            // ── 5. Confirmar todo o nada ──────────────────────────────────────
            $this->db->commit();

            if (function_exists('log_info')) {
                log_info('FacturacionService', "Factura #{$facturaId} creada.", [
                    'numero'   => $datosFactura['numero'],
                    'total'    => $datosFactura['total'],
                    'asiento'  => $numAsiento,
                ]);
            }

            return $facturaId;

        } catch (Exception $e) {
            // Revertir absolutamente todo si algo falla
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            // Capturar la causa original para que el usuario la vea
            $errorDetalle = $e->getMessage();
            
            // Si es un error de integridad de base de datos (ej: número duplicado)
            if (str_contains($errorDetalle, 'Duplicate entry')) {
                $errorDetalle = "Ya existe una factura con el número '{$datosFactura['numero']}' para el año '{$serie}'. Prueba con otro.";
            }

            if (function_exists('log_error')) {
                log_error('FacturacionService', 'Rollback en crearFactura: ' . $e->getMessage(), [
                    'numero' => $datosFactura['numero'] ?? 'N/A',
                ]);
            } else {
                error_log('[FacturacionService] Error en crearFactura: ' . $e->getMessage());
            }

            // Re-lanzar con el detalle para que el usuario sepa qué corregir
            throw new RuntimeException(
                "No se pudo crear la factura: " . $errorDetalle,
                (int)$e->getCode(),
                $e
            );
        }
    }

    // =========================================================================
    // MÉTODOS PRIVADOS
    // =========================================================================

    /**
     * Construye las líneas contables estándar según el tipo de factura.
     */
    private function construirLineasContables(array $datos): array
    {
        $tipo = $datos['tipo'] ?? 'emitida';
        $lineasContables = [];

        if ($tipo === 'emitida') {
            // VENTA: (430) Cliente DEBE | (700) Ventas HABER | (477) IVA Repercutido HABER
            $lineasContables[] = [
                'cuenta_id' => $this->obtenerCuentaId('430'),
                'debe'      => (string)$datos['total'],
                'haber'     => '0.00',
            ];
            $lineasContables[] = [
                'cuenta_id' => $this->obtenerCuentaId('700'),
                'debe'      => '0.00',
                'haber'     => (string)$datos['base_imponible'],
            ];
            if ((float)$datos['iva_total'] > 0) {
                $lineasContables[] = [
                    'cuenta_id' => $this->obtenerCuentaId('477'),
                    'debe'      => '0.00',
                    'haber'     => (string)$datos['iva_total'],
                ];
            }
        } else {
            // COMPRA/GASTO: (600) Compras DEBE | (472) IVA Soportado DEBE | (400) Proveedor HABER
            $lineasContables[] = [
                'cuenta_id' => $this->obtenerCuentaId('600'), // Genérico compras
                'debe'      => (string)$datos['base_imponible'],
                'haber'     => '0.00',
            ];
            if ((float)$datos['iva_total'] > 0) {
                $lineasContables[] = [
                    'cuenta_id' => $this->obtenerCuentaId('472'),
                    'debe'      => (string)$datos['iva_total'],
                    'haber'     => '0.00',
                ];
            }
            $lineasContables[] = [
                'cuenta_id' => $this->obtenerCuentaId('400'),
                'debe'      => '0.00',
                'haber'     => (string)$datos['total'],
            ];
        }

        return $lineasContables;
    }

    /**
     * Busca el ID de una cuenta contable por su código PGC (o prefijo).
     * Busca coincidencia exacta primero; si no, busca por LIKE 'codigo%'.
     *
     * @throws RuntimeException Si la cuenta no existe
     */
    private function obtenerCuentaId(string $codigoPgc): int
    {
        // Búsqueda exacta primero
        $stmt = $this->db->prepare(
            "SELECT id FROM cuentas_contables WHERE codigo_pgc = ? LIMIT 1"
        );
        $stmt->execute([$codigoPgc]);
        $id = $stmt->fetchColumn();

        // Si no hay exacta, buscar por prefijo (ej: '430' → '4300', '430000')
        if (!$id) {
            $stmt = $this->db->prepare(
                "SELECT id FROM cuentas_contables WHERE codigo_pgc LIKE ? ORDER BY codigo_pgc ASC LIMIT 1"
            );
            $stmt->execute([$codigoPgc . '%']);
            $id = $stmt->fetchColumn();
        }

        if (!$id) {
            throw new RuntimeException(
                "Cuenta PGC no encontrada: '{$codigoPgc}'. " .
                "Verifica que el Plan de Cuentas esté importado (database/schema.sql)."
            );
        }

        return (int)$id;
    }

    /**
     * Valida los campos obligatorios de los datos de cabecera de factura.
     *
     * @throws InvalidArgumentException
     */
    private function validarDatosFactura(array $datos): void
    {
        $requeridos = ['numero', 'fecha', 'cliente_id', 'base_imponible', 'iva_total', 'total'];

        foreach ($requeridos as $campo) {
            if (!isset($datos[$campo]) || (string)$datos[$campo] === '') {
                throw new InvalidArgumentException("Campo obligatorio faltante en factura: '$campo'");
            }
        }

        // Validar que el total cuadre (base + IVA = total, tolerancia de 5 céntimos por redondeos OCR)
        $totalCalculado = (float)$datos['base_imponible'] + (float)$datos['iva_total'];
        if (abs($totalCalculado - (float)$datos['total']) > 0.05) {
            throw new InvalidArgumentException(sprintf(
                'El total (%.2f) no coincide con la suma de Base + IVA (%.2f). Por favor, revisa las líneas.',
                $datos['total'],
                $totalCalculado
            ));
        }
    }

    /**
     * Valida los campos de una línea de factura.
     *
     * @throws InvalidArgumentException
     */
    private function validarLinea(array $linea, int $indice): void
    {
        if (empty(trim($linea['concepto'] ?? ''))) {
            throw new InvalidArgumentException("Línea [$indice]: el concepto es obligatorio.");
        }
        if ((float)($linea['cantidad'] ?? 0) <= 0) {
            throw new InvalidArgumentException("Línea [$indice]: la cantidad debe ser mayor que 0.");
        }
        if ((float)($linea['precio_unitario'] ?? 0) <= 0) {
            throw new InvalidArgumentException("Línea [$indice]: el precio unitario debe ser mayor que 0.");
        }
    }
}