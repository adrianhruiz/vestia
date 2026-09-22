#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

// Connecting already applies every pending migration; this only reports the outcome.
$pdo = db();
$applied = [];
foreach ($pdo->query('SELECT version, applied_at FROM schema_migrations ORDER BY version') as $row) $applied[$row['version']] = $row['applied_at'];

$files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
sort($files);
echo 'Base de datos: ' . env('DB_DATABASE', 'vestia_pre') . PHP_EOL;
foreach ($files as $file) {
    $version = basename($file);
    printf("  %-44s %s%s", $version, $applied[$version] ?? 'PENDIENTE', PHP_EOL);
    unset($applied[$version]);
}
foreach ($applied as $version => $at) printf("  %-44s %s (aplicada, sin fichero en el repo)%s", $version, $at, PHP_EOL);
echo count($files) . ' migraciones en el repositorio.' . PHP_EOL;
