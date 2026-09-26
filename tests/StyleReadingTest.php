<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/Images.php';
require_once APP_ROOT . '/app/Analysis.php';
require_once APP_ROOT . '/app/StyleReading.php';

/**
 * These photos are of people, so most of what follows is not about images at all: it is
 * about the promises the screen makes on their behalf. That they are never reachable by
 * name from outside, that deleting them deletes them, that nothing of the original file
 * survives into the copy we send away, and that one account cannot ask for readings all day.
 */

/** A real image on disk, in $format, filled with a flat colour. */
function style_test_image(int $width, int $height, string $format = 'jpeg'): string {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
    $file = (string) tempnam(sys_get_temp_dir(), 'styletest');
    $format === 'png' ? imagepng($image, $file) : imagejpeg($image, $file);
    imagedestroy($image);
    return $file;
}

/** A JPEG carrying an APP1 segment, which is where a camera writes its EXIF and its GPS. */
function style_test_jpeg_with_exif(string $marker): string {
    $file = style_test_image(60, 40);
    $bytes = (string) file_get_contents($file);
    $payload = "Exif\0\0" . $marker;
    $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    file_put_contents($file, substr($bytes, 0, 2) . $segment . substr($bytes, 2));
    return $file;
}

/** Stores $source as one of $userId's photos and returns [name, absolute path]. */
function style_test_store(string $source, int $userId = 999001): array {
    $name = style_photo_name($userId);
    store_style_photo($source, (string) style_photo_format($source), style_photo_dir(), $name);
    return [$name, style_photo_dir() . '/' . $name];
}

/** Writes a finished reading as though it had happened $hoursAgo hours ago. */
function seed_style_reading(PDO $pdo, int $userId, int $hoursAgo, string $status = 'completed'): void {
    $pdo->prepare('INSERT INTO jobs(user_id,type,status,input_json,created_at) VALUES(?,?,?,?,?)')
        ->execute([$userId, 'style_reading', $status, '{}', date(DATE_ATOM, time() - $hoursAgo * 3600)]);
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

describe('store_style_photo() · la copia que se envía');

test('una foto grande se reduce al lado máximo', function () {
    $source = style_test_image(2000, 1200);
    [, $stored] = style_test_store($source);
    try {
        [$width, $height] = (array) getimagesize($stored);
        same(STYLE_PHOTO_MAX_SIDE, $width, 'el lado largo queda en el máximo');
        same(614, $height, 'y el otro conserva la proporción');
    } finally { @unlink($source); @unlink($stored); }
});

test('una foto pequeña no se amplía', function () {
    $source = style_test_image(400, 300);
    [, $stored] = style_test_store($source);
    try { same([400, 300], array_slice((array) getimagesize($stored), 0, 2)); }
    finally { @unlink($source); @unlink($stored); }
});

test('la copia es siempre JPEG, venga como venga', function () {
    foreach (['jpeg' => 'image/jpeg', 'png' => 'image/png'] as $format => $mime) {
        $source = style_test_image(80, 80, $format);
        same($mime, style_photo_format($source), "el original es {$mime}");
        [, $stored] = style_test_store($source);
        try { same('image/jpeg', style_photo_format($stored)); }
        finally { @unlink($source); @unlink($stored); }
    }
});

test('un PNG transparente no llega como silueta negra', function () {
    // JPEG no tiene alfa y GD rellena de negro lo que no puede conservar: un recorte de
    // producto, que es justo lo que alguien sube como inspiración, llegaría en sombra.
    $file = (string) tempnam(sys_get_temp_dir(), 'styletest');
    $image = imagecreatetruecolor(50, 50);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagepng($image, $file);
    imagedestroy($image);

    [, $stored] = style_test_store($file);
    try {
        $copy = imagecreatefromjpeg($stored);
        $color = imagecolorsforindex($copy, imagecolorat($copy, 25, 25));
        imagedestroy($copy);
        truthy($color['red'] > 240 && $color['green'] > 240 && $color['blue'] > 240, 'el fondo transparente queda blanco, no negro');
    } finally { @unlink($file); @unlink($stored); }
});

test('el EXIF del original no viaja en la copia', function () {
    // Es el bloque donde el móvil escribe, entre otras cosas, las coordenadas de tu casa.
    $marker = 'COORDENADAS-DE-CASA-' . bin2hex(random_bytes(6));
    $source = style_test_jpeg_with_exif($marker);
    truthy(str_contains((string) file_get_contents($source), $marker), 'el original sí lo lleva');
    [, $stored] = style_test_store($source);
    try { falsy(str_contains((string) file_get_contents($stored), $marker), 'la copia no'); }
    finally { @unlink($source); @unlink($stored); }
});

describe('style_photo_path() · nombres que vienen de la base de datos');

test('acepta un nombre que hemos creado nosotros', function () {
    $source = style_test_image(40, 40);
    [$name, $stored] = style_test_store($source);
    try { same($stored, style_photo_path($name)); }
    finally { @unlink($source); @unlink($stored); }
});

$nombresRotos = [
    '../../.env'                                  => 'un recorrido de directorios',
    'subdir/999001-aaaaaaaaaaaaaaaaaaaaaaaa.jpg'  => 'una barra',
    '/etc/passwd'                                 => 'una ruta absoluta',
    ''                                            => 'el nombre vacío',
    '999001-abc.jpg'                              => 'un sufijo que no es el nuestro',
    '999001-aaaaaaaaaaaaaaaaaaaaaaaa.php'         => 'otra extensión',
    '999001-AAAAAAAAAAAAAAAAAAAAAAAA.jpg'         => 'hexadecimal en mayúsculas, que no generamos',
];
foreach ($nombresRotos as $nombre => $why) {
    test('rechaza ' . $why, fn() => same(null, style_photo_path($nombre)));
}

test('un nombre con la forma correcta pero sin fichero devuelve null', function () {
    same(null, style_photo_path('999001-' . str_repeat('a', 24) . '.jpg'));
});

describe('style_photos_delete()');

test('borra las nuestras y deja en paz cualquier otra cosa', function () {
    $source = style_test_image(40, 40);
    [$name, $stored] = style_test_store($source);
    $sentinel = dirname(style_photo_dir()) . '/no-tocar.jpg';
    file_put_contents($sentinel, 'x');
    try {
        style_photos_delete([$name, '../no-tocar.jpg', null, 42]);
        falsy(is_file($stored), 'la foto se ha borrado');
        truthy(is_file($sentinel), 'el fichero de fuera sigue ahí');
    } finally { @unlink($source); @unlink($stored); @unlink($sentinel); }
});

describe('style_photos_of_user()');

test('encuentra solo las de esa cuenta', function () {
    $source = style_test_image(40, 40);
    [$mias, $ruta1] = style_test_store($source, 999001);
    [, $ruta2] = style_test_store($source, 999002);
    try {
        $encontradas = style_photos_of_user(999001);
        same([$mias], $encontradas, 'ni una de la otra cuenta');
    } finally { @unlink($source); @unlink($ruta1); @unlink($ruta2); }
});

describe('style_photos_purge()');

test('se lleva lo que ningún job va a leer ya y respeta lo reciente', function () {
    $source = style_test_image(40, 40);
    [, $vieja] = style_test_store($source, 999003);
    [, $nueva] = style_test_store($source, 999004);
    touch($vieja, time() - (STYLE_PHOTO_RETENTION_HOURS + 1) * 3600);
    try {
        truthy(style_photos_purge() >= 1, 'ha borrado al menos una');
        falsy(is_file($vieja), 'la caducada ya no está');
        truthy(is_file($nueva), 'la de hace un momento sigue');
    } finally { @unlink($source); @unlink($vieja); @unlink($nueva); }
});

describe('style_photo_format()');

test('un fichero que no es una imagen no pasa', function () {
    $file = (string) tempnam(sys_get_temp_dir(), 'styletest');
    file_put_contents($file, 'esto es texto, no una foto');
    try { same(null, style_photo_format($file)); }
    finally { @unlink($file); }
});

test('el formato sale de los bytes, no del nombre', function () {
    $source = style_test_image(40, 40, 'png');
    $renamed = $source . '.jpg';
    rename($source, $renamed);
    try { same('image/png', style_photo_format($renamed), 'se llama .jpg pero es un PNG'); }
    finally { @unlink($renamed); }
});

describe('style_reading_input()');

test('la nota se recorta al máximo que se envía al modelo', function () {
    $input = style_reading_input([], str_repeat('a', STYLE_NOTE_MAX_LENGTH + 50));
    same(STYLE_NOTE_MAX_LENGTH, mb_strlen($input['note']));
});

test('la nota se limpia por los extremos', function () {
    same('sin abrigos', style_reading_input([], "  sin abrigos \n")['note']);
});

test('solo viajan nombres de fichero, y con las claves seguidas', function () {
    $input = style_reading_input(['a.jpg', null, 7, 'b.jpg'], '');
    same(['a.jpg', 'b.jpg'], $input['photos']);
});

describe('style_reading_result() · lo que se guarda del veredicto');

test('se queda con las claves del perfil y con el resumen', function () {
    $result = style_reading_result([
        'summary' => 'Vistes sobrio y cómodo.',
        'style' => 'Minimalista',
        'colors' => 'Tonos tierra',
        'occasions' => 'Oficina',
        'avoid' => '',
        'sizes' => 'M',
        'inventado' => 'no deberia llegar',
    ]);
    same(['summary', 'style', 'colors', 'occasions'], array_keys($result), 'ni avoid vacío, ni sizes, ni claves de fuera');
    same('Vistes sobrio y cómodo.', $result['summary']);
});

test('un campo que el estilista no pudo deducir no llega relleno', function () {
    // Es la mitad del diseño: el formulario tiene que quedarse vacío ahí, no con un invento.
    $result = style_reading_result(['summary' => 'x', 'style' => 'Casual', 'colors' => '', 'occasions' => '', 'avoid' => '']);
    falsy(isset($result['colors']), 'colors vacío no se guarda');
    falsy(isset($result['avoid']), 'avoid vacío tampoco');
});

test('sin resumen no se inventa la clave', function () {
    falsy(isset(style_reading_result(['style' => 'Casual'])['summary']));
});

test('el resumen se recorta como cualquier otro texto guardado', function () {
    same(STYLE_PROFILE_MAX_LENGTH, mb_strlen(style_reading_result(['summary' => str_repeat('a', STYLE_PROFILE_MAX_LENGTH + 100)])['summary']));
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
    // Se le borraron las fotos igual, así que cobrárselas la dejaría sin poder reintentar.
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
