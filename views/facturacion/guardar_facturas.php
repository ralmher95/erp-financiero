<?php
/**
 * PROCESADOR DE GUARDADO
 * Validaciones robustas para asegurar que acepte 1 sola línea sin problemas.
 */
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../config/db_connect.php'; 
require_once __DIR__ . '/../../src/Services/FacturacionService.php';
use App\Services\FacturacionService;

// Validaciones de seguridad básicas
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: listar_facturas.php");
    exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    die("Token de seguridad inválido. Recarga la página.");
}

// 1. Recogida de datos generales de la factura
$datosFactura = [
    'cliente_id'     => filter_input(INPUT_POST, 'cliente_id', FILTER_VALIDATE_INT),
    'numero'         => filter_input(INPUT_POST, 'numero_factura', FILTER_SANITIZE_SPECIAL_CHARS),
    'fecha'          => $_POST['fecha'] ?? date('Y-m-d'),
    'base_imponible' => (float)($_POST['base_imponible'] ?? 0),
    'iva_total'      => (float)($_POST['iva_total'] ?? 0),
    'total'          => (float)($_POST['total'] ?? 0),
];

// 2. EXTRACCIÓN Y VALIDACIÓN DE LÍNEAS (A prueba de balas)
$lineasRaw = $_POST['lineas'] ?? [];
$lineasValidas = [];

// Recorremos las líneas que llegan del formulario
foreach ($lineasRaw as $linea) {
    // Limpiamos espacios en blanco por si acaso
    $concepto = trim((string)($linea['concepto'] ?? ''));
    $precio   = trim((string)($linea['precio'] ?? ''));
    $cantidad = trim((string)($linea['cantidad'] ?? ''));
    $iva      = trim((string)($linea['iva'] ?? '21'));
    
    // Si la línea tiene algo escrito en el concepto y el precio es un número, ES VÁLIDA
    if ($concepto !== '' && is_numeric($precio)) {
        $lineasValidas[] = [
            'concepto'        => $concepto,
            'precio_unitario' => (float)$precio,
            'cantidad'        => (float)($cantidad ?: 1),
            'iva'             => (float)$iva
        ];
    }
}

// 3. Verificación final: ¿Hay al menos 1 línea válida?
if (empty($lineasValidas)) {
    // Si entra aquí, es que PHP detectó que no había ni un solo concepto y precio válido
    header("Location: crear_factura.php?error=sinlineas");
    exit;
}

$servicio = new FacturacionService($pdo);

try {
    // 4. Persistencia en Base de Datos
    $servicio->crearFactura($datosFactura, $lineasValidas);
    
    // Si todo va bien, vamos a la lista con un éxito
    header("Location: listar_facturas.php?status=success");
    exit;

} catch (Exception $e) {
    error_log("Error Guardando Factura: " . $e->getMessage());
    
    // Guardar el mensaje de error en la sesión para mostrarlo en la vista
    $_SESSION['error_save'] = $e->getMessage();
    
    // Si da un error de duplicado (ej. F-2024-001 ya existe)
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        header("Location: crear_factura.php?error=duplicado");
        exit;
    }
    
    header("Location: crear_factura.php?error=debug");
    exit;
}