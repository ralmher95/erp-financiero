<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AccountingService;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * AsientoController
 *
 * Gestiona el ciclo de vida completo de los asientos contables
 * delegando la lógica de integridad y persistencia a AccountingService.
 */
class AsientoController
{
    /**
     * @param AccountingService $accounting Inyección del servicio contable.
     */
    public function __construct(
        private readonly AccountingService $accounting
    ) {}

    /* ============================================================
       CREAR ASIENTO
       ============================================================ */

    /**
     * Procesa el formulario de nuevo asiento.
     *
     * @param  array $post Contenido de $_POST
     * @return array{ok: bool, errores: string[], asiento_id: int|null}
     */
    public function crear(array $post): array
    {
        $errores = $this->validarPost($post);
        if (!empty($errores)) {
            return ['ok' => false, 'errores' => $errores, 'asiento_id' => null];
        }

        try {
            // Combinar líneas Debe y Haber para el servicio
            $lineas = array_merge(
                $this->normalizarLineas($post['debe_cuenta_id'] ?? [], $post['debe_importe'] ?? [], true),
                $this->normalizarLineas($post['haber_cuenta_id'] ?? [], $post['haber_importe'] ?? [], false)
            );

            $asientoId = $this->accounting->registrarAsiento(
                $post['fecha'],
                trim($post['concepto']),
                $lineas
            );

            return ['ok' => true, 'errores' => [], 'asiento_id' => $asientoId];

        } catch (InvalidArgumentException | RuntimeException $e) {
            return [
                'ok'         => false,
                'errores'    => [$e->getMessage()],
                'asiento_id' => null,
            ];
        } catch (Exception $e) {
            error_log('[AsientoController::crear] Error inesperado: ' . $e->getMessage());
            return [
                'ok'         => false,
                'errores'    => ['Error interno al procesar el asiento.'],
                'asiento_id' => null,
            ];
        }
    }

    /* ============================================================
       LISTAR ASIENTOS
       ============================================================ */

    /**
     * Lista los asientos delegando en el servicio.
     */
    public function listar(int $limite = 0, string $desde = '', string $hasta = ''): array
    {
        return $this->accounting->listarAsientos($limite, $desde, $hasta);
    }

    /* ============================================================
       ELIMINAR ASIENTO
       ============================================================ */

    /**
     * Elimina un asiento delegando en el servicio.
     */
    public function eliminar(int $numeroAsiento): bool
    {
        return $this->accounting->eliminarAsiento($numeroAsiento);
    }

    /* ============================================================
       CONSULTAS DE APOYO
       ============================================================ */

    /**
     * Devuelve las cuentas contables.
     */
    public function getCuentas(): array
    {
        return $this->accounting->getCuentasContables();
    }

    /* ============================================================
       MÉTODOS PRIVADOS
       ============================================================ */

    /**
     * Valida los datos mínimos del POST.
     */
    private function validarPost(array $post): array
    {
        $errores = [];

        if (empty($post['fecha'])) {
            $errores[] = 'La fecha del asiento es obligatoria.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $post['fecha'])) {
            $errores[] = 'El formato de fecha no es válido (use AAAA-MM-DD).';
        }

        if (empty(trim($post['concepto'] ?? ''))) {
            $errores[] = 'El concepto del asiento es obligatorio.';
        }

        return $errores;
    }

    /**
     * Normaliza las líneas del formulario para el formato del servicio.
     */
    private function normalizarLineas(array $cuentaIds, array $importes, bool $esDebe): array
    {
        $lineas = [];
        $total  = min(count($cuentaIds), count($importes));

        for ($i = 0; $i < $total; $i++) {
            $cuentaId = (int)$cuentaIds[$i];
            $importe  = str_replace(',', '.', (string)$importes[$i]);

            if ($cuentaId > 0 && is_numeric($importe) && (float)$importe > 0) {
                $lineas[] = [
                    'cuenta_id' => $cuentaId,
                    'debe'      => $esDebe ? $importe : '0.00',
                    'haber'     => $esDebe ? '0.00' : $importe,
                ];
            }
        }

        return $lineas;
    }
}
