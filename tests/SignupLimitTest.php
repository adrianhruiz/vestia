<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/SignupLimit.php';

/** Writes a signup as though it had happened $minutesAgo minutes ago. */
function seed_signup(PDO $pdo, string $ipHash, string $domain, int $minutesAgo): void {
    $pdo->prepare('INSERT INTO signup_attempts(ip_hash,email_domain,created_at) VALUES(?,?,?)')
        ->execute([$ipHash, $domain, date(DATE_ATOM, time() - $minutesAgo * 60)]);
}

function fresh_attempts(): PDO {
    $pdo = test_pdo();
    test_truncate($pdo, 'signup_attempts');
    return $pdo;
}

describe('migración 010 · esquema');

test('signup_attempts existe con sus índices', function () {
    $pdo = test_pdo();
    same(1, count($pdo->query("SHOW TABLES LIKE 'signup_attempts'")->fetchAll()), 'la tabla existe');
    $indexes = $pdo->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='signup_attempts'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['signup_attempts_ip_idx', 'signup_attempts_domain_idx', 'signup_attempts_created_idx'] as $index) {
        truthy(in_array($index, $indexes, true), "existe el índice {$index}");
    }
});

describe('signup_ip_hash()');

test('la dirección no queda guardada en claro', function () {
    $hash = signup_ip_hash('83.45.12.9');
    falsy(str_contains($hash, '83.45.12.9'), 'el hash no contiene la IP');
    same(64, strlen($hash), 'es un sha256 en hexadecimal');
});

test('la misma IP da el mismo hash y dos distintas no', function () {
    same(signup_ip_hash('83.45.12.9'), signup_ip_hash('83.45.12.9'), 'estable');
    truthy(signup_ip_hash('83.45.12.9') !== signup_ip_hash('83.45.12.10'), 'distingue orígenes');
});

describe('signup_major_domain()');

test('los proveedores de consumo están exentos del límite por dominio', function () {
    foreach (['gmail.com', 'googlemail.com', 'icloud.com', 'proton.me', 'telefonica.net'] as $dominio) {
        truthy(signup_major_domain($dominio), $dominio);
    }
});

test('las variantes de país de Microsoft y Yahoo también', function () {
    foreach (['hotmail.es', 'outlook.es', 'live.com.mx', 'msn.com', 'yahoo.es'] as $dominio) {
        truthy(signup_major_domain($dominio), $dominio);
    }
});

test('un dominio propio no lo está: es justo al que apunta el límite', function () {
    foreach (['midominio.com', 'vestia.com', 'empresa.es'] as $dominio) {
        falsy(signup_major_domain($dominio), $dominio);
    }
});

describe('signup_retry_after() · límite por IP');

test('deja pasar mientras quede hueco en la hora', function () {
    $pdo = fresh_attempts();
    $ip = signup_ip_hash('83.45.12.9');
    for ($i = 0; $i < SIGNUPS_PER_IP_PER_HOUR - 1; $i++) seed_signup($pdo, $ip, 'gmail.com', 5);
    same(null, signup_retry_after($pdo, $ip, 'gmail.com'), 'por debajo del tope');
});

test('corta al alcanzar el tope horario', function () {
    $pdo = fresh_attempts();
    $ip = signup_ip_hash('83.45.12.9');
    for ($i = 0; $i < SIGNUPS_PER_IP_PER_HOUR; $i++) seed_signup($pdo, $ip, 'gmail.com', 5);
    $wait = signup_retry_after($pdo, $ip, 'gmail.com');
    truthy($wait !== null, 'devuelve una espera');
    // La más antigua fue hace 5 minutos, así que su hueco se libera en unos 55.
    truthy($wait > 3200 && $wait <= 3600, "la espera es de ~55 minutos, obtenido {$wait}");
});

test('una IP distinta no hereda el bloqueo', function () {
    $pdo = fresh_attempts();
    $bloqueada = signup_ip_hash('83.45.12.9');
    for ($i = 0; $i < SIGNUPS_PER_IP_PER_HOUR; $i++) seed_signup($pdo, $bloqueada, 'gmail.com', 5);
    same(null, signup_retry_after($pdo, signup_ip_hash('83.45.12.10'), 'gmail.com'), 'otra IP entra');
});

test('lo de hace más de una hora ya no cuenta', function () {
    $pdo = fresh_attempts();
    $ip = signup_ip_hash('83.45.12.9');
    for ($i = 0; $i < SIGNUPS_PER_IP_PER_HOUR; $i++) seed_signup($pdo, $ip, 'gmail.com', 61);
    same(null, signup_retry_after($pdo, $ip, 'gmail.com'), 'la ventana ha pasado');
});

describe('signup_retry_after() · ventana diaria');

test('el goteo lento acaba topando con el límite del día', function () {
    $pdo = fresh_attempts();
    $ip = signup_ip_hash('83.45.12.9');
    // Repartidas a lo largo del día y ninguna en la última hora: solo puede cortar el día.
    for ($i = 1; $i <= SIGNUPS_PER_IP_PER_DAY; $i++) seed_signup($pdo, $ip, 'gmail.com', 90 + $i * 60);
    $wait = signup_retry_after($pdo, $ip, 'gmail.com');
    truthy($wait !== null, 'la ventana diaria corta');
    truthy($wait > 3600, "la espera pasa de una hora, obtenido {$wait}");
});

test('la espera se mide desde la más antigua de la ventana, entre en el orden que entre', function () {
    $pdo = fresh_attempts();
    $ip = signup_ip_hash('83.45.12.9');
    // A propósito: la fila con el id más bajo es la MÁS RECIENTE, así que el orden por id
    // y el del reloj van al revés. recent_signups() lee por id, y sin ordenar por fecha el
    // cálculo tomaría la más nueva por la más antigua y daría horas de más.
    for ($i = 1; $i <= SIGNUPS_PER_IP_PER_DAY; $i++) seed_signup($pdo, $ip, 'gmail.com', 90 + $i * 60);
    $masAntigua = 90 + SIGNUPS_PER_IP_PER_DAY * 60;
    $esperado = 86400 - $masAntigua * 60;
    $wait = signup_retry_after($pdo, $ip, 'gmail.com');
    truthy(abs($wait - $esperado) < 120, "esperado ~{$esperado}s desde la más antigua, obtenido {$wait}");
});

describe('signup_retry_after() · límite por dominio');

test('un dominio catch-all se corta aunque la IP esté limpia', function () {
    $pdo = fresh_attempts();
    // Cinco altas desde cinco orígenes distintos, ninguno el que ahora llama.
    for ($i = 1; $i <= SIGNUPS_PER_DOMAIN_PER_HOUR; $i++) seed_signup($pdo, str_repeat((string) $i, 64), 'midominio.com', 5);
    truthy(signup_retry_after($pdo, signup_ip_hash('83.45.12.9'), 'midominio.com') !== null, 'corta por dominio');
});

test('gmail con el mismo historial pasa: estrangularlo sería un tiro en el pie', function () {
    $pdo = fresh_attempts();
    for ($i = 1; $i <= SIGNUPS_PER_DOMAIN_PER_HOUR * 2; $i++) seed_signup($pdo, str_repeat((string) ($i % 10), 64), 'gmail.com', 5);
    same(null, signup_retry_after($pdo, signup_ip_hash('83.45.12.9'), 'gmail.com'), 'exento');
});

describe('record_signup()');

test('escribe la fila y purga lo que ninguna ventana puede leer', function () {
    $pdo = fresh_attempts();
    seed_signup($pdo, str_repeat('a', 64), 'viejo.com', SIGNUP_ATTEMPT_RETENTION_DAYS * 24 * 60 + 60);
    seed_signup($pdo, str_repeat('b', 64), 'reciente.com', 30);
    record_signup($pdo, signup_ip_hash('83.45.12.9'), 'gmail.com');

    $dominios = $pdo->query('SELECT email_domain FROM signup_attempts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    same(['reciente.com', 'gmail.com'], $dominios, 'la vieja se va, la reciente y la nueva se quedan');
});

test('lo que escribe es lo que el límite luego lee', function () {
    $pdo = fresh_attempts();
    $ip = signup_ip_hash('83.45.12.9');
    for ($i = 0; $i < SIGNUPS_PER_IP_PER_HOUR; $i++) record_signup($pdo, $ip, 'gmail.com');
    truthy(signup_retry_after($pdo, $ip, 'gmail.com') !== null, 'tres altas reales alcanzan el tope');
});
