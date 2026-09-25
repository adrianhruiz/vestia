#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/Email.php';

/**
 * Fills email_canonical for accounts registered before migration 009. Until a row has one
 * it is invisible to the duplicate check, so an inbox that already has an old account here
 * can still be registered again.
 *
 * Collisions are reported, never resolved. Two existing accounts reducing to the same inbox
 * means somebody already did what this column is meant to stop, and which of the two keeps
 * the mailbox -- or whether they should be merged at all -- is not a decision a backfill
 * gets to make. Both are left NULL and listed; everything else goes through.
 *
 * Safe to run more than once: rows that already have a value are not selected.
 */
$pdo = db();
$rows = $pdo->query("SELECT id, email FROM users WHERE email_canonical IS NULL ORDER BY id")->fetchAll();
$update = $pdo->prepare('UPDATE users SET email_canonical=? WHERE id=?');

// Which account already holds each canonical form, counting the rows this run has filled
// and the ones that were filled before it.
$held = [];
foreach ($pdo->query("SELECT id, email_canonical FROM users WHERE email_canonical IS NOT NULL")->fetchAll() as $row) {
    $held[(string) $row['email_canonical']] = (int) $row['id'];
}

$filled = 0;
$collisions = [];
foreach ($rows as $row) {
    $canonical = email_canonical((string) $row['email']);
    if (isset($held[$canonical])) {
        $collisions[] = "  {$row['email']} (id {$row['id']}) comparte buzón con la cuenta id {$held[$canonical]}";
        continue;
    }
    $update->execute([$canonical, $row['id']]);
    $held[$canonical] = (int) $row['id'];
    $filled++;
}

echo "Cuentas actualizadas: {$filled} · pendientes por colisión: " . count($collisions) . "\n";
if ($collisions) {
    echo "\nEstas cuentas se han quedado sin forma canónica porque otra ya tenía ese buzón.\n";
    echo "Resuélvelas a mano y vuelve a ejecutar el script:\n" . implode("\n", $collisions) . "\n";
    exit(1);
}
