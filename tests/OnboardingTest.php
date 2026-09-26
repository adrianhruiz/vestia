<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/Onboarding.php';

/**
 * The welcome is one flag and two decisions, and both decisions are the kind that go wrong
 * quietly: an account nagged forever because nothing was written down, a profile half wiped
 * by a form that only asked about half of it, or a query string that gets to choose where
 * somebody lands after registering.
 */

function seed_onboarding_user(PDO $pdo, string $profile = '{}'): int {
    $pdo->prepare('INSERT INTO users(name,email,email_canonical,password_hash,style_profile,created_at) VALUES(?,?,?,?,?,?)')
        ->execute(['Adri', 'a' . bin2hex(random_bytes(6)) . '@ejemplo.com', 'c' . bin2hex(random_bytes(6)) . '@ejemplo.com', 'x', $profile, date(DATE_ATOM)]);
    return (int) $pdo->lastInsertId();
}

function fresh_onboarding(): PDO {
    $pdo = test_pdo();
    test_truncate($pdo, 'users');
    return $pdo;
}

describe('migración 011 · esquema');

test('users.onboarding_done_at existe y admite nulos', function () {
    $pdo = test_pdo();
    $column = $pdo->query("SELECT IS_NULLABLE, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='onboarding_done_at'")->fetch();
    truthy($column !== false, 'la columna existe');
    same('YES', $column['IS_NULLABLE'], 'nulo es "todavía no la ha hecho"');
});

describe('onboarding_pending()');

test('una cuenta recién creada la tiene pendiente', function () {
    truthy(onboarding_pending(['id' => 1, 'onboarding_done_at' => null]));
});

test('una cuenta que ya pasó por ella, no', function () {
    falsy(onboarding_pending(['id' => 1, 'onboarding_done_at' => '2026-09-26T10:00:00+02:00']));
});

test('sin sesión no hay nada pendiente', function () {
    // La landing pinta el aside solo con sesión, pero preguntarlo sin ella no puede reventar.
    falsy(onboarding_pending(null));
});

describe('onboarding_done()');

test('marca la cuenta y no vuelve a tocarla', function () {
    $pdo = fresh_onboarding();
    $id = seed_onboarding_user($pdo);
    onboarding_done($pdo, $id);
    $primera = $pdo->query("SELECT onboarding_done_at FROM users WHERE id={$id}")->fetchColumn();
    truthy($primera !== null && $primera !== '', 'queda anotada');

    onboarding_done($pdo, $id);
    same($primera, $pdo->query("SELECT onboarding_done_at FROM users WHERE id={$id}")->fetchColumn(), 'la fecha es cuándo dejó de ser nueva, no la última vez que guardó');
});

test('después de marcarla deja de estar pendiente', function () {
    $pdo = fresh_onboarding();
    $id = seed_onboarding_user($pdo);
    onboarding_done($pdo, $id);
    $user = $pdo->query("SELECT id,onboarding_done_at FROM users WHERE id={$id}")->fetch();
    falsy(onboarding_pending($user));
});

test('no marca la cuenta de otra persona', function () {
    $pdo = fresh_onboarding();
    $mio = seed_onboarding_user($pdo);
    $suyo = seed_onboarding_user($pdo);
    onboarding_done($pdo, $mio);
    same(null, $pdo->query("SELECT onboarding_done_at FROM users WHERE id={$suyo}")->fetchColumn());
});

describe('onboarding_next_path() · a dónde se sale de la bienvenida');

test('quien venía de un look compartido sigue teniendo su prenda esperando', function () {
    same('/?nueva-prenda=1', onboarding_next_path('nueva-prenda'));
});

test('quien venía de la landing entra en su armario', function () {
    same('/', onboarding_next_path(''));
});

$intentos = [
    'https://otra-web.example/phishing' => 'una URL absoluta',
    '//otra-web.example'                => 'un enlace sin esquema',
    '/api/logout'                       => 'otra ruta de la propia app',
    "nueva-prenda\n"                    => 'el valor bueno con basura detrás',
];
foreach ($intentos as $valor => $why) {
    test('no redirige a donde diga la URL: ' . $why, function () use ($valor) {
        // El parámetro lo escribe quien pide la página, así que la función devuelve una de
        // sus dos rutas y nunca lo que le han pasado.
        same('/', onboarding_next_path($valor));
    });
}

describe('style_profile_update() · un formulario que solo pregunta por una parte');

test('lo que el formulario no menciona se queda como estaba', function () {
    // Es justo el caso de la bienvenida: pregunta por cuatro de los seis campos.
    $result = style_profile_update(
        ['style' => 'Minimalista', 'colors' => 'Tonos tierra'],
        ['style' => 'Clásico', 'sizes' => 'M', 'budget' => 'Hasta 120 €']
    );
    same('Minimalista', $result['style'], 'lo que sí menciona, se actualiza');
    same('M', $result['sizes'], 'lo que no menciona, sobrevive');
    same('Hasta 120 €', $result['budget']);
    same('Tonos tierra', $result['colors']);
});

test('un campo vaciado a propósito sí se borra', function () {
    // Mencionado y vacío no es lo mismo que no mencionado, o no habría forma de limpiarlo.
    $result = style_profile_update(['style' => ''], ['style' => 'Clásico']);
    falsy(isset($result['style']));
});

test('las claves que no son del perfil no entran por ninguna de las dos vías', function () {
    $result = style_profile_update(['inventado' => 'x'], ['tambien_inventado' => 'y']);
    same([], $result);
});

test('el recorte se aplica venga de donde venga', function () {
    $largo = str_repeat('a', STYLE_PROFILE_MAX_LENGTH + 100);
    same(STYLE_PROFILE_MAX_LENGTH, mb_strlen(style_profile_update(['style' => $largo], [])['style']));
    same(STYLE_PROFILE_MAX_LENGTH, mb_strlen(style_profile_update([], ['style' => $largo])['style']));
});

test('un perfil completo que se reenvía entero no cambia', function () {
    $perfil = ['style' => 'Minimalista', 'colors' => 'Verde', 'avoid' => 'Estampados', 'sizes' => 'M', 'budget' => '120 €', 'occasions' => 'Oficina'];
    same($perfil, style_profile_update($perfil, $perfil));
});
