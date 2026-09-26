<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/Wardrobe.php';

/**
 * The suggestion has to be right far more often than it is wrong, because it arrives already
 * typed into the box: a stale one is worse than none at all. So what these cases pin down is
 * which garment wins, and that nothing from another wardrobe ever does.
 */

function seed_wardrobe_user(PDO $pdo): int {
    $pdo->prepare('INSERT INTO users(name,email,email_canonical,password_hash,style_profile,created_at) VALUES(?,?,?,?,?,?)')
        ->execute(['Adri', 'a' . bin2hex(random_bytes(6)) . '@ejemplo.com', 'c' . bin2hex(random_bytes(6)) . '@ejemplo.com', 'x', '{}', date(DATE_ATOM)]);
    return (int) $pdo->lastInsertId();
}

function seed_garment(PDO $pdo, int $userId, string $category, ?string $size): void {
    $pdo->prepare('INSERT INTO garments(user_id,name,brand,category,size,created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$userId, 'Prenda', 'Zara', $category, $size, date(DATE_ATOM)]);
}

function fresh_wardrobe(): PDO {
    $pdo = test_pdo();
    test_truncate($pdo, 'garments', 'users');
    return $pdo;
}

describe('remembered_sizes()');

test('un armario vacío no sugiere nada', function () {
    $pdo = fresh_wardrobe();
    same([], remembered_sizes($pdo, seed_wardrobe_user($pdo)));
});

test('cada categoría recuerda la suya', function () {
    $pdo = fresh_wardrobe();
    $id = seed_wardrobe_user($pdo);
    seed_garment($pdo, $id, 'Partes de arriba', 'M');
    seed_garment($pdo, $id, 'Partes de abajo', '40');
    seed_garment($pdo, $id, 'Calzado', '43');
    same(['Partes de arriba' => 'M', 'Partes de abajo' => '40', 'Calzado' => '43'], remembered_sizes($pdo, $id));
});

test('gana la última prenda de esa categoría', function () {
    // Es lo que hace que sirva de algo: si alguien cambia de talla, la siguiente prenda ya
    // sale con la nueva sin tener que ir a editar un perfil.
    $pdo = fresh_wardrobe();
    $id = seed_wardrobe_user($pdo);
    seed_garment($pdo, $id, 'Partes de arriba', 'S');
    seed_garment($pdo, $id, 'Partes de arriba', 'M');
    same('M', remembered_sizes($pdo, $id)['Partes de arriba']);
});

test('una prenda sin talla no borra lo que ya se sabía', function () {
    $pdo = fresh_wardrobe();
    $id = seed_wardrobe_user($pdo);
    seed_garment($pdo, $id, 'Partes de arriba', 'M');
    seed_garment($pdo, $id, 'Partes de arriba', '');
    seed_garment($pdo, $id, 'Partes de arriba', null);
    same('M', remembered_sizes($pdo, $id)['Partes de arriba'], 'la última con talla sigue mandando');
});

test('una categoría sin ninguna talla no aparece', function () {
    // Ausente y no vacía: una sugerencia vacía no es una sugerencia, y el formulario tiene
    // algo mejor que decir en ese hueco.
    $pdo = fresh_wardrobe();
    $id = seed_wardrobe_user($pdo);
    seed_garment($pdo, $id, 'Accesorios', null);
    falsy(array_key_exists('Accesorios', remembered_sizes($pdo, $id)));
});

test('no se cuela el armario de otra persona', function () {
    $pdo = fresh_wardrobe();
    $mio = seed_wardrobe_user($pdo);
    $suyo = seed_wardrobe_user($pdo);
    seed_garment($pdo, $suyo, 'Partes de arriba', 'XXL');
    same([], remembered_sizes($pdo, $mio));
});

describe('el perfil ya no guarda tallas');

test('una talla enviada al perfil se descarta', function () {
    // El campo se ha ido del formulario; esto es lo que impide que vuelva por la API.
    falsy(isset(style_profile(['style' => 'Minimalista', 'sizes' => 'M'])['sizes']));
});

test('y tampoco sobrevive en un perfil ya guardado', function () {
    falsy(isset(style_profile_update([], ['sizes' => 'M', 'style' => 'Minimalista'])['sizes']));
});
