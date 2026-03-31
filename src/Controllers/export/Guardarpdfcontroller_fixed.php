<?php
// =============================================================================
// src/Controllers/export/guardar_pdf.php — Generación segura de reportes PDF
// FIX V1: SQL Injection en parámetro ORDER BY
// FIX V5: Template Injection en parámetros de Dompdf
// =============================================================================

declare(strict_types=1);

namespace App\Controllers\Export;

use Dompdf\Dompdf;
use InvalidArgumentException;
use PDO;
use Exception;

class GuardarPdfController
{
    private PDO $pdo;
    private Dompdf $dompdf;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->dompdf = new Dompdf([
            'isRemoteEnabled' => false,  // Evitar XXE
            'isPhpEnabled'    => false,  // Evitar code injection
        ]);
    }

    /**
     * Genera PDF del libro diario.
     * 
     * @param array $get GET parameters
     * @return void
     * @throws InvalidArgumentException
     */
    public function generarLibroDiario(array $get): void
    {
        try {
            // ✅ VALIDAR: orden (whitelist)
            $camposPermitidos = ['fecha', 'numero_asiento', 'concepto', 'importe'];
            $campoOrden = $get['orden'] ?? 'fecha';
            
            if (!in_array($campoOrden, $camposPermitidos, true)) {
                throw new InvalidArgumentException('Campo de orden no válido.');
            }

            // ✅ VALIDAR: rango de fechas
            $fechaDesde = $this->validarFecha($get['desde'] ?? '', '2020-01-01');
            $fechaHasta = $this->validarFecha($get['hasta'] ?? '', date('Y-m-d'));

            // ✅ VALIDAR: límite de registros
            $limite = (int)($get['limite'] ?? 1000);
            if ($limite < 1 || $limite > 10000) {
                $limite = 1000;
            }

            // ✅ PREPARED STATEMENT: evitar SQLi
            $sql = "
                SELECT 
                    numero_asiento,
                    fecha,
                    concepto,
                    debe,
                    haber
                FROM libro_diario
                WHERE fecha BETWEEN ? AND ?
                ORDER BY `" . $campoOrden . "` DESC
                LIMIT ?
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$fechaDesde, $fechaHasta, $limite]);
            $asientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ✅ ESCAPAR: título y otros parámetros user-input
            $titulo = $this->escaparHtml($get['titulo'] ?? 'Libro Diario');
            $titulo = mb_substr($titulo, 0, 100, 'UTF-8');

            // ✅ GENERAR HTML: seguro con htmlspecialchars
            $html = $this->generarHtmlLibroDiario($titulo, $asientos);

            // Generar PDF
            $this->dompdf->loadHtml($html, 'UTF-8');
            $this->dompdf->setPaper('A4', 'landscape');
            $this->dompdf->render();

            // ✅ HEADERS seguros
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="libro_diario_' . date('Y-m-d') . '.pdf"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');

            echo $this->dompdf->output();
            exit;

        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            header('Content-Type: text/html; charset=UTF-8');
            echo "Error de validación: " . htmlspecialchars($e->getMessage());
            exit;
        } catch (Exception $e) {
            error_log('[GuardarPdf::generarLibroDiario] ' . $e->getMessage());
            http_response_code(500);
            echo "Error al generar PDF.";
            exit;
        }
    }

    /**
     * Valida y sanitiza una fecha.
     * 
     * @param string $fecha Fecha en formato YYYY-MM-DD
     * @param string $default Valor por defecto
     * @return string Fecha validada
     */
    private function validarFecha(string $fecha, string $default): string
    {
        if (empty($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return $default;
        }

        [$año, $mes, $dia] = explode('-', $fecha);

        // ✅ Validar que sea una fecha real
        if (!checkdate((int)$mes, (int)$dia, (int)$año)) {
            return $default;
        }

        return $fecha;
    }

    /**
     * Escapa HTML para evitar XSS y template injection.
     * 
     * @param string $texto Texto a escapar
     * @return string
     */
    private function escaparHtml(string $texto): string
    {
        return htmlspecialchars(
            trim($texto),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    /**
     * Genera HTML seguro para el PDF.
     * 
     * @param string $titulo
     * @param array $asientos
     * @return string
     */
    private function generarHtmlLibroDiario(string $titulo, array $asientos): string
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { text-align: center; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f5f5f5; }
        .numero { text-align: right; }
        .dinero { text-align: right; }
    </style>
</head>
<body>
    <h1>' . $titulo . '</h1>
    <p><strong>Generado:</strong> ' . date('d/m/Y H:i:s') . '</p>
    <table>
        <thead>
            <tr>
                <th>Nº Asiento</th>
                <th>Fecha</th>
                <th>Concepto</th>
                <th class="dinero">Debe</th>
                <th class="dinero">Haber</th>
            </tr>
        </thead>
        <tbody>';

        // ✅ ESCAPAR cada valor con htmlspecialchars
        foreach ($asientos as $asiento) {
            $html .= '<tr>
                <td class="numero">' . htmlspecialchars($asiento['numero_asiento'] ?? '') . '</td>
                <td>' . htmlspecialchars($asiento['fecha'] ?? '') . '</td>
                <td>' . htmlspecialchars($asiento['concepto'] ?? '') . '</td>
                <td class="dinero">' . number_format((float)($asiento['debe'] ?? 0), 2, ',', '.') . '</td>
                <td class="dinero">' . number_format((float)($asiento['haber'] ?? 0), 2, ',', '.') . '</td>
            </tr>';
        }

        $html .= '</tbody>
    </table>
</body>
</html>';

        return $html;
    }
}