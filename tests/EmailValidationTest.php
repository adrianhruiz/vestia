<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/EmailValidation.php';

/**
 * The two ways this can go wrong are opposite and both bad: letting a throwaway provider
 * through leaves the door open, and turning away a real address costs a signup from someone
 * who has no idea why. The DNS cases need the network and skip without it.
 */
describe('email_rejection() · direcciones que deben pasar');

foreach (['adri@gmail.com', 'adri@hotmail.es', 'adri.ruiz+x@empresa.es', 'raul@proton.me'] as $bueno) {
    test('acepta ' . $bueno, function () use ($bueno) {
        if (!email_domain_has_records(EMAIL_DNS_SENTINEL)) skip('sin DNS');
        same(null, email_rejection($bueno));
    });
}

describe('email_rejection() · proveedores desechables');

$desechables = [
    'basura@mailinator.com'            => 'dominio exacto de la lista',
    'basura@yopmail.com'               => 'otro dominio de la lista',
    'basura@MAILINATOR.COM'            => 'las mayúsculas no lo esquivan',
    'basura@loquesea.mailinator.com'   => 'un subdominio tampoco',
    'basura@a.b.guerrillamail.com'     => 'ni un subdominio de varios niveles',
];
foreach ($desechables as $email => $why) {
    test($why . ': ' . $email, fn() => same('disposable', email_rejection($email)));
}

test('un dominio normal no se confunde con uno de la lista', function () {
    // "mimailinator.com" contiene el nombre pero no es un subdominio suyo.
    falsy(email_domain_disposable('mimailinator.com'), 'la coincidencia es por etiquetas, no por substring');
    falsy(email_domain_disposable('gmail.com'));
    falsy(email_domain_disposable('empresa.com'));
});

test('la lista se lee del fichero y no está vacía', function () {
    truthy(is_file(EMAIL_DISPOSABLE_LIST), 'existe database/disposable_domains.txt');
    truthy(email_domain_disposable('mailinator.com'), 'y se carga');
});

describe('email_rejection() · sintaxis');

// FILTER_VALIDATE_EMAIL es más estricto de lo que parece: aplica los 254 octetos de la
// dirección, los 64 del local part y exige un punto en el dominio. Por eso email_rejection()
// no vuelve a comprobarlos, y estos casos son la prueba de que no hace falta.
$rotas = [
    'sin-arroba'                          => 'sin arroba',
    'adri@'                               => 'sin dominio',
    '@gmail.com'                          => 'sin local part',
    'adri@localhost'                      => 'dominio sin punto',
    'adri@dominio.'                       => 'dominio con punto final',
    'dos@arrobas@gmail.com'               => 'dos arrobas',
];
foreach ($rotas as $email => $why) {
    test($why . ': ' . var_export($email, true), fn() => same('syntax', email_rejection($email)));
}

test('un local part de más de 64 octetos se rechaza, uno de 64 no', function () {
    same('syntax', email_rejection(str_repeat('a', 65) . '@gmail.com'), '65 octetos');
    if (!email_domain_has_records(EMAIL_DNS_SENTINEL)) skip('sin DNS para validar el de 64');
    same(null, email_rejection(str_repeat('a', 64) . '@gmail.com'), '64 octetos, el límite exacto');
});

test('una dirección de más de 254 octetos se rechaza', function () {
    $etiqueta = str_repeat('a', 60);
    $larga = str_repeat('l', 60) . '@' . implode('.', array_fill(0, 4, $etiqueta)) . '.com';
    truthy(strlen($larga) > 254, 'el caso realmente pasa de 254');
    same('syntax', email_rejection($larga));
});

describe('email_rejection() · dominios que no reciben correo');

test('un dominio inventado se rechaza', function () {
    if (!email_domain_has_records(EMAIL_DNS_SENTINEL)) skip('sin DNS');
    same('domain', email_rejection('adri@dominio-que-no-existe-jamas-12345.com'));
});

test('un TLD reservado se rechaza', function () {
    if (!email_domain_has_records(EMAIL_DNS_SENTINEL)) skip('sin DNS');
    same('domain', email_rejection('adri@ejemplo.invalid'));
});

test('los proveedores que la gente usa de verdad resuelven', function () {
    if (!email_domain_has_records(EMAIL_DNS_SENTINEL)) skip('sin DNS');
    foreach (['gmail.com', 'hotmail.es', 'outlook.es', 'proton.me', 'icloud.com', 'yahoo.es', 'telefonica.net'] as $dominio) {
        truthy(email_domain_resolvable($dominio), $dominio);
    }
});

test('un dominio sin MX pero con registro A se acepta', function () {
    if (!email_domain_has_records(EMAIL_DNS_SENTINEL)) skip('sin DNS');
    // RFC 5321 s5.1: sin MX, el registro de dirección es el servidor de correo implícito, y
    // así está montado medio internet pequeño.
    truthy(email_domain_resolvable('mail.google.com'), 'resuelve por A aunque no publique MX');
});

describe('email_domain_of()');

test('se queda con la última arroba', fn() => same('gmail.com', email_domain_of('"a@b"@gmail.com')));
test('recorta los puntos de los extremos', fn() => same('gmail.com', email_domain_of('adri@gmail.com.')));
test('devuelve null cuando no hay dominio', function () {
    same(null, email_domain_of('sin-arroba'));
    same(null, email_domain_of('adri@'));
});
