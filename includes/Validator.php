<?php
// includes/Validator.php
// CORRECCIÓN R-02 (WET → DRY) — Validador centralizado para entidades de negocio.
// Extrae la lógica de validación duplicada que antes existía por separado en
// clientes.php y proveedores.php (y sus respectivos controladores de edición).
//
// Uso:
//   require_once __DIR__ . '/../../includes/Validator.php';
//   $errores = Validator::entidadFiscal($nombre, $nif, $email);
//   if (empty($errores)) { /* guardar */ }

declare(strict_types=1);

class Validator
{
    /**
     * Valida los campos comunes a clientes Y proveedores.
     *
     * @param  string  $nombre  Nombre fiscal (requerido)
     * @param  string  $nif     NIF/CIF       (requerido)
     * @param  string  $email   Email         (opcional; si se proporciona debe ser válido)
     * @return string[]  Lista de mensajes de error (vacía si todo es correcto)
     */
    public static function entidadFiscal(string $nombre, string $nif, string $email): array
    {
        $errores = [];

        if ($nombre === '') {
            $errores[] = 'El nombre fiscal es obligatorio.';
        }

        if ($nif === '') {
            $errores[] = 'El NIF/CIF es obligatorio.';
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El formato del email no es válido.';
        }

        return $errores;
    }

    /**
     * Comprueba si ya existe un registro con el mismo NIF/CIF en una tabla dada,
     * opcionalmente excluyendo un ID concreto (útil en edición).
     *
     * @param  PDO    $pdo
     * @param  string $tabla   'clientes' | 'proveedores'
     * @param  string $nif     Valor a comprobar
     * @param  int    $excluirId  ID del registro actual (0 = ninguno)
     * @return bool   true si el NIF ya está en uso por otro registro
     */
    public static function nifDuplicado(PDO $pdo, string $tabla, string $nif, int $excluirId = 0): bool
    {
        // Sólo se permiten los nombres de tabla que el sistema conoce
        $tablasPermitidas = ['clientes', 'proveedores'];
        if (!in_array($tabla, $tablasPermitidas, true)) {
            throw new InvalidArgumentException("Tabla no permitida: $tabla");
        }

        if ($excluirId > 0) {
            $stmt = $pdo->prepare("SELECT id FROM {$tabla} WHERE nif_cif = ? AND id != ?");
            $stmt->execute([$nif, $excluirId]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM {$tabla} WHERE nif_cif = ?");
            $stmt->execute([$nif]);
        }

        return (bool) $stmt->fetch();
    }
}