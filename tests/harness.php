<?php
declare(strict_types=1);

/**
 * The smallest thing that can fail a build.
 *
 * No Composer, no PHPUnit: this repository has neither and adding one to run a few hundred
 * assertions would be the largest dependency in it. Everything here is a function and an
 * exit code.
 *
 * Loaded by tests/run.php, which is the way to run any of this.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

/** Thrown by skip() so a test that cannot run here is not a test that failed. */
final class TestSkipped extends RuntimeException {}

$GLOBALS['test_results'] = ['passed' => 0, 'failed' => 0, 'skipped' => 0, 'failures' => []];

/** Runs one case. Anything it throws is a failure, except a skip. */
function test(string $name, callable $body): void {
    try {
        $body();
        $GLOBALS['test_results']['passed']++;
        echo "  \033[32mok\033[0m    {$name}\n";
    } catch (TestSkipped $skipped) {
        $GLOBALS['test_results']['skipped']++;
        echo "  \033[33m--\033[0m    {$name} ({$skipped->getMessage()})\n";
    } catch (Throwable $error) {
        $GLOBALS['test_results']['failed']++;
        $GLOBALS['test_results']['failures'][] = $name . ': ' . $error->getMessage();
        echo "  \033[31mFAIL\033[0m  {$name}\n        {$error->getMessage()}\n";
    }
}

/** Prints the name of the group whose cases follow. */
function describe(string $name): void {
    echo "\n{$name}\n";
}

/**
 * $why is optional throughout: the name the case was declared with usually says it already,
 * and it is only worth repeating when one case makes several different assertions.
 */
function same(mixed $expected, mixed $actual, string $why = ''): void {
    if ($expected === $actual) return;
    throw new RuntimeException(test_detail($why) . 'esperado ' . var_export($expected, true) . ', obtenido ' . var_export($actual, true));
}

function truthy(mixed $value, string $why = ''): void {
    if (!$value) throw new RuntimeException(test_detail($why) . 'esperado algo cierto, obtenido ' . var_export($value, true));
}

function falsy(mixed $value, string $why = ''): void {
    if ($value) throw new RuntimeException(test_detail($why) . 'esperado algo falso, obtenido ' . var_export($value, true));
}

function test_detail(string $why): string {
    return $why === '' ? '' : $why . ' — ';
}

/** Ends this case without failing it: something it needs is not available here. */
function skip(string $why): never {
    throw new TestSkipped($why);
}

/**
 * A throwaway database with every migration applied, or a skip when none is configured.
 *
 * TEST_DB_DATABASE is a separate name on purpose, and refusing to accept the application's
 * own is the whole safety story: every case that uses this drops and recreates the schema,
 * and pointing that at a working database would empty it.
 */
function test_pdo(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;

    $database = (string) env('TEST_DB_DATABASE', '');
    if ($database === '') skip('define TEST_DB_DATABASE en .env para las pruebas con base de datos');
    if ($database === env('DB_DATABASE', '')) {
        throw new RuntimeException('TEST_DB_DATABASE no puede ser la base de datos de la aplicación: estas pruebas la borrarían.');
    }

    $socket = (string) env('DB_SOCKET', '');
    $prefix = $socket !== ''
        ? 'mysql:unix_socket=' . $socket
        : 'mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

    $server = new PDO($prefix . ';charset=utf8mb4', env('DB_USERNAME', ''), env('DB_PASSWORD', ''), $options);
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $pdo = new PDO($prefix . ';dbname=' . $database . ';charset=utf8mb4', env('DB_USERNAME', ''), env('DB_PASSWORD', ''), $options);
    // The same files the application applies on every connection, in the same order.
    foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $file) {
        foreach (sql_statements((string) file_get_contents($file)) as $statement) $pdo->exec($statement);
    }
    return $pdo;
}

/** Empties the tables a case is about, so cases do not inherit each other's rows. */
function test_truncate(PDO $pdo, string ...$tables): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) $pdo->exec('TRUNCATE TABLE `' . $table . '`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

/** Prints the tally and returns the exit code the runner should leave with. */
function test_summary(): int {
    $results = $GLOBALS['test_results'];
    echo "\n" . str_repeat('-', 60) . "\n";
    foreach ($results['failures'] as $failure) echo "  FALLO  {$failure}\n";
    printf("%d correctas · %d fallidas · %d omitidas\n", $results['passed'], $results['failed'], $results['skipped']);
    return $results['failed'] > 0 ? 1 : 0;
}
