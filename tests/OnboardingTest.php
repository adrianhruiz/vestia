<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/Wardrobe.php';
require_once APP_ROOT . '/app/Outfits.php';
require_once APP_ROOT . '/app/Analysis.php';
require_once APP_ROOT . '/app/StyleReading.php';
require_once APP_ROOT . '/app/Onboarding.php';

/**
 * The welcome is one flag and one decision, and the decision is the expensive one: finishing
 * the second outfit starts a model call that nobody pressed a button for. So what is tested
 * here is mostly when it must *not* happen -- twice, on an account that is already past the
 * welcome, or over the daily limit.
 *
 * The other thing worth guarding is the profile form, which only asks about part of the
 * profile and must not wipe the part it never showed.
 */

function seed_onboarding_user(PDO $pdo, string $profile = '{}'): int {
    $pdo->prepare('INSERT INTO users(name,email,email_canonical,password_hash,style_profile,created_at) VALUES(?,?,?,?,?,?)')
        ->execute(['Adri', 'a' . bin2hex(random_bytes(6)) . '@ejemplo.com', 'c' . bin2hex(random_bytes(6)) . '@ejemplo.com', 'x', $profile, date(DATE_ATOM)]);
    return (int) $pdo->lastInsertId();
}

function fresh_onboarding(): PDO {
    $pdo = test_pdo();
    test_truncate($pdo, 'outfit_garments', 'outfits', 'jobs', 'garments', 'users');
    return $pdo;
}

/** The user row onboarding_advance() is given, read back the way the endpoints read it. */
function onboarding_test_user(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT id,email,is_test,onboarding_done_at FROM users WHERE id=?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: [];
}

/**
 * One garment in the wardrobe. Its own copy rather than OutfitsTest's: every file here has to
 * run on its own, which is what `php tests/run.php Onboarding` does.
 */
function seed_onboarding_garment(PDO $pdo, int $userId, string $category, string $name): int {
    $pdo->prepare('INSERT INTO garments(user_id,name,brand,category,created_at) VALUES(?,?,?,?,?)')
        ->execute([$userId, $name, 'Zara', $category, date(DATE_ATOM)]);
    return (int) $pdo->lastInsertId();
}

/** Two complete, different looks: exactly what the welcome asks for. */
function seed_two_looks(PDO $pdo, int $userId): void {
    foreach (onboarding_outfits($pdo, $userId) as $index => $outfit) {
        foreach ([GARMENT_TOP, GARMENT_BOTTOM, GARMENT_SHOES] as $category) {
            outfit_add_garment($pdo, $userId, (int) $outfit['id'], seed_onboarding_garment($pdo, $userId, $category, $category . ' ' . $index));
        }
    }
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
    // Preguntarlo sin sesión no puede reventar: la página lo hace en cada petición.
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
    falsy(onboarding_pending(onboarding_test_user($pdo, $id)));
});

test('no marca la cuenta de otra persona', function () {
    $pdo = fresh_onboarding();
    $mio = seed_onboarding_user($pdo);
    $suyo = seed_onboarding_user($pdo);
    onboarding_done($pdo, $mio);
    same(null, $pdo->query("SELECT onboarding_done_at FROM users WHERE id={$suyo}")->fetchColumn());
});

describe('onboarding_state() · lo que el panel recibe para pintarse');

test('una cuenta nueva estrena sus dos outfits vacíos', function () {
    $pdo = fresh_onboarding();
    $state = onboarding_state($pdo, seed_onboarding_user($pdo));
    same(ONBOARDING_OUTFITS, count($state['outfits']));
    same(['Outfit 1', 'Outfit 2'], array_column($state['outfits'], 'name'));
    falsy($state['ready']);
    same(null, $state['reading']);
});

test('cada outfit viene con lo que le falta ya calculado', function () {
    // La regla de cuándo un look se puede llevar puesto vive en outfit_missing() y el
    // navegador solo pinta lo que le dicen.
    $pdo = fresh_onboarding();
    $id = seed_onboarding_user($pdo);
    $state = onboarding_state($pdo, $id);
    same([GARMENT_TOP, GARMENT_BOTTOM, GARMENT_SHOES], $state['outfits'][0]['missing']);
    falsy($state['outfits'][0]['complete']);
});

test('el armario viaja completo, no filtrado', function () {
    // El <select> de un hueco no puede quedarse sin los pantalones porque alguien haya
    // escrito en el buscador.
    $pdo = fresh_onboarding();
    $id = seed_onboarding_user($pdo);
    seed_onboarding_garment($pdo, $id, GARMENT_SHOES, 'Botas');
    $state = onboarding_state($pdo, $id);
    same(1, count($state['wardrobe']));
    same(['id', 'name', 'category'], array_keys($state['wardrobe'][0]), 'solo lo que un hueco muestra');
});

test('con los dos montados está lista y sin pista que dar', function () {
    $pdo = fresh_onboarding();
    $id = seed_onboarding_user($pdo);
    seed_two_looks($pdo, $id);
    $state = onboarding_state($pdo, $id);
    truthy($state['ready']);
    same(null, $state['hint']);
});

describe('onboarding_advance() · cuándo arranca sola la lectura');

test('completar el segundo outfit la encola', function () {
    $pdo = fresh_onboarding();
    $id = seed_onboarding_user($pdo);
    seed_two_looks($pdo, $id);
    $state = onboarding_advance($pdo, onboarding_test_user($pdo, $id));
    truthy($state['reading'] !== null, 'hay lectura en marcha');
    same('queued', $state['reading']['status']);
});

test('no encola una segunda por volver a pasar por aquí', function () {
    $pdo = fresh_onboarding();
    $id = seed_onboarding_user($pdo);
    seed_two_looks($pdo, $id);
    $primera = onboarding_advance($pdo, onboarding_test_user($pdo, $id))['reading']['id'];
    $segunda = onboarding_advance($pdo, onboarding_test_user($pdo, $id))['reading']['id'];
    same($primera, $segunda, 'la misma fila');
    same(1, (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE user_id={$id}")->fetchColumn());
});

test('con un solo outfit completo no encola nada', function () {
    $pdo = fresh_onboarding();
    $id = seed_onboarding_user($pdo);
    $outfit = onboarding_outfits($pdo, $id)[0];
    foreach ([GARMENT_TOP, GARMENT_BOTTOM, GARMENT_SHOES] as $category) {
        outfit_add_garment($pdo, $id, (int) $outfit['id'], seed_onboarding_garment($pdo, $id, $category, $category));
    }
    same(null, onboarding_advance($pdo, onboarding_test_user($pdo, $id))['reading']);
});

test('una cuenta que ya pasó la bienvenida no paga una lectura sin pedirla', function () {
    $pdo = fresh_onboarding();
    $id = seed_onboarding_user($pdo);
    seed_two_looks($pdo, $id);
    onboarding_done($pdo, $id);
    same(null, onboarding_advance($pdo, onboarding_test_user($pdo, $id))['reading']);
});

test('no la encola una lectura fallida: eso lo pide la persona', function () {
    $pdo = fresh_onboarding();
    $id = seed_onboarding_user($pdo);
    seed_two_looks($pdo, $id);
    $pdo->prepare("INSERT INTO jobs(user_id,type,status,input_json,error,created_at) VALUES(?,'style_reading','failed','{}','vaya',?)")
        ->execute([$id, date(DATE_ATOM)]);
    $state = onboarding_advance($pdo, onboarding_test_user($pdo, $id));
    same('failed', $state['reading']['status'], 'sigue siendo la fallida');
    same(1, (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE user_id={$id}")->fetchColumn());
});

test('por encima del límite diario no encola nada', function () {
    $pdo = fresh_onboarding();
    $id = seed_onboarding_user($pdo);
    seed_two_looks($pdo, $id);
    for ($i = 0; $i < STYLE_READINGS_PER_DAY; $i++) {
        $pdo->prepare("INSERT INTO jobs(user_id,type,status,input_json,created_at) VALUES(?,'style_reading','completed','{}',?)")
            ->execute([$id, date(DATE_ATOM)]);
    }
    $antes = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE user_id={$id}")->fetchColumn();
    onboarding_advance($pdo, onboarding_test_user($pdo, $id));
    same($antes, (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE user_id={$id}")->fetchColumn());
});

describe('style_profile_update() · un formulario que solo pregunta por una parte');

test('lo que el formulario no menciona se queda como estaba', function () {
    // Es justo el caso del veredicto: pregunta solo por una parte del perfil.
    $result = style_profile_update(
        ['style' => 'Minimalista', 'colors' => 'Tonos tierra'],
        ['style' => 'Clásico', 'occasions' => 'Oficina', 'avoid' => 'Estampados']
    );
    same('Minimalista', $result['style'], 'lo que sí menciona, se actualiza');
    same('Oficina', $result['occasions'], 'lo que no menciona, sobrevive');
    same('Estampados', $result['avoid']);
    same('Tonos tierra', $result['colors']);
});

test('un campo vaciado a propósito sí se borra', function () {
    // Mencionado y vacío no es lo mismo que no mencionado, o no habría forma de limpiarlo.
    $result = style_profile_update(['style' => ''], ['style' => 'Clásico']);
    falsy(isset($result['style']));
});

test('las claves que no son del perfil no entran por ninguna de las dos vías', function () {
    // "finish" viaja en ese mismo cuerpo y no es una preferencia de estilo.
    $result = style_profile_update(['inventado' => 'x', 'finish' => true], ['tambien_inventado' => 'y']);
    same([], $result);
});

test('el recorte se aplica venga de donde venga', function () {
    $largo = str_repeat('a', STYLE_PROFILE_MAX_LENGTH + 100);
    same(STYLE_PROFILE_MAX_LENGTH, mb_strlen(style_profile_update(['style' => $largo], [])['style']));
    same(STYLE_PROFILE_MAX_LENGTH, mb_strlen(style_profile_update([], ['style' => $largo])['style']));
});

test('un perfil completo que se reenvía entero no cambia', function () {
    $perfil = ['style' => 'Minimalista', 'colors' => 'Verde', 'avoid' => 'Estampados', 'occasions' => 'Oficina'];
    same($perfil, style_profile_update($perfil, $perfil));
});
