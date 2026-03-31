<?php
// =============================================================================
// src/Services/AccountingService.php — Servicio de Integridad Contable (PGC Español)
// v2.0 — CORRECCIONES CRÍTICAS:
//   ✅ BUG CRÍTICO: "FOR UPDATE" fuera de transacción no bloquea nada →
//      Ahora registrarAsiento() abre su propia transacción interna si no hay una
//      activa, o participa en la transacción del llamador (patrón savepoint).
//   ✅ BCMath para cuadratura de decimales (evita errores de float IEEE 754)
//   ✅ Validación de lineas[] no vacío antes de operar
//   ✅ log_erp() integrado para trazabilidad de asientos
//   ✅ Método público verificarCuadre() reutilizable desde otros servicios
// =============================================================================

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use RuntimeException;
use InvalidArgumentException;

/**
 * AccountingService — Garantiza la partida doble y la atomicidad de asientos.
 *
 * PRINCIPIO DE USO:
 *   - Si el llamador ya abrió una transacción → este servicio participa en ella
 *     (no llama beginTransaction() de nuevo; si falla, el llamador hace rollBack()).
 *   - Si el llamador no abrió transacción → este servicio abre y cierra la suya.
 *
 * Esto permite dos patrones de uso:
 *   A) Solo contabilidad:  $accounting->registrarAsiento(...);
 *   B) Como parte de una transacción mayor (ej: InvoiceController):
 *        $pdo->beginTransaction();
 *        // ... INSERT en facturas ...
 *        $accounting->registrarAsiento(...);  // participa en la TX existente
 *        $pdo->commit();
 */
class AccountingService
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    // =========================================================================
    // REGISTRO DE ASIENTO
    // =========================================================================

    /**
     * Registra un asiento completo en el Libro Diario de forma segura.
     *
     * @param string $fecha    Fecha del asiento (Y-m-d)
     * @param string $concepto Descripción del asiento
     * @param array  $lineas   Estructura:
     *                         [['cuenta_id' => int, 'debe' => string, 'haber' => string], ...]
     *                         Los importes deben ser strings para compatibilidad con BCMath.
     *
     * @return int  Número de asiento asignado
     *
     * @throws InvalidArgumentException Si las líneas están vacías o mal formadas
     * @throws RuntimeException         Si hay descuadre de partida doble o error de BD
     */
    public function registrarAsiento(string $fecha, string $concepto, array $lineas): int
    {
        // ── Guardia: al menos una línea ───────────────────────────────────────
        if (empty($lineas)) {
            throw new InvalidArgumentException('Un asiento debe tener al menos una línea contable.');
        }

        // ── Validar formato de cada línea ─────────────────────────────────────
        foreach ($lineas as $i => $l) {
            if (!isset($l['cuenta_id'], $l['debe'], $l['haber'])) {
                throw new InvalidArgumentException(
                    "Línea [$i] malformada: se requieren 'cuenta_id', 'debe', 'haber'."
                );
            }
        }

        // ── Verificación de partida doble con BCMath ──────────────────────────
        // BCMath opera sobre strings y evita errores de precisión float (IEEE 754).
        [$debeTotal, $haberTotal] = $this->sumarLineas($lineas);

        if (bccomp($debeTotal, $haberTotal, 2) !== 0) {
            throw new RuntimeException(
                "Violación de Partida Doble: Debe ({$debeTotal}) ≠ Haber ({$haberTotal}). " .
                "El asiento no puede registrarse."
            );
        }

        // ── Determinar si ya hay una transacción activa del llamador ──────────
        $txPropia = !$this->pdo->inTransaction();

        try {
            if ($txPropia) {
                $this->pdo->beginTransaction();
            }

            // ── Número de asiento con bloqueo de fila (FOR UPDATE) ────────────
            // FOR UPDATE solo funciona dentro de una transacción InnoDB activa.
            // Serializa las escrituras concurrentes evitando números duplicados.
            $stmtNum = $this->pdo->query(
                "SELECT COALESCE(MAX(numero_asiento), 0) FROM libro_diario FOR UPDATE"
            );
            $numAsiento = (int)$stmtNum->fetchColumn() + 1;

            // ── INSERT de líneas ──────────────────────────────────────────────
            $stmt = $this->pdo->prepare(
                "INSERT INTO libro_diario (fecha, numero_asiento, concepto, cuenta_id, debe, haber)
                 VALUES (:fecha, :numero, :concepto, :cuenta_id, :debe, :haber)"
            );

            foreach ($lineas as $l) {
                $stmt->execute([
                    ':fecha'     => $fecha,
                    ':numero'    => $numAsiento,
                    ':concepto'  => $concepto,
                    ':cuenta_id' => (int)$l['cuenta_id'],
                    ':debe'      => $l['debe'],
                    ':haber'     => $l['haber'],
                ]);
            }

            if ($txPropia) {
                $this->pdo->commit();
            }

            // Log de trazabilidad (si logger.php está disponible)
            if (function_exists('log_info')) {
                log_info('AccountingService', "Asiento #{$numAsiento} registrado.", [
                    'fecha'    => $fecha,
                    'concepto' => $concepto,
                    'debe'     => $debeTotal,
                    'haber'    => $haberTotal,
                    'lineas'   => count($lineas),
                ]);
            }

            return $numAsiento;

        } catch (PDOException $e) {
            // Solo hacemos rollBack si abrimos la TX nosotros
            if ($txPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Propagar como RuntimeException para que el llamador pueda capturar
            throw new RuntimeException(
                "Error al persistir el asiento en Libro Diario: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    // =========================================================================
    // CONSULTAS Y LISTADOS
    // =========================================================================

    /**
     * Devuelve todos los asientos del libro diario agrupados por número de asiento.
     *
     * @param  int    $limite  Máximo de asientos a devolver (0 = todos).
     * @param  string $desde   Fecha mínima (Y-m-d).
     * @param  string $hasta   Fecha máxima (Y-m-d).
     * @return array[]
     */
    public function listarAsientos(int $limite = 0, string $desde = '', string $hasta = ''): array
    {
        $where  = [];
        $params = [];

        if ($desde !== '') {
            $where[]          = 'ld.fecha >= :desde';
            $params[':desde'] = $desde;
        }
        if ($hasta !== '') {
            $where[]          = 'ld.fecha <= :hasta';
            $params[':hasta'] = $hasta;
        }

        $clausulaWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $clausulaLimit = $limite > 0 ? 'LIMIT ' . (int)$limite : '';

        $sql = "SELECT
                    ld.numero_asiento,
                    ld.fecha,
                    ld.concepto,
                    ld.cuenta_id,
                    cc.codigo_pgc,
                    cc.descripcion AS cuenta_nombre,
                    ld.debe,
                    ld.haber
                FROM libro_diario ld
                JOIN cuentas_contables cc ON ld.cuenta_id = cc.id
                $clausulaWhere
                ORDER BY ld.fecha DESC, ld.numero_asiento DESC, ld.id ASC
                $clausulaLimit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->agruparPorAsiento($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Elimina un asiento completo por su número.
     *
     * @param  int $numeroAsiento
     * @return bool
     */
    public function eliminarAsiento(int $numeroAsiento): bool
    {
        if ($numeroAsiento <= 0) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("DELETE FROM libro_diario WHERE numero_asiento = ?");
            $stmt->execute([$numeroAsiento]);
            
            $eliminados = $stmt->rowCount() > 0;

            if ($eliminados && function_exists('log_info')) {
                log_info('AccountingService', "Asiento #{$numeroAsiento} eliminado.");
            }

            return $eliminados;
        } catch (PDOException $e) {
            error_log('[AccountingService::eliminarAsiento] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Devuelve el catálogo de cuentas contables.
     *
     * @return array[]
     */
    public function getCuentasContables(): array
    {
        return $this->pdo
            ->query("SELECT id, codigo_pgc, descripcion FROM cuentas_contables ORDER BY codigo_pgc ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // MÉTODOS DE APOYO PÚBLICOS
    // =========================================================================

    /**
     * Verifica que un conjunto de líneas cuadra sin persistirlas.
     * Útil para validación en tiempo real desde el controlador.
     *
     * @param  array $lineas  Misma estructura que registrarAsiento()
     * @return bool  true si Debe == Haber (precisión de 2 decimales)
     */
    public function verificarCuadre(array $lineas): bool
    {
        if (empty($lineas)) {
            return false;
        }

        [$debe, $haber] = $this->sumarLineas($lineas);
        return bccomp($debe, $haber, 2) === 0;
    }

    /**
     * Devuelve el último número de asiento registrado.
     * Útil para mostrar en UI antes de crear un asiento.
     */
    public function ultimoNumeroAsiento(): int
    {
        return (int)$this->pdo
            ->query("SELECT COALESCE(MAX(numero_asiento), 0) FROM libro_diario")
            ->fetchColumn();
    }

    // =========================================================================
    // MÉTODOS PRIVADOS
    // =========================================================================

    /**
     * Suma los totales de Debe y Haber de un array de líneas usando BCMath.
     *
     * @param  array   $lineas
     * @return array{0: string, 1: string}  [$debeTotal, $haberTotal] con 2 decimales
     */
    private function sumarLineas(array $lineas): array
    {
        $debe  = '0.00';
        $haber = '0.00';

        foreach ($lineas as $l) {
            // Convertir a string limpio: reemplazar coma por punto (formato ES)
            $debeLinea  = str_replace(',', '.', (string)($l['debe']  ?? '0'));
            $haberLinea = str_replace(',', '.', (string)($l['haber'] ?? '0'));

            // bcadd requiere strings válidos; validamos que sean numéricos
            if (!is_numeric($debeLinea) || !is_numeric($haberLinea)) {
                throw new InvalidArgumentException(
                    "Importe no numérico en línea: debe='{$l['debe']}', haber='{$l['haber']}'"
                );
            }

            $debe  = bcadd($debe,  $debeLinea,  2);
            $haber = bcadd($haber, $haberLinea, 2);
        }

        return [$debe, $haber];
    }

    /**
     * Agrupa las filas del Libro Diario por número de asiento.
     *
     * @param  array[] $filas  Resultado plano de la query.
     * @return array[]         Asientos con sus líneas anidadas.
     */
    private function agruparPorAsiento(array $filas): array
    {
        $asientos = [];

        foreach ($filas as $fila) {
            $num = $fila['numero_asiento'];

            if (!isset($asientos[$num])) {
                $asientos[$num] = [
                    'numero_asiento' => $num,
                    'fecha'          => $fila['fecha'],
                    'concepto'       => $fila['concepto'],
                    'total_debe'     => '0.00',
                    'total_haber'    => '0.00',
                    'lineas'         => [],
                ];
            }

            $asientos[$num]['lineas'][] = $fila;
            
            // Usar BCMath para el sumatorio en el listado
            $asientos[$num]['total_debe']  = bcadd($asientos[$num]['total_debe'],  (string)$fila['debe'],  2);
            $asientos[$num]['total_haber'] = bcadd($asientos[$num]['total_haber'], (string)$fila['haber'], 2);
        }

        return array_values($asientos);
    }
}