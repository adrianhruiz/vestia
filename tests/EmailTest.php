<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/Email.php';

/**
 * email_canonical() decides whether two people are one person, so a change that quietly
 * widens it merges strangers' accounts and one that narrows it reopens the hole it exists
 * to close. Both directions are asserted here.
 */
describe('email_canonical() · reducción a buzón');

$sameInbox = [
    'adri@gmail.com'               => 'una dirección corriente se deja como está',
    'ADRI@Gmail.COM'               => 'las mayúsculas no hacen una cuenta nueva',
    '  adri@gmail.com  '           => 'los espacios alrededor no cuentan',
    'adri+vestia@gmail.com'        => 'la etiqueta de subdirección desaparece',
    'adri+1+2@gmail.com'           => 'todo lo que va tras el primer + desaparece',
    'a.d.r.i@gmail.com'            => 'gmail ignora los puntos del local part',
    'a.d.r.i+spam@googlemail.com'  => 'googlemail es gmail, con puntos y etiqueta',
];
foreach ($sameInbox as $input => $why) {
    test($why, fn() => same('adri@gmail.com', email_canonical($input), $input));
}

test('la etiqueta también se quita fuera de Google', fn() => same('adri@hotmail.es', email_canonical('adri+trabajo@hotmail.es')));
test('las variantes españolas de Outlook quedan cubiertas', fn() => same('adri@outlook.es', email_canonical('adri+x@outlook.es')));

describe('email_canonical() · lo que NO debe fundir');

test('fuera de gmail los puntos distinguen a dos personas', function () {
    truthy(email_canonical('a.d.r.i@hotmail.es') !== email_canonical('adri@hotmail.es'), 'a.d.r.i@hotmail.es y adri@hotmail.es son buzones distintos');
});
test('una dirección con puntos de un dominio cualquiera no se toca', fn() => same('adri.ruiz@empresa.com', email_canonical('adri.ruiz@empresa.com')));
test('un subdominio no se confunde con su dominio padre', fn() => same('adri@correo.empresa.com', email_canonical('adri@correo.empresa.com')));
test('cinco direcciones de cinco personas siguen siendo cinco', function () {
    $distintas = ['adri@gmail.com', 'adri@hotmail.es', 'a.d.r.i@hotmail.es', 'adrian@gmail.com', 'adri@empresa.com'];
    same(5, count(array_unique(array_map('email_canonical', $distintas))), 'no deben colapsar entre sí');
});
test('un local part entrecomillado pasa entero', function () {
    // Reducirlo fundiría "a+b"@raro.com con "a+c"@raro.com y dejaría fuera al segundo.
    same('"a+b"@raro.com', email_canonical('"a+b"@raro.com'), 'sin tocar');
    truthy(email_canonical('"a+b"@raro.com') !== email_canonical('"a+c"@raro.com'), 'dos entrecomillados distintos siguen distintos');
});

describe('email_canonical() · entradas rotas y estabilidad');

// Nada que reducir, y nada que romper: el valor sale como entró salvo el trim y las
// minúsculas que se aplican siempre. '+tag@gmail.com' es el caso interesante -- quitarle la
// etiqueta lo dejaría en un buzón vacío al que iría a parar cualquier otra dirección igual.
foreach (['sin-arroba', '', 'adri@', '@gmail.com', '+tag@gmail.com'] as $roto) {
    test('devuelve tal cual una entrada que no puede reducir: ' . var_export($roto, true),
        fn() => same($roto, email_canonical($roto)));
}

test('reducir una forma ya reducida no la mueve otra vez', function () {
    $entradas = ['adri+x@gmail.com', 'a.d.r.i@googlemail.com', 'adri+y@hotmail.es', 'x@y.es', '"a+b"@raro.com'];
    foreach ($entradas as $entrada) {
        $once = email_canonical($entrada);
        same($once, email_canonical($once), "segunda pasada sobre {$entrada}");
    }
});

describe('migración 009 · esquema');

test('users tiene email_canonical con índice único', function () {
    $pdo = test_pdo();
    $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_canonical'")->fetchAll();
    same(1, count($columns), 'la columna existe');
    same('YES', $columns[0]['Null'], 'admite NULL, que es lo que deja convivir a las cuentas antiguas');
    $index = $pdo->query("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='users_email_canonical'")->fetchAll();
    same(1, count($index), 'el índice existe');
    same(0, (int) $index[0]['NON_UNIQUE'], 'y es único');
});

test('varias cuentas antiguas sin forma canónica conviven', function () {
    $pdo = test_pdo();
    test_truncate($pdo, 'users');
    $insert = $pdo->prepare('INSERT INTO users(name,email,password_hash,style_profile,created_at) VALUES(?,?,?,?,?)');
    foreach (['a@gmail.com', 'b@gmail.com', 'c@gmail.com'] as $email) $insert->execute(['X', $email, 'x', '{}', date(DATE_ATOM)]);
    same(3, (int) $pdo->query('SELECT COUNT(*) FROM users WHERE email_canonical IS NULL')->fetchColumn(), 'los NULL no chocan entre sí');
});

test('dos cuentas no pueden compartir buzón', function () {
    $pdo = test_pdo();
    test_truncate($pdo, 'users');
    $insert = $pdo->prepare('INSERT INTO users(name,email,email_canonical,password_hash,style_profile,created_at) VALUES(?,?,?,?,?,?)');
    $insert->execute(['X', 'adri@gmail.com', email_canonical('adri@gmail.com'), 'x', '{}', date(DATE_ATOM)]);
    try {
        $insert->execute(['X', 'adri+vestia@gmail.com', email_canonical('adri+vestia@gmail.com'), 'x', '{}', date(DATE_ATOM)]);
        throw new RuntimeException('la segunda inserción debería haber sido rechazada');
    } catch (PDOException $e) {
        // 1062 es justo el código que /api/register traduce a "ya está registrado".
        same(1062, (int) ($e->errorInfo[1] ?? 0), 'rechazada por clave duplicada');
    }
});

test('las migraciones son idempotentes: aplicarlas dos veces no rompe', function () {
    $pdo = test_pdo();
    foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $file) {
        foreach (sql_statements((string) file_get_contents($file)) as $statement) $pdo->exec($statement);
    }
    same(1, count($pdo->query("SHOW COLUMNS FROM users LIKE 'email_canonical'")->fetchAll()), 'la columna sigue siendo una');
});
