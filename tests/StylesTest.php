<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/Styles.php';

/**
 * A style somebody names is the one piece of free text in this feature that is stored, shown
 * back and sent to a model, so three things are at stake and each gets its own cases:
 *
 *   it is one line        -- newlines and runs of whitespace cannot survive into a chip, and
 *                            they cannot survive into a prompt either;
 *   it is one style       -- "Biblioteca", "biblioteca" and "BIBLIOTECA" are the same chip,
 *                            and so is "cafe" against the "Café" that ships with the app;
 *   it is theirs          -- no label, id or deletion may cross between two accounts.
 */

function fresh_styles(): PDO {
    $pdo = test_pdo();
    test_truncate($pdo, 'user_styles', 'users');
    return $pdo;
}

function seed_styles_user(PDO $pdo, string $name = 'Adri'): int {
    $pdo->prepare('INSERT INTO users(name,email,email_canonical,password_hash,style_profile,created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$name, 'a' . bin2hex(random_bytes(6)) . '@ejemplo.com', 'c' . bin2hex(random_bytes(6)) . '@ejemplo.com', 'x', '{}', date(DATE_ATOM)]);
    return (int) $pdo->lastInsertId();
}

describe('migración 013 · esquema');

test('la tabla existe', function () {
    $pdo = test_pdo();
    $found = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_styles'")->fetchColumn();
    same(1, (int) $found);
});

test('la misma persona no puede guardar dos veces el mismo estilo', function () {
    // Lo impide la clave única, no el código que llama: dos peticiones a la vez tampoco crean
    // dos chips que se lean igual.
    $pdo = test_pdo();
    $unique = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_styles' AND INDEX_NAME='user_styles_unique'")->fetchColumn();
    same(2, (int) $unique, 'la clave es el par (user_id, canonical)');
});

describe('style_label_clean() · un chip es una línea');

test('se limpia por los extremos', function () {
    same('Biblioteca', style_label_clean("  Biblioteca \n"));
});

test('los saltos de línea y los espacios repetidos se colapsan', function () {
    // Si no, un estilo de dos líneas acabaría dentro del prompt como dos líneas.
    same('Finde en casa', style_label_clean("Finde\n\nen    casa"));
});

test('los caracteres de control desaparecen', function () {
    same('Cafe con leche', style_label_clean("Cafe\tcon\x00leche"));
});

test('se recorta a lo que cabe en un chip', function () {
    same(STYLE_LABEL_MAX_LENGTH, mb_strlen(style_label_clean(str_repeat('a', STYLE_LABEL_MAX_LENGTH + 30))));
});

test('el recorte cuenta caracteres y no bytes', function () {
    // "ñ" ocupa dos bytes: con substr() se habría cortado a la mitad y dejado basura.
    same(STYLE_LABEL_MAX_LENGTH, mb_strlen(style_label_clean(str_repeat('ñ', STYLE_LABEL_MAX_LENGTH + 5))));
});

test('lo que no tiene nada legible se queda vacío', function () {
    same('', style_label_clean("   \n\t "));
});

describe('style_canonical() · qué cuenta como el mismo estilo');

test('las mayúsculas no distinguen', function () {
    same(style_canonical('Biblioteca'), style_canonical('BIBLIOTECA'));
});

test('los acentos tampoco', function () {
    same(style_canonical('Café'), style_canonical('cafe'));
});

test('dos estilos distintos siguen siendo distintos', function () {
    truthy(style_canonical('Biblioteca') !== style_canonical('Terraceo'));
});

test('vacío sigue vacío', function () {
    same('', style_canonical('   '));
});

describe('style_preset_slug() · lo que la app ya sabe hacer');

test('reconoce un preset escrito a mano', function () {
    same('cafe', style_preset_slug('Café'));
    same('cafe', style_preset_slug('cafe'));
    same('deporte', style_preset_slug('  DEPORTE '));
});

test('un estilo propio no es ningún preset', function () {
    same(null, style_preset_slug('Biblioteca'));
});

test('cada preset trae su etiqueta y su frase para el modelo', function () {
    // La frase es la razón de que esto sea un mapa y no una lista de palabras: "Café" a secas
    // es ambiguo, y un modelo que no puede preguntar se lo inventa.
    foreach (STYLE_PRESETS as $slug => $preset) {
        truthy(($preset['label'] ?? '') !== '', "{$slug} tiene etiqueta");
        truthy(($preset['guidance'] ?? '') !== '', "{$slug} tiene frase");
    }
});

describe('user_style_create()');

test('guarda lo que la persona escribió, limpio', function () {
    $pdo = fresh_styles();
    $answer = user_style_create($pdo, seed_styles_user($pdo), '  Biblioteca  ');
    same('Biblioteca', $answer['style']['label']);
    falsy($answer['existing']);
});

test('escribirlo dos veces devuelve el que ya había', function () {
    $pdo = fresh_styles();
    $user = seed_styles_user($pdo);
    $first = user_style_create($pdo, $user, 'Biblioteca');
    $again = user_style_create($pdo, $user, 'BIBLIOTECA');
    same($first['style']['id'], $again['style']['id']);
    truthy($again['existing']);
    same(1, count(user_styles($pdo, $user)), 'sigue habiendo un solo chip');
});

test('escribir un preset no crea nada: señala el preset', function () {
    $pdo = fresh_styles();
    $user = seed_styles_user($pdo);
    same('cafe', user_style_create($pdo, $user, 'cafe')['preset']);
    same([], user_styles($pdo, $user), 'no se ha guardado ninguna fila');
});

test('sin nada legible, error', function () {
    $pdo = fresh_styles();
    same('empty', user_style_create($pdo, seed_styles_user($pdo), "  \n ")['error']);
});

test('al llegar al tope, error', function () {
    $pdo = fresh_styles();
    $user = seed_styles_user($pdo);
    for ($i = 0; $i < USER_STYLES_MAX; $i++) user_style_create($pdo, $user, 'Estilo ' . $i);
    same('full', user_style_create($pdo, $user, 'Uno más')['error']);
});

test('con el tope lleno, un estilo que ya tienes sigue pudiéndose seleccionar', function () {
    // El chip existe: negarse a buscarlo porque la lista está llena sería impedir usar lo que
    // ya está guardado.
    $pdo = fresh_styles();
    $user = seed_styles_user($pdo);
    for ($i = 0; $i < USER_STYLES_MAX; $i++) user_style_create($pdo, $user, 'Estilo ' . $i);
    truthy(user_style_create($pdo, $user, 'Estilo 0')['existing']);
});

test('dos personas pueden llamar igual a su estilo', function () {
    $pdo = fresh_styles();
    $adri = seed_styles_user($pdo); $raul = seed_styles_user($pdo, 'Raúl');
    truthy(isset(user_style_create($pdo, $adri, 'Biblioteca')['style']));
    truthy(isset(user_style_create($pdo, $raul, 'Biblioteca')['style']));
    same(1, count(user_styles($pdo, $adri)));
    same(1, count(user_styles($pdo, $raul)));
});

describe('user_styles() · el orden de los chips');

test('el último usado va primero, y lo nunca usado al final', function () {
    $pdo = fresh_styles();
    $user = seed_styles_user($pdo);
    $primero = user_style_create($pdo, $user, 'Biblioteca')['style']['id'];
    $segundo = user_style_create($pdo, $user, 'Terraceo')['style']['id'];
    user_style_create($pdo, $user, 'Concierto');
    user_style_touch($pdo, $user, $primero);
    // Un segundo de diferencia: last_used_at es ISO-8601 con precisión de segundo, así que sin
    // esperar los dos toques tendrían la misma marca y el orden lo decidiría el id.
    sleep(1);
    user_style_touch($pdo, $user, $segundo);
    same(['Terraceo', 'Biblioteca', 'Concierto'], array_column(user_styles($pdo, $user), 'label'));
});

describe('un estilo es de quien lo guardó');

test('no se lee el estilo de otra cuenta', function () {
    $pdo = fresh_styles();
    $adri = seed_styles_user($pdo); $raul = seed_styles_user($pdo, 'Raúl');
    $suyo = user_style_create($pdo, $raul, 'Biblioteca')['style']['id'];
    same(null, user_style_label($pdo, $adri, $suyo));
    same('Biblioteca', user_style_label($pdo, $raul, $suyo));
});

test('no se borra el estilo de otra cuenta', function () {
    $pdo = fresh_styles();
    $adri = seed_styles_user($pdo); $raul = seed_styles_user($pdo, 'Raúl');
    $suyo = user_style_create($pdo, $raul, 'Biblioteca')['style']['id'];
    falsy(user_style_delete($pdo, $adri, $suyo));
    same(1, count(user_styles($pdo, $raul)), 'sigue estando');
    truthy(user_style_delete($pdo, $raul, $suyo));
});

test('borrar un estilo que no existe no es un borrado', function () {
    $pdo = fresh_styles();
    falsy(user_style_delete($pdo, seed_styles_user($pdo), 99999));
});

test('borrar la cuenta se lleva sus estilos', function () {
    $pdo = fresh_styles();
    $user = seed_styles_user($pdo);
    user_style_create($pdo, $user, 'Biblioteca');
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$user]);
    same(0, (int) $pdo->query('SELECT COUNT(*) FROM user_styles')->fetchColumn());
});
