#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Runs every tests/*Test.php.
 *
 *   php tests/run.php              todas
 *   php tests/run.php Email        solo las que lleven "Email" en el nombre
 *
 * Los casos que tocan base de datos necesitan TEST_DB_DATABASE en el .env, con un nombre
 * distinto del de la aplicación: crean y borran el esquema en cada ejecución. Sin esa
 * variable se omiten y el resto sigue corriendo.
 */

require __DIR__ . '/harness.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$ran = 0;
foreach ($files as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) continue;
    require $file;
    $ran++;
}

if ($ran === 0) {
    fwrite(STDERR, "No hay pruebas que coincidan con \"{$filter}\".\n");
    exit(1);
}
exit(test_summary());
