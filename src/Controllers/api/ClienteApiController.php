<?php
// =============================================================================
// src/Controllers/api/ClienteApiController.php — API de clientes
// FIX V2: SQL Injection en búsqueda
// FIX V8: IDOR (Insecure Direct Object Reference)
// =============================================================================

declare(strict_types=1);

namespace App\Controllers\Api;

use Exception;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class ClienteApiController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Lista los clientes del usuario autenticado (paginado).
     * 
     * @param array $queryParams GET parameters
     * @return array
     */
    public function listar(array $queryParams): array
    {
        try {
            $this->verificarAutenticacion();
            $usuarioId = $_SESSION['usuario_id'];

            // ✅ VALIDAR: paginación
            $pagina = max(1, (int)($queryParams['pagina'] ?? 1));
            $porPagina = min(100, max(1, (int)($queryParams['por_pagina'] ?? 20)));
            $offset = ($pagina - 1) * $porPagina;

            // ✅ PREPARED STATEMENT: lista solo clientes del usuario actual
            $stmt = $this->pdo->prepare(
                "SELECT id, nombre, nif, email, telefono, ciudad, estado
                 FROM clientes
                 WHERE usuario_id = ?
                 ORDER BY nombre ASC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute([$usuarioId, $porPagina, $offset]);
            $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Contar total
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) as total FROM clientes WHERE usuario_id = ?"
            );
            $stmt->execute([$usuarioId]);
            $total = (int)$stmt->fetch()['total'];

            return [
                'ok' => true,
                'data' => $clientes,
                'total' => $total,
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
            ];

        } catch (Exception $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Busca clientes por término (nombre o NIF).
     * 
     * @param array $queryParams GET parameters
     * @return array
     */
    public function buscar(array $queryParams): array
    {
        try {
            $this->verificarAutenticacion();
            $usuarioId = $_SESSION['usuario_id'];

            // ✅ VALIDAR: término de búsqueda
            $termino = $queryParams['q'] ?? '';
            if (strlen($termino) < 2) {
                throw new InvalidArgumentException('Búsqueda muy corta (mínimo 2 caracteres).');
            }

            // ✅ SANITIZAR: truncar y limpiar espacios
            $termino = mb_substr(trim($termino), 0, 100, 'UTF-8');

            // ✅ PREPARED STATEMENT: evitar SQLi
            $stmt = $this->pdo->prepare(
                "SELECT id, nombre, nif, email, telefono
                 FROM clientes
                 WHERE usuario_id = ? AND (
                     nombre LIKE ? OR 
                     nif LIKE ? OR 
                     email LIKE ?
                 )
                 ORDER BY nombre ASC
                 LIMIT 20"
            );

            // Usar % solo en SQL, no concatenar strings
            $patron = '%' . $termino . '%';
            $stmt->execute([$usuarioId, $patron, $patron, $patron]);
            $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ✅ ESCAPAR: output JSON seguro
            return [
                'ok' => true,
                'data' => $clientes,
                'total' => count($clientes),
            ];

        } catch (InvalidArgumentException $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        } catch (Exception $e) {
            error_log('[ClienteApiController::buscar] ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'Error interno en la búsqueda.',
            ];
        }
    }

    /**
     * Obtiene un cliente específico.
     * 
     * @param int $clienteId
     * @return array
     */
    public function obtener(int $clienteId): array
    {
        try {
            $this->verificarAutenticacion();
            $usuarioId = $_SESSION['usuario_id'];

            // ✅ IDOR FIX: Verificar que el cliente pertenece al usuario actual
            $stmt = $this->pdo->prepare(
                "SELECT * FROM clientes 
                 WHERE id = ? AND usuario_id = ?"
            );
            $stmt->execute([$clienteId, $usuarioId]);
            $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cliente) {
                // No exponer si existe el cliente o no (privacidad)
                http_response_code(404);
                throw new RuntimeException('Cliente no encontrado.');
            }

            return [
                'ok' => true,
                'data' => $cliente,
            ];

        } catch (Exception $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Crea un nuevo cliente.
     * 
     * @param array $post POST data
     * @return array
     */
    public function crear(array $post): array
    {
        try {
            $this->verificarAutenticacion();
            $usuarioId = $_SESSION['usuario_id'];

            // ✅ VALIDAR: datos obligatorios
            $errores = $this->validarDatos($post);
            if (!empty($errores)) {
                throw new InvalidArgumentException(implode('; ', $errores));
            }

            $nombre = $this->sanitizar($post['nombre']);
            $nif = strtoupper($this->sanitizar($post['nif']));
            $email = strtolower($this->sanitizar($post['email']));
            $telefono = $this->sanitizar($post['telefono'] ?? '');
            $ciudad = $this->sanitizar($post['ciudad'] ?? '');

            // ✅ Verificar NIF único (para este usuario)
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) as cnt FROM clientes 
                 WHERE usuario_id = ? AND nif = ?"
            );
            $stmt->execute([$usuarioId, $nif]);
            if ($stmt->fetch()['cnt'] > 0) {
                throw new InvalidArgumentException('Ya existe un cliente con este NIF.');
            }

            // ✅ INSERTAR: prepared statement
            $stmt = $this->pdo->prepare(
                "INSERT INTO clientes 
                 (usuario_id, nombre, nif, email, telefono, ciudad, fecha_creacion)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$usuarioId, $nombre, $nif, $email, $telefono, $ciudad]);

            $clienteId = (int)$this->pdo->lastInsertId();

            return [
                'ok' => true,
                'id' => $clienteId,
                'mensaje' => 'Cliente creado correctamente.',
            ];

        } catch (InvalidArgumentException $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        } catch (Exception $e) {
            error_log('[ClienteApiController::crear] ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'Error al crear cliente.',
            ];
        }
    }

    /**
     * Actualiza un cliente (solo si pertenece al usuario).
     * 
     * @param int $clienteId
     * @param array $post POST data
     * @return array
     */
    public function actualizar(int $clienteId, array $post): array
    {
        try {
            $this->verificarAutenticacion();
            $usuarioId = $_SESSION['usuario_id'];

            // ✅ IDOR FIX: Verificar propiedad
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) as cnt FROM clientes 
                 WHERE id = ? AND usuario_id = ?"
            );
            $stmt->execute([$clienteId, $usuarioId]);
            if ($stmt->fetch()['cnt'] === 0) {
                http_response_code(403);
                throw new RuntimeException('No tienes permiso para editar este cliente.');
            }

            // ✅ VALIDAR: datos
            $errores = $this->validarDatos($post, permitirVacios: true);
            if (!empty($errores)) {
                throw new InvalidArgumentException(implode('; ', $errores));
            }

            // Preparar UPDATE dinámico
            $campos = [];
            $valores = [];

            if (!empty($post['nombre'])) {
                $campos[] = 'nombre = ?';
                $valores[] = $this->sanitizar($post['nombre']);
            }
            if (!empty($post['email'])) {
                $campos[] = 'email = ?';
                $valores[] = strtolower($this->sanitizar($post['email']));
            }
            if (!empty($post['telefono'])) {
                $campos[] = 'telefono = ?';
                $valores[] = $this->sanitizar($post['telefono']);
            }
            if (!empty($post['ciudad'])) {
                $campos[] = 'ciudad = ?';
                $valores[] = $this->sanitizar($post['ciudad']);
            }

            if (empty($campos)) {
                throw new InvalidArgumentException('No hay datos para actualizar.');
            }

            // ✅ PREPARED STATEMENT: construir dinámicamente
            $valores[] = $clienteId;
            $valores[] = $usuarioId;

            $sql = "UPDATE clientes SET " . implode(', ', $campos) . 
                   " WHERE id = ? AND usuario_id = ?";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($valores);

            return [
                'ok' => true,
                'mensaje' => 'Cliente actualizado correctamente.',
            ];

        } catch (InvalidArgumentException | RuntimeException $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        } catch (Exception $e) {
            error_log('[ClienteApiController::actualizar] ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'Error al actualizar cliente.',
            ];
        }
    }

    /**
     * Elimina un cliente (solo si pertenece al usuario).
     * 
     * @param int $clienteId
     * @return array
     */
    public function eliminar(int $clienteId): array
    {
        try {
            $this->verificarAutenticacion();
            $usuarioId = $_SESSION['usuario_id'];

            // ✅ IDOR FIX: Verificar propiedad
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) as cnt FROM clientes 
                 WHERE id = ? AND usuario_id = ?"
            );
            $stmt->execute([$clienteId, $usuarioId]);
            if ($stmt->fetch()['cnt'] === 0) {
                http_response_code(403);
                throw new RuntimeException('No tienes permiso para eliminar este cliente.');
            }

            // Verificar que no tiene facturas asociadas
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) as cnt FROM facturas WHERE cliente_id = ?"
            );
            $stmt->execute([$clienteId]);
            if ($stmt->fetch()['cnt'] > 0) {
                throw new RuntimeException('No se puede eliminar cliente con facturas asociadas.');
            }

            // ✅ ELIMINAR
            $stmt = $this->pdo->prepare("DELETE FROM clientes WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$clienteId, $usuarioId]);

            return [
                'ok' => true,
                'mensaje' => 'Cliente eliminado correctamente.',
            ];

        } catch (InvalidArgumentException | RuntimeException $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        } catch (Exception $e) {
            error_log('[ClienteApiController::eliminar] ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'Error al eliminar cliente.',
            ];
        }
    }

    // =========================================================================
    // MÉTODOS AUXILIARES
    // =========================================================================

    /**
     * Verifica que el usuario está autenticado.
     */
    private function verificarAutenticacion(): void
    {
        if (empty($_SESSION['usuario_id'])) {
            http_response_code(401);
            throw new RuntimeException('No autenticado.');
        }
    }

    /**
     * Valida los datos de cliente.
     */
    private function validarDatos(array $post, bool $permitirVacios = false): array
    {
        $errores = [];

        $nombre = $post['nombre'] ?? '';
        $nif = $post['nif'] ?? '';
        $email = $post['email'] ?? '';

        if (!$permitirVacios) {
            if (empty($nombre)) {
                $errores[] = 'Nombre requerido';
            }
            if (empty($nif)) {
                $errores[] = 'NIF requerido';
            }
            if (empty($email)) {
                $errores[] = 'Email requerido';
            }
        }

        if (!empty($nif) && !$this->validarNif($nif)) {
            $errores[] = 'NIF no válido';
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'Email no válido';
        }

        return $errores;
    }

    /**
     * Valida formato NIF/CIF.
     */
    private function validarNif(string $nif): bool
    {
        return preg_match('/^[0-9]{8}[A-Z]|[A-Z][0-9]{7}[0-9A-Z]$/', strtoupper($nif));
    }

    /**
     * Sanitiza string para BD.
     */
    private function sanitizar(string $valor, int $maxLen = 255): string
    {
        $limpio = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $valor) ?? '');
        return mb_substr($limpio, 0, $maxLen, 'UTF-8');
    }
}