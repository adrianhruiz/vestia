<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/Wardrobe.php';
require_once APP_ROOT . '/app/Outfits.php';

/**
 * A look is either wearable or it is not, and the answer decides when an account gets its
 * style read. So the rule gets a case per shape it has to get right -- a dress standing in for
 * two garments above all -- and the ids that reach it from a request body get the usual
 * treatment: nothing belonging to anybody else may be joined, read or removed.
 */

function fresh_outfits(): PDO {
    $pdo = test_pdo();
    test_truncate($pdo, 'outfit_garments', 'outfits', 'garments', 'users');
    return $pdo;
}

function seed_outfits_user(PDO $pdo): int {
    $pdo->prepare('INSERT INTO users(name,email,email_canonical,password_hash,style_profile,created_at) VALUES(?,?,?,?,?,?)')
        ->execute(['Adri', 'a' . bin2hex(random_bytes(6)) . '@ejemplo.com', 'c' . bin2hex(random_bytes(6)) . '@ejemplo.com', 'x', '{}', date(DATE_ATOM)]);
    return (int) $pdo->lastInsertId();
}

/** One garment in the wardrobe. Shared with OnboardingTest, which builds looks out of these. */
function seed_outfit_garment(PDO $pdo, int $userId, string $category, string $name = 'Prenda'): int {
    $pdo->prepare('INSERT INTO garments(user_id,name,brand,category,created_at) VALUES(?,?,?,?,?)')
        ->execute([$userId, $name, 'Zara', $category, date(DATE_ATOM)]);
    return (int) $pdo->lastInsertId();
}

/** A look in the shape the rules read, without going near the database. */
function outfit_of(array $categories): array {
    return ['id' => 1, 'name' => 'Outfit', 'garments' => array_map(
        static fn(string $category, int $index): array => ['id' => $index + 1, 'category' => $category],
        $categories,
        array_keys($categories)
    )];
}

describe('migración 012 · esquema');

test('las dos tablas existen', function () {
    $pdo = test_pdo();
    foreach (['outfits', 'outfit_garments'] as $table) {
        $found = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}'")->fetchColumn();
        same(1, (int) $found, "existe {$table}");
    }
});

test('la misma prenda no entra dos veces en el mismo outfit', function () {
    // Es la clave primaria quien lo impide, no el código que llama.
    $pdo = test_pdo();
    $key = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='outfit_garments' AND INDEX_NAME='PRIMARY'")->fetchColumn();
    same(2, (int) $key, 'la clave primaria es el par');
});

test('borrar una prenda la saca de los outfits en los que estaba', function () {
    $pdo = fresh_outfits();
    $id = seed_outfits_user($pdo);
    $outfit = onboarding_outfits($pdo, $id)[0];
    $garment = seed_outfit_garment($pdo, $id, GARMENT_SHOES);
    outfit_add_garment($pdo, $id, (int) $outfit['id'], $garment);
    $pdo->prepare('DELETE FROM garments WHERE id=?')->execute([$garment]);
    same(0, (int) $pdo->query('SELECT COUNT(*) FROM outfit_garments')->fetchColumn(), 'un look no puede apuntar a nada');
});

describe('outfit_missing() · cuándo un look se puede llevar puesto');

test('arriba, abajo y calzado está completo', function () {
    same([], outfit_missing([GARMENT_TOP, GARMENT_BOTTOM, GARMENT_SHOES]));
});

test('un vestido con su calzado también', function () {
    // Un vestido es la mitad de arriba y la de abajo a la vez.
    same([], outfit_missing([GARMENT_DRESS, GARMENT_SHOES]));
});

test('un vestido sin calzado no', function () {
    same([GARMENT_SHOES], outfit_missing([GARMENT_DRESS]));
});

test('un armario vacío le falta todo, en el orden en que se pregunta', function () {
    same([GARMENT_TOP, GARMENT_BOTTOM, GARMENT_SHOES], outfit_missing([]));
});

test('lo que falta se nombra una sola vez aunque sobre de otra cosa', function () {
    same([GARMENT_SHOES], outfit_missing([GARMENT_TOP, GARMENT_TOP, GARMENT_BOTTOM]));
});

test('un abrigo y un accesorio no completan nada y tampoco estorban', function () {
    // Un look no está incompleto por no llevar bufanda, y pedir una convertiría la
    // bienvenida en un formulario.
    same([], outfit_missing([GARMENT_TOP, GARMENT_BOTTOM, GARMENT_SHOES, 'Abrigos', 'Accesorios']));
    same([GARMENT_TOP, GARMENT_BOTTOM, GARMENT_SHOES], outfit_missing(['Abrigos', 'Accesorios']));
});

describe('outfits_ready() · los dos que pide la bienvenida');

test('dos looks completos y con ropa distinta valen', function () {
    truthy(outfits_ready([
        ['id' => 1, 'name' => 'A', 'garments' => [['id' => 1, 'category' => GARMENT_TOP], ['id' => 2, 'category' => GARMENT_BOTTOM], ['id' => 3, 'category' => GARMENT_SHOES]]],
        ['id' => 2, 'name' => 'B', 'garments' => [['id' => 4, 'category' => GARMENT_DRESS], ['id' => 3, 'category' => GARMENT_SHOES]]],
    ]));
});

test('compartir prendas entre los dos está bien', function () {
    // Los mismos vaqueros en dos looks es como viste la gente; prohibirlo sería mentir
    // sobre la ropa.
    truthy(outfits_ready([
        ['id' => 1, 'name' => 'A', 'garments' => [['id' => 1, 'category' => GARMENT_TOP], ['id' => 2, 'category' => GARMENT_BOTTOM], ['id' => 3, 'category' => GARMENT_SHOES]]],
        ['id' => 2, 'name' => 'B', 'garments' => [['id' => 9, 'category' => GARMENT_TOP], ['id' => 2, 'category' => GARMENT_BOTTOM], ['id' => 3, 'category' => GARMENT_SHOES]]],
    ]));
});

test('el mismo look metido dos veces no son dos looks', function () {
    $mismo = [['id' => 1, 'category' => GARMENT_TOP], ['id' => 2, 'category' => GARMENT_BOTTOM], ['id' => 3, 'category' => GARMENT_SHOES]];
    falsy(outfits_ready([['id' => 1, 'name' => 'A', 'garments' => $mismo], ['id' => 2, 'name' => 'B', 'garments' => array_reverse($mismo)]]));
});

test('con uno incompleto no está lista', function () {
    falsy(outfits_ready([outfit_of([GARMENT_TOP, GARMENT_BOTTOM, GARMENT_SHOES]), outfit_of([GARMENT_TOP])]));
});

test('con un solo outfit tampoco', function () {
    falsy(outfits_ready([outfit_of([GARMENT_TOP, GARMENT_BOTTOM, GARMENT_SHOES])]));
});

describe('outfits_hint() · lo único que los huecos vacíos no explican');

test('mientras falte ropa, los huecos ya lo dicen', function () {
    same(null, outfits_hint([outfit_of([GARMENT_TOP]), outfit_of([])]));
});

test('dos looks idénticos se explican con una frase', function () {
    $mismo = [['id' => 1, 'category' => GARMENT_TOP], ['id' => 2, 'category' => GARMENT_BOTTOM], ['id' => 3, 'category' => GARMENT_SHOES]];
    $hint = outfits_hint([['id' => 1, 'name' => 'A', 'garments' => $mismo], ['id' => 2, 'name' => 'B', 'garments' => $mismo]]);
    truthy($hint !== null && str_contains($hint, 'misma ropa'));
});

test('cuando está lista no hay nada que explicar', function () {
    same(null, outfits_hint([
        ['id' => 1, 'name' => 'A', 'garments' => [['id' => 1, 'category' => GARMENT_TOP], ['id' => 2, 'category' => GARMENT_BOTTOM], ['id' => 3, 'category' => GARMENT_SHOES]]],
        ['id' => 2, 'name' => 'B', 'garments' => [['id' => 4, 'category' => GARMENT_DRESS], ['id' => 3, 'category' => GARMENT_SHOES]]],
    ]));
});

describe('onboarding_outfits() · los dos que estrena una cuenta');

test('los crea la primera vez y no los duplica en la segunda', function () {
    $pdo = fresh_outfits();
    $id = seed_outfits_user($pdo);
    same(ONBOARDING_OUTFITS, count(onboarding_outfits($pdo, $id)));
    same(ONBOARDING_OUTFITS, count(onboarding_outfits($pdo, $id)));
    same(ONBOARDING_OUTFITS, (int) $pdo->query("SELECT COUNT(*) FROM outfits WHERE user_id={$id}")->fetchColumn());
});

test('quien ya tiene looks de sobra no estrena ninguno', function () {
    $pdo = fresh_outfits();
    $id = seed_outfits_user($pdo);
    onboarding_outfits($pdo, $id);
    $pdo->prepare('INSERT INTO outfits(user_id,name,created_at) VALUES(?,?,?)')->execute([$id, 'Boda de Ana', date(DATE_ATOM)]);
    same(3, count(onboarding_outfits($pdo, $id)));
});

test('cada cuenta estrena los suyos', function () {
    $pdo = fresh_outfits();
    $mio = seed_outfits_user($pdo);
    $suyo = seed_outfits_user($pdo);
    onboarding_outfits($pdo, $mio);
    same(0, (int) $pdo->query("SELECT COUNT(*) FROM outfits WHERE user_id={$suyo}")->fetchColumn());
});

describe('outfits_of_user()');

test('un look vacío también vuelve: es el caso de una cuenta recién creada', function () {
    $pdo = fresh_outfits();
    $id = seed_outfits_user($pdo);
    onboarding_outfits($pdo, $id);
    $outfits = outfits_of_user($pdo, $id);
    same(ONBOARDING_OUTFITS, count($outfits));
    same([], $outfits[0]['garments']);
});

test('las prendas llegan con lo que el estilista necesita de ellas', function () {
    $pdo = fresh_outfits();
    $id = seed_outfits_user($pdo);
    $outfit = onboarding_outfits($pdo, $id)[0];
    outfit_add_garment($pdo, $id, (int) $outfit['id'], seed_outfit_garment($pdo, $id, GARMENT_TOP, 'Camisa fluida'));
    $garment = outfits_of_user($pdo, $id)[0]['garments'][0];
    same('Camisa fluida', $garment['name']);
    same(GARMENT_TOP, $garment['category']);
    truthy(array_key_exists('thumb_path', $garment), 'la miniatura viaja: el panel la puede pintar');
});

test('no devuelve los looks de otra cuenta', function () {
    $pdo = fresh_outfits();
    $mio = seed_outfits_user($pdo);
    onboarding_outfits($pdo, seed_outfits_user($pdo));
    same([], outfits_of_user($pdo, $mio));
});

describe('outfit_add_garment() · ids que vienen de una petición');

test('mete la prenda en el look', function () {
    $pdo = fresh_outfits();
    $id = seed_outfits_user($pdo);
    $outfit = (int) onboarding_outfits($pdo, $id)[0]['id'];
    truthy(outfit_add_garment($pdo, $id, $outfit, seed_outfit_garment($pdo, $id, GARMENT_SHOES)));
    same(1, count(outfits_of_user($pdo, $id)[0]['garments']));
});

test('meterla dos veces no es un error y no la duplica', function () {
    $pdo = fresh_outfits();
    $id = seed_outfits_user($pdo);
    $outfit = (int) onboarding_outfits($pdo, $id)[0]['id'];
    $garment = seed_outfit_garment($pdo, $id, GARMENT_SHOES);
    outfit_add_garment($pdo, $id, $outfit, $garment);
    truthy(outfit_add_garment($pdo, $id, $outfit, $garment));
    same(1, count(outfits_of_user($pdo, $id)[0]['garments']));
});

test('no mete una prenda ajena en mi look', function () {
    $pdo = fresh_outfits();
    $mio = seed_outfits_user($pdo);
    $suyo = seed_outfits_user($pdo);
    $outfit = (int) onboarding_outfits($pdo, $mio)[0]['id'];
    falsy(outfit_add_garment($pdo, $mio, $outfit, seed_outfit_garment($pdo, $suyo, GARMENT_SHOES)));
    same(0, (int) $pdo->query('SELECT COUNT(*) FROM outfit_garments')->fetchColumn());
});

test('no mete mi prenda en el look de otra persona', function () {
    $pdo = fresh_outfits();
    $mio = seed_outfits_user($pdo);
    $suyo = seed_outfits_user($pdo);
    $ajeno = (int) onboarding_outfits($pdo, $suyo)[0]['id'];
    falsy(outfit_add_garment($pdo, $mio, $ajeno, seed_outfit_garment($pdo, $mio, GARMENT_SHOES)));
    same(0, (int) $pdo->query('SELECT COUNT(*) FROM outfit_garments')->fetchColumn());
});

test('un id que no existe no entra', function () {
    $pdo = fresh_outfits();
    $id = seed_outfits_user($pdo);
    falsy(outfit_add_garment($pdo, $id, (int) onboarding_outfits($pdo, $id)[0]['id'], 999999));
});

describe('outfit_remove_garment()');

test('la saca del look y la deja en el armario', function () {
    $pdo = fresh_outfits();
    $id = seed_outfits_user($pdo);
    $outfit = (int) onboarding_outfits($pdo, $id)[0]['id'];
    $garment = seed_outfit_garment($pdo, $id, GARMENT_SHOES);
    outfit_add_garment($pdo, $id, $outfit, $garment);
    truthy(outfit_remove_garment($pdo, $id, $outfit, $garment));
    same([], outfits_of_user($pdo, $id)[0]['garments']);
    same(1, (int) $pdo->query("SELECT COUNT(*) FROM garments WHERE id={$garment}")->fetchColumn(), 'quitarla de un look no es tirarla');
});

test('no saca nada del look de otra persona', function () {
    $pdo = fresh_outfits();
    $mio = seed_outfits_user($pdo);
    $suyo = seed_outfits_user($pdo);
    $ajeno = (int) onboarding_outfits($pdo, $suyo)[0]['id'];
    $garment = seed_outfit_garment($pdo, $suyo, GARMENT_SHOES);
    outfit_add_garment($pdo, $suyo, $ajeno, $garment);
    falsy(outfit_remove_garment($pdo, $mio, $ajeno, $garment));
    same(1, (int) $pdo->query('SELECT COUNT(*) FROM outfit_garments')->fetchColumn());
});

test('quitar lo que no estaba dentro no es un éxito', function () {
    $pdo = fresh_outfits();
    $id = seed_outfits_user($pdo);
    falsy(outfit_remove_garment($pdo, $id, (int) onboarding_outfits($pdo, $id)[0]['id'], seed_outfit_garment($pdo, $id, GARMENT_SHOES)));
});
