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
                    (cliente_id, numero_serie, numero_factura, fecha_emision, base_imponible, cuota_iva, total)
                 VALUES
                    (:cliente_id, :serie, :numero, :fecha, :base, :iva, :total)"
            );
            $stmt->execute([
                ':cliente_id' => (int)$datosFactura['cliente_id'],
                ':serie'      => $serie,
                ':numero'     => $numero,
                ':fecha'      => $datosFactura['fecha'],
                ':base'       => $datosFactura['base_imponible'],
                ':iva'        => $datosFactura['iva_total'],
                ':total'      => $datosFactura['total'],
            ]);

            $facturaId = (int)$this->db->lastInsertId();

            // ── 3. Insertar líneas de factura ─────────────────────────────────
            $stmtLinea = $this->db->prepare(
                "INSERT INTO lineas_factura
                    (factura_id, descripcion, cantidad, precio_unitario, tipo_iva, subtotal, cuota_iva, total)
                 VALUES
                    (:f_id, :desc, :cant, :precio, :iva_pct, :sub, :iva_val, :total_linea)"
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
                    ':sub'         => $subtotal,
                    ':iva_val'     => $cuotaIva,
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
            $this->db->prepare("UPDATE facturas SET numero_asiento = ? WHERE id = ?")
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

            if (function_exists('log_error')) {
                log_error('FacturacionService', 'Rollback en crearFactura: ' . $e->getMessage(), [
                    'numero' => $datosFactura['numero'] ?? 'N/A',
                ]);
            } else {
                error_log('[FacturacionService] Error en crearFactura: ' . $e->getMessage());
            }

            // Re-lanzar para que el controlador decida qué mostrar al usuario
            throw new RuntimeException(
                'No se pudo crear la factura. La operación fue revertida.',
                0,
                $e
            );
        }
    }

    // =========================================================================
    // MÉTODOS PRIVADOS
    // =========================================================================

    /**
     * Construye las líneas contables estándar para una factura de venta.
     * Plan General Contable español:
     *   (430) Clientes — DEBE por el total
     *   (700) Ventas de mercaderías — HABER por la base imponible
     *   (477) IVA repercutido — HABER por el IVA
     *
     * @throws RuntimeException Si alguna cuenta PGC no existe en la BD
     */
    private function construirLineasContables(array $datos): array
    {
        return [
            [
                'cuenta_id' => $this->obtenerCuentaId('430'),
                'debe'      => (string)$datos['total'],
                'haber'     => '0.00',
            ],
            [
                'cuenta_id' => $this->obtenerCuentaId('700'),
                'debe'      => '0.00',
                'haber'     => (string)$datos['base_imponible'],
            ],
            [
                'cuenta_id' => $this->obtenerCuentaId('477'),
                'debe'      => '0.00',
                'haber'     => (string)$datos['iva_total'],
            ],
        ];
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

        // Validar que el total cuadre (base + IVA = total, tolerancia de 1 céntimo)
        $totalCalculado = (float)$datos['base_imponible'] + (float)$datos['iva_total'];
        if (abs($totalCalculado - (float)$datos['total']) > 0.01) {
            throw new InvalidArgumentException(sprintf(
                'El total (%.2f) no coincide con base_imponible + iva_total (%.2f).',
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