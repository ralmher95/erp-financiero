<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/db_connect.php';

header('Content-Type: application/json');

// Leer datos del cuerpo de la petición
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['tipo']) || empty($input['nombre']) || empty($input['nif'])) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos.']);
    exit;
}

$tipo = $input['tipo']; // 'emitida' (cliente) o 'recibida' (proveedor)
$nombre = trim($input['nombre']);
$nif = trim($input['nif']);
$email = trim($input['email'] ?? '');
$vinculada = (int)($input['vinculada'] ?? 0);

try {
    if ($tipo === 'emitida') {
        // Crear cliente
        $stmt = $pdo->prepare("INSERT INTO clientes (nombre_fiscal, nif_cif, email, es_vinculado) VALUES (?, ?, ?, ?)");
        $stmt->execute([$nombre, $nif, $email, $vinculada]);
        $id = $pdo->lastInsertId();
    } else {
        // Crear proveedor
        $stmt = $pdo->prepare("INSERT INTO proveedores (nombre_fiscal, nif_cif, email, es_vinculado) VALUES (?, ?, ?, ?)");
        $stmt->execute([$nombre, $nif, $email, $vinculada]);
        $id = $pdo->lastInsertId();
    }

    echo json_encode([
        'success' => true,
        'id' => $id,
        'nombre' => $nombre,
        'nif' => $nif
    ]);

} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        echo json_encode(['success' => false, 'error' => 'Ya existe una entidad con ese NIF/CIF.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error de base de datos: ' . $e->getMessage()]);
    }
}
