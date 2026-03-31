<?php
// includes/layout_header.php
// MEJORA M-08 / 6.1 — Sistema de templates centralizado.
// Elimina la repetición de DOCTYPE, <head> y navbar en cada vista.
//
// Uso en cualquier vista:
//   $titulo          = 'Libro Diario';  // obligatorio
//   $incluir_chartjs = true;            // opcional, default false
//   $incluir_select2 = true;            // opcional, default false
//   require_once __DIR__ . '/../../includes/layout_header.php';

declare(strict_types=1);

if (!defined('URL_BASE')) {
    die('⛔ Acceso directo no permitido.');
}

// Valores por defecto para variables opcionales
$titulo          = $titulo          ?? APP_NAME;
$incluir_chartjs = $incluir_chartjs ?? false;
$incluir_select2 = $incluir_select2 ?? false;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo) ?> — ERP Financiero</title>

    <!-- MEJORA M-01: CSS global en todas las vistas desde un único archivo -->
    <link rel="stylesheet" href="<?= URL_BASE ?>public/assets/css/styles.css">

    <?php if ($incluir_select2): ?>
    <!-- Select2 — solo en vistas que lo necesiten -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <?php endif; ?>

    <?php if ($incluir_chartjs): ?>
    <!-- Chart.js — solo en vistas que lo necesiten -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php endif; ?>
</head>
<body>
<?php require_once __DIR__ . '/navbar.php'; ?>
