<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/Wardrobe.php';
require_once APP_ROOT . '/app/Outfits.php';
require_once APP_ROOT . '/app/Analysis.php';
require_once APP_ROOT . '/app/StyleReading.php';

/**
 * The reading used to take photographs of people, and most of this file used to be about the
 * promises made on their behalf: unreachable by name, deleted when deleting them was
 * promised, stripped of everything the camera wrote. None of that exists any more, because
 * nothing is uploaded: the input is two outfits out of the person's own wardrobe.
 *
 * What is left to protect is narrower and still worth a test each. That the snapshot sent to
 * the model carries the clothes and nothing else. That a field the stylist could not answer
 * arrives at the form empty rather than invented. And that one account cannot ask for
 * readings all day.
 */

/** Writes a reading as though it had happened $hoursAgo hours ago. */
function seed_style_reading(PDO $pdo, int $userId, int $hoursAgo, string $status = 'completed', ?string $result = null): int {
    $pdo->prepare('INSERT INTO jobs(user_id,type,status,input_json,result_json,created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$userId, 'style_reading', $status, '{}', $result, date(DATE_ATOM, time() - $hoursAgo * 3600)]);
    return (int) $pdo->lastInsertId();
}

/** An account to hang jobs off, since jobs.user_id is a foreign key. */
function seed_style_user(PDO $pdo, bool $isTest = false): int {
    $pdo->prepare('INSERT INTO users(name,email,email_canonical,password_hash,style_profile,is_test,created_at) VALUES(?,?,?,?,?,?,?)')
        ->execute(['Adri', 'a' . bin2hex(random_bytes(6)) . '@ejemplo.com', 'c' . bin2hex(random_bytes(6)) . '@ejemplo.com', 'x', '{}', $isTest ? 1 : 0, date(DATE_ATOM)]);
    return (int) $pdo->lastInsertId();
}

function fresh_style_jobs(): PDO {
    $pdo = test_pdo();
    test_truncate($pdo, 'jobs', 'users');
    return $pdo;
}

/** One look, in the shape outfits_of_user() hands over. */
function style_test_outfit(string $name, array $garments): array {
    return ['id' => 1, 'name' => $name, 'garments' => $garments];
}

describe('style_reading_input() · el retrato que se le envía al estilista');

test('viaja la ropa y nada más', function () {
    $input = style_reading_input([
        style_test_outfit('Outfit 1', [[
            'id' => 7, 'name' => 'Camisa fluida', 'brand' => 'Zara', 'category' => GARMENT_TOP,
            'color' => 'Crudo', 'size' => 'M', 'season' => 'Todo el año', 'notes' => 'Lino',
            'image_path' => '/uploads/x.webp', 'thumb_path' => '/uploads/x-thumb.webp',
        ]]),
    ]);
    $garment = $input['outfits'][0]['garments'][0];
    same(['name', 'brand', 'category', 'color', 'size', 'season', 'notes'], array_keys($garment), 'ni ids ni rutas de imagen');
    same('Outfit 1', $input['outfits'][0]['name']);
});

test('los campos vacíos no llegan como claves', function () {
    // Un color vacío es una clave y un null que el modelo tiene que leer para nada.
    $input = style_reading_input([style_test_outfit('Outfit 1', [['name' => 'Vaqueros', 'category' => GARMENT_BOTTOM, 'color' => '', 'notes' => '   ']])]);
    same(['name', 'category'], array_keys($input['outfits'][0]['garments'][0]));
});

test('las notas se recortan a lo que se paga por ellas', function () {
    $input = style_reading_input([style_test_outfit('Outfit 1', [['name' => 'x', 'notes' => str_repeat('a', STYLE_READING_NOTES_MAX_LENGTH + 80)]])]);
    same(STYLE_READING_NOTES_MAX_LENGTH, mb_strlen($input['outfits'][0]['garments'][0]['notes']));
});

test('un outfit sin prendas no se envía vacío', function () {
    $input = style_reading_input([style_test_outfit('Outfit 1', []), style_test_outfit('Outfit 2', [['name' => 'Botas']])]);
    same(1, count($input['outfits']));
    same('Outfit 2', $input['outfits'][0]['name']);
});

test('nunca se envían más de los dos que pide la bienvenida', function () {
    // Cada uno se paga en tokens de entrada, y readStyle() está escrito alrededor de dos.
    $muchos = array_fill(0, 6, style_test_outfit('Outfit', [['name' => 'Camisa']]));
    same(ONBOARDING_OUTFITS, count(style_reading_input($muchos)['outfits']));
});

describe('style_reading_result() · lo que se guarda del veredicto');

test('se queda con las claves del perfil y con el resumen', function () {
    $result = style_reading_result([
        'summary' => 'Vistes sobrio y cómodo.',
        'style' => 'Minimalista',
        'colors' => 'Tonos tierra',
        'occasions' => 'Oficina',
        'avoid' => 'Estampados',
        'sizes' => 'M',
        'inventado' => 'no deberia llegar',
    ]);
    same(['summary', 'style', 'colors', 'occasions'], array_keys($result), 'ni sizes ni claves de fuera');
    same('Vistes sobrio y cómodo.', $result['summary']);
});

test('avoid no se acepta ni cuando el modelo lo manda', function () {
    // Que algo no salga en dos outfits no es prueba de que no guste, y avoid es el campo que
    // decide lo que el estilista no volverá a proponer.
    falsy(isset(style_reading_result(['style' => 'Casual', 'avoid' => 'Estampados'])['avoid']));
});

test('un campo que el estilista no pudo deducir no llega relleno', function () {
    // Es la mitad del diseño: el formulario tiene que quedarse vacío ahí, no con un invento.
    $result = style_reading_result(['summary' => 'x', 'style' => 'Casual', 'colors' => '', 'occasions' => '']);
    falsy(isset($result['colors']), 'colors vacío no se guarda');
    falsy(isset($result['occasions']));
});

test('sin resumen no se inventa la clave', function () {
    falsy(isset(style_reading_result(['style' => 'Casual'])['summary']));
});

test('el resumen se recorta como cualquier otro texto guardado', function () {
    same(STYLE_PROFILE_MAX_LENGTH, mb_strlen(style_reading_result(['summary' => str_repeat('a', STYLE_PROFILE_MAX_LENGTH + 100)])['summary']));
});

describe('latest_style_reading() · de dónde se reanuda el panel');

test('sin lecturas no hay nada que reanudar', function () {
    $pdo = fresh_style_jobs();
    same(null, latest_style_reading($pdo, seed_style_user($pdo)));
});

test('devuelve la última y con el resultado ya decodificado', function () {
    $pdo = fresh_style_jobs();
    $id = seed_style_user($pdo);
    seed_style_reading($pdo, $id, 3, 'failed');
    $ultima = seed_style_reading($pdo, $id, 1, 'completed', '{"summary":"Vistes sobrio."}');
    $reading = latest_style_reading($pdo, $id);
    same($ultima, $reading['id']);
    same('Vistes sobrio.', $reading['result']['summary']);
    falsy(isset($reading['result_json']), 'el JSON crudo no viaja al navegador');
});

test('no devuelve la lectura de otra cuenta', function () {
    $pdo = fresh_style_jobs();
    $mio = seed_style_user($pdo);
    seed_style_reading($pdo, seed_style_user($pdo), 1);
    same(null, latest_style_reading($pdo, $mio));
});

describe('style_reading_queue()');

test('encola la lectura con la instantánea de los outfits', function () {
    $pdo = fresh_style_jobs();
    $id = seed_style_user($pdo);
    $jobId = style_reading_queue($pdo, $id, [style_test_outfit('Outfit 1', [['name' => 'Camisa fluida', 'category' => GARMENT_TOP]])]);
    truthy($jobId > 0, 'devuelve el id de la fila que acaba de escribir');
    $stmt = $pdo->prepare('SELECT type,status,input_json FROM jobs WHERE id=?');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    same('style_reading', $job['type']);
    same('queued', $job['status']);
    same('Camisa fluida', json_decode($job['input_json'], true)['outfits'][0]['garments'][0]['name']);
});

describe('style_reading_retry_after() · lecturas por día');

test('una cuenta nueva puede pedir una lectura', function () {
    $pdo = fresh_style_jobs();
    $id = seed_style_user($pdo);
    same(null, style_reading_retry_after($pdo, ['id' => $id]));
});

test('por debajo del límite sigue pudiendo', function () {
    $pdo = fresh_style_jobs();
    $id = seed_style_user($pdo);
    seed_style_reading($pdo, $id, 1);
    same(null, style_reading_retry_after($pdo, ['id' => $id]));
});

test('en el límite espera a que caduque la más antigua', function () {
    $pdo = fresh_style_jobs();
    $id = seed_style_user($pdo);
    seed_style_reading($pdo, $id, 2);
    seed_style_reading($pdo, $id, 1);
    $wait = style_reading_retry_after($pdo, ['id' => $id]);
    truthy($wait !== null, 'hay espera');
    // La de hace dos horas es la que libera el hueco: 24 h - 2 h.
    truthy($wait > 21.5 * 3600 && $wait <= 22 * 3600, 'la cuenta atrás sale de la más antigua, obtenido ' . var_export($wait, true));
});

test('fuera de la ventana de un día ya no cuentan', function () {
    $pdo = fresh_style_jobs();
    $id = seed_style_user($pdo);
    seed_style_reading($pdo, $id, 25);
    seed_style_reading($pdo, $id, 26);
    same(null, style_reading_retry_after($pdo, ['id' => $id]));
});

test('las lecturas fallidas no gastan cupo', function () {
    // La persona no se llevó nada, y cobrárselas la dejaría sin poder reintentar.
    $pdo = fresh_style_jobs();
    $id = seed_style_user($pdo);
    seed_style_reading($pdo, $id, 1, 'failed');
    seed_style_reading($pdo, $id, 1, 'failed');
    same(null, style_reading_retry_after($pdo, ['id' => $id]));
});

test('las lecturas de otra cuenta tampoco', function () {
    $pdo = fresh_style_jobs();
    $mio = seed_style_user($pdo);
    $suyo = seed_style_user($pdo);
    seed_style_reading($pdo, $suyo, 1);
    seed_style_reading($pdo, $suyo, 1);
    same(null, style_reading_retry_after($pdo, ['id' => $mio]));
});

test('una cuenta de pruebas está exenta, como en los análisis', function () {
    $pdo = fresh_style_jobs();
    $id = seed_style_user($pdo, true);
    seed_style_reading($pdo, $id, 1);
    seed_style_reading($pdo, $id, 1);
    same(null, style_reading_retry_after($pdo, ['id' => $id, 'is_test' => 1]));
});

test('un análisis del armario no gasta cupo de lecturas', function () {
    $pdo = fresh_style_jobs();
    $id = seed_style_user($pdo);
    $pdo->prepare('INSERT INTO jobs(user_id,type,status,input_json,created_at) VALUES(?,?,?,?,?)')
        ->execute([$id, 'wardrobe_analysis', 'completed', '{}', date(DATE_ATOM)]);
    same(null, style_reading_retry_after($pdo, ['id' => $id]));
});
