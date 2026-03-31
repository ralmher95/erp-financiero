<?php
/**
 * tests/test_db_connect.php
 *
 * Suite de pruebas básicas para verificar que la conexión a la base
 * de datos funciona correctamente y que las tablas principales del
 * ERP Financiero existen y tienen la estructura esperada.
 *
 * Uso desde terminal:
 *   php tests/test_db_connect.php
 *
 * Uso desde navegador (solo en entorno de desarrollo):
 *   http://localhost/erp-financiero/tests/test_db_connect.php
 *
 * ⚠️  NO exponer este archivo en producción.
 *     Añadir al .gitignore si contiene datos sensibles, o proteger
 *     con autenticación HTTP básica en el servidor web.
 */

declare(strict_types=1);

/* ============================================================
   Configuración de entorno de test
   ============================================================ */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Detectar si se ejecuta desde CLI o navegador
$esCli = PHP_SAPI === 'cli';
$nl    = $esCli ? PHP_EOL : '<br>';

/* ============================================================
   Helpers de salida
   ============================================================ */

function ok(string $mensaje): void
{
    global $esCli, $nl;
    $prefijo = $esCli ? '  [OK]  ' : '<span style="color:#27ae60;font-weight:bold">[OK]</span> ';
    echo $prefijo . $mensaje . $nl;
}

function fallo(string $mensaje): void
{
    global $esCli, $nl;
    $prefijo = $esCli ? ' [FAIL] ' : '<span style="color:#e74c3c;font-weight:bold">[FAIL]</span> ';
    echo $prefijo . $mensaje . $nl;
}

function info(string $mensaje): void
{
    global $esCli, $nl;
    $prefijo = $esCli ? '  [--]  ' : '<span style="color:#3498db">[INFO]</span> ';
    echo $prefijo . $mensaje . $nl;
}

function titulo(string $texto): void
{
    global $esCli, $nl;
    if ($esCli) {
        echo $nl . '=== ' . strtoupper($texto) . ' ===' . $nl;
    } else {
        echo "<h3 style='color:#2c3e50;border-bottom:1px solid #eee;padding-bottom:6px;margin-top:20px'>"
           . htmlspecialchars($texto) . "</h3>";
    }
}

/* ============================================================
   Cabecera HTML (solo navegador)
   ============================================================ */

if (!$esCli) {
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
       . '<title>Test DB — ERP Financiero</title>'
       . '<style>body{font-family:monospace;max-width:800px;margin:30px auto;background:#f4f6f9;padding:20px}'
       . 'pre{background:#fff;padding:15px;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.07)}</style>'
       . '</head><body><h2>🔌 Test de conexión — ERP Financiero</h2><pre>';
}

/* ============================================================
   Variables de estado global
   ============================================================ */

$totalTests  = 0;
$testsOk     = 0;
$testsFail   = 0;

/**
 * Registra el resultado de un test.
 */
function assert_test(bool $condicion, string $descripcion): void
{
    global $totalTests, $testsOk, $testsFail;
    $totalTests++;
    if ($condicion) {
        $testsOk++;
        ok($descripcion);
    } else {
        $testsFail++;
        fallo($descripcion);
    }
}

/* ============================================================
   TEST 1 — Carga del archivo de conexión
   ============================================================ */

titulo('1. Archivo de configuración');

$rutaConfig = __DIR__ . '/../config/db_connect.php';
assert_test(file_exists($rutaConfig), 'El archivo config/db_connect.php existe.');

if (!file_exists($rutaConfig)) {
    fallo('No se puede continuar sin el archivo de configuración.');
    goto resultado_final;
}

require_once $rutaConfig;

assert_test(isset($pdo), 'La variable $pdo está definida tras incluir db_connect.php.');

/* ============================================================
   TEST 2 — Tipo y estado de la conexión PDO
   ============================================================ */

titulo('2. Conexión PDO');

assert_test($pdo instanceof PDO, 'El objeto $pdo es una instancia de PDO.');

if ($pdo instanceof PDO) {
    // Comprobar que la conexión está activa con una query trivial
    try {
        $resultado = $pdo->query('SELECT 1')->fetchColumn();
        assert_test($resultado == 1, 'La conexión responde correctamente (SELECT 1 = 1).');
    } catch (Throwable $e) {
        fallo('La query de prueba falló: ' . $e->getMessage());
        $testsFail++;
        $totalTests++;
    }

    // Mostrar versión del motor
    try {
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        info('Versión del servidor MySQL/MariaDB: ' . $version);
    } catch (Throwable $e) {
        info('No se pudo obtener la versión del servidor.');
    }

    // Verificar modo de errores
    $modoErrores = $pdo->getAttribute(PDO::ATTR_ERRMODE);
    assert_test(
        $modoErrores === PDO::ERRMODE_EXCEPTION,
        'PDO está configurado en modo ERRMODE_EXCEPTION.'
    );
}

/* ============================================================
   TEST 3 — Existencia de tablas principales
   ============================================================ */

titulo('3. Estructura de la base de datos');

$tablasEsperadas = [
    'cuentas_contables' => 'Catálogo de cuentas del PGC',
    'libro_diario'      => 'Libro Diario de asientos contables',
    'clientes'          => 'Gestión de clientes',
    'proveedores'       => 'Gestión de proveedores',
    'facturas'          => 'Facturas emitidas',
    'lineas_factura'    => 'Líneas de factura',
];

if ($pdo instanceof PDO) {
    foreach ($tablasEsperadas as $tabla => $descripcion) {
        try {
            $existe = $pdo->query("SHOW TABLES LIKE '$tabla'")->fetchColumn();
            assert_test((bool)$existe, "Tabla '$tabla' existe  ($descripcion).");
        } catch (Throwable $e) {
            fallo("Error al comprobar la tabla '$tabla': " . $e->getMessage());
            $testsFail++;
            $totalTests++;
        }
    }
}

/* ============================================================
   TEST 4 — Integridad básica de datos
   ============================================================ */

titulo('4. Integridad de datos');

if ($pdo instanceof PDO) {
    // Cuentas contables
    try {
        $numCuentas = (int)$pdo->query("SELECT COUNT(*) FROM cuentas_contables")->fetchColumn();
        assert_test($numCuentas > 0, "La tabla cuentas_contables contiene $numCuentas registros.");
    } catch (Throwable $e) {
        fallo('Error al contar cuentas_contables: ' . $e->getMessage());
        $testsFail++; $totalTests++;
    }

    // Cuadre del Libro Diario
    try {
        $stmt = $pdo->query("SELECT ROUND(SUM(debe),2) as td, ROUND(SUM(haber),2) as th FROM libro_diario");
        $totales = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($totales && ($totales['td'] !== null)) {
            $diferencia = abs((float)$totales['td'] - (float)$totales['th']);
            assert_test(
                $diferencia <= 0.01,
                sprintf(
                    'El Libro Diario cuadra: Debe %.2f€ = Haber %.2f€ (diferencia: %.4f€).',
                    $totales['td'],
                    $totales['th'],
                    $diferencia
                )
            );
        } else {
            info('El Libro Diario está vacío (sin asientos registrados aún).');
        }
    } catch (Throwable $e) {
        fallo('Error al verificar el cuadre del Libro Diario: ' . $e->getMessage());
        $testsFail++; $totalTests++;
    }

    // Facturas sin cliente asociado (integridad referencial)
    try {
        $huerfanas = (int)$pdo->query(
            "SELECT COUNT(*) FROM facturas f LEFT JOIN clientes c ON f.cliente_id = c.id WHERE c.id IS NULL"
        )->fetchColumn();
        assert_test($huerfanas === 0, "No hay facturas sin cliente asociado ($huerfanas encontradas).");
    } catch (Throwable $e) {
        fallo('Error al verificar integridad de facturas: ' . $e->getMessage());
        $testsFail++; $totalTests++;
    }
}

/* ============================================================
   TEST 5 — Operaciones de escritura (transacción que se revierte)
   ============================================================ */

titulo('5. Operaciones de escritura (rollback)');

if ($pdo instanceof PDO) {
    try {
        $pdo->beginTransaction();

        // Intentar insertar un cliente de prueba
        $stmt = $pdo->prepare(
            "INSERT INTO clientes (nombre_fiscal, nif_cif, email) VALUES (?, ?, ?)"
        );
        $stmt->execute(['__TEST_CLIENTE__', 'X99999999X', 'test@test.local']);
        $idInsertado = (int)$pdo->lastInsertId();

        $existe = (int)$pdo->query(
            "SELECT COUNT(*) FROM clientes WHERE id = $idInsertado"
        )->fetchColumn();

        $pdo->rollBack(); // ← Siempre revertimos, no dejamos datos basura

        $yaNoExiste = (int)$pdo->query(
            "SELECT COUNT(*) FROM clientes WHERE id = $idInsertado"
        )->fetchColumn();

        assert_test($existe === 1,      'INSERT en clientes funciona correctamente.');
        assert_test($yaNoExiste === 0,  'ROLLBACK funciona: el registro de prueba fue revertido.');

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fallo('Error en el test de escritura: ' . $e->getMessage());
        $testsFail += 2;
        $totalTests += 2;
    }
}

/* ============================================================
   Resultado final
   ============================================================ */

resultado_final:

titulo('Resultado');

$emoji = ($testsFail === 0) ? '✅' : '❌';
info(sprintf(
    '%s %d tests ejecutados — %d OK — %d fallidos.',
    $emoji,
    $totalTests,
    $testsOk,
    $testsFail
));

if ($testsFail > 0) {
    fallo('Revisa los errores anteriores antes de desplegar el proyecto.');
} else {
    ok('Todo en orden. La base de datos está correctamente configurada.');
}

/* ============================================================
   Cierre HTML (solo navegador)
   ============================================================ */

if (!$esCli) {
    echo '</pre></body></html>';
}

// Código de salida para integración con CI/CD
exit($testsFail > 0 ? 1 : 0);