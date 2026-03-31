<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * DashboardService
 * 
 * Centraliza la obtención de métricas y datos para el panel principal.
 */
class DashboardService
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    /**
     * Obtiene los KPIs principales (Tesorería, Gastos, Ingresos, Beneficio).
     *
     * @return array{total_bancos: float, total_gastos: float, total_ingresos: float, beneficio: float}
     */
    public function getKpis(): array
    {
        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN cc.codigo_pgc LIKE '57%' THEN ld.debe  - ld.haber ELSE 0 END), 0) AS total_bancos,
                    COALESCE(SUM(CASE WHEN cc.codigo_pgc LIKE '6%'  THEN ld.debe  - ld.haber ELSE 0 END), 0) AS total_gastos,
                    COALESCE(SUM(CASE WHEN cc.codigo_pgc LIKE '7%'  THEN ld.haber - ld.debe  ELSE 0 END), 0) AS total_ingresos
                FROM libro_diario ld
                JOIN cuentas_contables cc ON ld.cuenta_id = cc.id";
        
        $data = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

        $totalBancos   = (float)$data['total_bancos'];
        $totalGastos   = (float)$data['total_gastos'];
        $totalIngresos = (float)$data['total_ingresos'];

        return [
            'total_bancos'   => $totalBancos,
            'total_gastos'   => $totalGastos,
            'total_ingresos' => $totalIngresos,
            'beneficio'      => $totalIngresos - $totalGastos
        ];
    }

    /**
     * Obtiene datos para el gráfico mensual de los últimos 6 meses.
     *
     * @return array[]
     */
    public function getChartData(): array
    {
        $sql = "SELECT DATE_FORMAT(ld.fecha, '%Y-%m') as mes,
                       COALESCE(SUM(CASE WHEN cc.codigo_pgc LIKE '7%' THEN ld.haber - ld.debe ELSE 0 END), 0) as ingresos,
                       COALESCE(SUM(CASE WHEN cc.codigo_pgc LIKE '6%' THEN ld.debe - ld.haber ELSE 0 END), 0) as gastos
                FROM libro_diario ld 
                JOIN cuentas_contables cc ON ld.cuenta_id = cc.id
                WHERE ld.fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY mes 
                ORDER BY mes ASC";
        
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene los últimos movimientos registrados.
     *
     * @param  int $limite
     * @return array[]
     */
    public function getUltimosMovimientos(int $limite = 5): array
    {
        $sql = "SELECT fecha, concepto, debe, haber 
                FROM libro_diario 
                ORDER BY id DESC 
                LIMIT " . (int)$limite;
        
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
