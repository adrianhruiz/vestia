<?php
declare(strict_types=1);

require_once APP_ROOT . '/app/Images.php';

/**
 * A shared look: the snapshot stored in outfit_shares, the two public copies of its picture
 * and the page a stranger lands on at /o/{token}.
 *
 * The page is not a data dump, it is the shop window: someone who has never heard of Vestia
 * arrives here from a chat, and what they see is one real look put together out of one real
 * wardrobe. So it carries the app's own header, type and colours, and the registration modal
 * lives on the page itself -- sending an interested stranger to the home page to start over
 * is where the interest goes to die.
 */

/** 1.91:1 is the card WhatsApp, Telegram, Slack and X expect from og:image. */
const SHARE_OG_WIDTH = 1200;
const SHARE_OG_HEIGHT = 630;
/** Longest side of the copy the page itself paints. */
const SHARE_PAGE_SIDE = 1200;
/** Garments drawn into the preview card of a look that has no generated picture. */
const SHARE_OG_PIECES = 4;
/** Links one account may keep alive at once, so the endpoint is not unlimited storage. */
const SHARE_MAX_ACTIVE = 50;

function share_token(): string { return bin2hex(random_bytes(16)); }

function share_dir(): string {
    $dir = APP_ROOT . '/storage/shares';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

/** The two files a share owns: what the page paints, and what the chat app previews. */
function share_files(string $token): array {
    return ['page' => share_dir() . '/' . $token . '.webp', 'og' => share_dir() . '/' . $token . '-og.jpg'];
}

function delete_share_images(string $token): void {
    foreach (share_files($token) as $file) if (is_file($file)) @unlink($file);
}

/**
 * Absolute origin of this installation. Every environment already declares it in .env, and
 * a share link has to be absolute: it is pasted into WhatsApp, and og:image is fetched by a
 * crawler that has no page to resolve a relative path against.
 */
function base_url(): string {
    $configured = rtrim((string) env('APP_URL', ''), '/');
    if ($configured !== '') return $configured;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function share_url(string $token): string { return base_url() . '/o/' . $token; }

function share_image_url(string $token, string $variant): string {
    return base_url() . '/share-image.php?token=' . $token . '&v=' . $variant;
}

/**
 * The frozen copy that gets published. Built field by field rather than by filtering the
 * outfit: a future key in result_json then has to be added here deliberately instead of
 * leaking the day someone adds it.
 */
function share_snapshot(array $outfit, array $garments, string $ownerName): array {
    $pieces = [];
    foreach ($outfit['garment_ids'] ?? [] as $id) {
        $garment = $garments[(int) $id] ?? null;
        if ($garment === null) continue;
        // Size, reference and notes stay at home: they say something about the person, not
        // about the look.
        $pieces[] = [
            'name' => (string) $garment['name'], 'brand' => (string) $garment['brand'],
            'color' => (string) ($garment['color'] ?? ''), 'category' => (string) ($garment['category'] ?? ''),
            'thumb_path' => (string) ($garment['thumb_path'] ?: $garment['image_path'] ?: ''),
            'source_url' => (string) ($garment['source_url'] ?? ''),
        ];
    }
    $shopping = [];
    foreach ($outfit['shopping'] ?? [] as $item) {
        if (!is_array($item)) continue;
        $shopping[] = ['brand' => (string) ($item['brand'] ?? ''), 'name' => (string) ($item['name'] ?? ''),
            'url' => (string) ($item['url'] ?? ''), 'price_note' => (string) ($item['price_note'] ?? '')];
    }
    return [
        'name' => (string) ($outfit['name'] ?? 'Un look'),
        'occasion' => (string) ($outfit['occasion'] ?? ''),
        'reason' => (string) ($outfit['reason'] ?? ''),
        'missing_items' => array_values(array_filter((array) ($outfit['missing_items'] ?? []), 'is_string')),
        'shopping' => $shopping, 'garments' => $pieces, 'owner_name' => $ownerName,
    ];
}

/** Absolute path of a public image referenced by a snapshot, or null if it is not one. */
function share_public_file(string $path): ?string {
    if (!str_starts_with($path, '/uploads/') && !str_starts_with($path, '/assets/')) return null;
    if (str_contains($path, '..')) return null;
    $file = APP_ROOT . '/public' . $path;
    return is_file($file) ? $file : null;
}

/** Draws $src into the box at ($x,$y), cropping the overflow. CSS object-fit: cover. */
function share_cover(GdImage $card, GdImage $src, int $x, int $y, int $width, int $height): void {
    $sourceWidth = imagesx($src); $sourceHeight = imagesy($src);
    $scale = max($width / $sourceWidth, $height / $sourceHeight);
    $cropWidth = (int) round($width / $scale); $cropHeight = (int) round($height / $scale);
    imagecopyresampled($card, $src, $x, $y, (int) (($sourceWidth - $cropWidth) / 2), (int) (($sourceHeight - $cropHeight) / 2),
        $width, $height, $cropWidth, $cropHeight);
}

/**
 * The preview card of a look that has a generated picture. The picture is a 2:3 portrait and
 * the card is 1.91:1, so containing it leaves two empty columns: they get a blurred copy of
 * the same photo, which reads as a deliberate frame where a flat fill reads as a broken
 * image.
 */
function share_og_from_image(GdImage $source): GdImage {
    $card = imagecreatetruecolor(SHARE_OG_WIDTH, SHARE_OG_HEIGHT);
    $backdrop = imagescale($source, 80);
    if ($backdrop !== false) {
        // Blurring at thumbnail size and letting the upscale smooth it is far cheaper than
        // running the filter over 1200x630.
        for ($pass = 0; $pass < 3; $pass++) imagefilter($backdrop, IMG_FILTER_GAUSSIAN_BLUR);
        share_cover($card, $backdrop, 0, 0, SHARE_OG_WIDTH, SHARE_OG_HEIGHT);
        imagedestroy($backdrop);
    }
    $width = (int) round(imagesx($source) * SHARE_OG_HEIGHT / imagesy($source));
    imagecopyresampled($card, $source, (int) ((SHARE_OG_WIDTH - $width) / 2), 0, 0, 0,
        $width, SHARE_OG_HEIGHT, imagesx($source), imagesy($source));
    return $card;
}

/**
 * The preview card of a look nobody has drawn yet -- which is most of them, now that the
 * pictures are generated on demand. The garments themselves make the card: four columns of
 * real clothes say more about Vestia than a stock photo would.
 */
function share_og_from_garments(array $pieces): ?GdImage {
    $images = [];
    foreach ($pieces as $piece) {
        $file = share_public_file((string) ($piece['thumb_path'] ?? ''));
        if ($file === null) continue;
        $image = @imagecreatefromstring((string) file_get_contents($file));
        if ($image === false) continue;
        $images[] = $image;
        if (count($images) === SHARE_OG_PIECES) break;
    }
    if (!$images) return null;
    $card = imagecreatetruecolor(SHARE_OG_WIDTH, SHARE_OG_HEIGHT);
    $column = (int) ceil(SHARE_OG_WIDTH / count($images));
    foreach ($images as $index => $image) {
        share_cover($card, $image, $index * $column, 0, $column, SHARE_OG_HEIGHT);
        imagedestroy($image);
    }
    return $card;
}

/**
 * Writes the two public copies of a share. The generated picture is a 1024x1536 PNG of about
 * two megabytes, and the share page is the first thing a stranger ever loads from Vestia on
 * whatever connection they happen to be on, so the page gets a WebP instead.
 */
function store_share_images(array $snapshot, ?string $sourcePng, string $token): void {
    $files = share_files($token);
    $source = $sourcePng !== null && is_file($sourcePng)
        ? @imagecreatefromstring((string) file_get_contents($sourcePng)) : false;
    try {
        if ($source !== false) {
            $page = scale_within($source, SHARE_PAGE_SIDE);
            imagewebp($page, $files['page'], IMAGE_QUALITY);
            if ($page !== $source) imagedestroy($page);
        }
        $card = $source !== false ? share_og_from_image($source) : share_og_from_garments($snapshot['garments'] ?? []);
        if ($card !== null) { imagejpeg($card, $files['og'], IMAGE_QUALITY); imagedestroy($card); }
    } finally {
        if ($source !== false) imagedestroy($source);
    }
}

/** Shortens free text to fit a meta description without cutting a word in half. */
function share_excerpt(string $text, int $length = 155): string {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if (mb_strlen($text) <= $length) return $text;
    $cut = mb_substr($text, 0, $length);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ' .,;:') . '…';
}

/** The look at /o/{token}, or the branded 404/410 when there is nothing to show. */
function render_share_page(string $token): never {
    $stmt = db()->prepare('SELECT id,token,snapshot_json,revoked_at FROM outfit_shares WHERE token=?');
    $stmt->execute([$token]);
    $share = $stmt->fetch();
    if (!$share) share_error_page(404, 'Este look no existe', 'El enlace no es correcto, o el look nunca llegó a compartirse.');
    if ($share['revoked_at'] !== null) share_error_page(410, 'Este look ya no se comparte', 'Quien lo compartió ha retirado el enlace.');
    db()->prepare('UPDATE outfit_shares SET views=views+1 WHERE id=?')->execute([$share['id']]);
    // Whoever registers from here arrived through this look. /api/register writes it down,
    // which is the only way to know afterwards whether sharing brings anyone in.
    $_SESSION['ref_share'] = $token;

    // Defaults rather than ?? at every use: the template reads these a dozen times, and a
    // row written by an older version of share_snapshot() must still render.
    $look = (json_decode((string) $share['snapshot_json'], true) ?: [])
        + ['name' => 'Un look', 'occasion' => '', 'reason' => '', 'missing_items' => [], 'shopping' => [], 'garments' => [], 'owner_name' => ''];
    $owner = (string) $look['owner_name'];
    $pieces = (array) $look['garments'];
    $hasPicture = is_file(share_files($token)['page']);
    // A look whose garments have no photo at all leaves nothing to draw a card from, so the
    // preview falls back to the hero. Only our own card can promise 1200x630.
    $hasCard = is_file(share_files($token)['og']);
    $ogImage = $hasCard ? share_image_url($token, 'og') : base_url() . asset('/assets/hero/wardrobe-hero-1100.jpg');
    $title = $look['name'] . ($owner !== '' ? ' — un look de ' . $owner : '') . ' | Vestia';
    $description = share_excerpt((string) ($look['reason'] ?? '')) ?: 'Un look creado con Vestia a partir de un armario real.';
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#183f36">
<?php // Someone's wardrobe is not ours to make searchable. The messaging crawlers that make
      // the link worth sharing build their preview from og: tags and ignore this. ?>
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?></title><meta name="description" content="<?= e($description) ?>">
<meta property="og:type" content="article"><meta property="og:site_name" content="Vestia">
<meta property="og:title" content="<?= e($look['name'] . ($owner !== '' ? ' — un look de ' . $owner : '')) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e(share_url($token)) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>"><?php if ($hasCard): ?><meta property="og:image:width" content="<?= SHARE_OG_WIDTH ?>"><meta property="og:image:height" content="<?= SHARE_OG_HEIGHT ?>"><?php endif ?>
<meta name="twitter:card" content="summary_large_image">
<link rel="preload" as="font" type="font/woff2" href="/assets/fonts/dm-sans-latin.woff2" crossorigin><link rel="preload" as="font" type="font/woff2" href="/assets/fonts/playfair-display-latin.woff2" crossorigin>
<link rel="stylesheet" href="<?= asset('/assets/app.css') ?>"></head>
<body data-auth="0">
<header class="topbar"><a class="brand" href="/"><span>V</span><div class="brand-label">Vestia<small class="app-version">Versión <?= e(trim((string) file_get_contents(APP_ROOT . '/VERSION'))) ?></small></div></a><nav><a href="#how">Cómo funciona</a></nav><div><button class="btn btn-ghost" data-open-auth="login">Entrar</button><button class="btn btn-dark" data-open-auth="register">Crear armario</button></div></header>
<main class="share">
 <section class="share-look">
  <figure class="share-figure"><?php if ($hasPicture): $size = @getimagesize(share_files($token)['page']); ?><img src="<?= e(share_image_url($token, 'page')) ?>" alt="<?= e($look['name']) ?>"<?= $size ? ' width="' . (int) $size[0] . '" height="' . (int) $size[1] . '"' : '' ?> fetchpriority="high" decoding="async"><?php else: ?><div class="outfit-collage"><?php foreach (array_slice($pieces, 0, 4) as $piece): if (($piece['thumb_path'] ?? '') === '') continue; ?><img src="<?= e($piece['thumb_path']) ?>" alt="<?= e($piece['name']) ?>" decoding="async"><?php endforeach ?></div><?php endif ?></figure>
  <div class="share-intro">
   <?php if (($look['occasion'] ?? '') !== ''): ?><span class="eyebrow"><?= e(mb_strtoupper($look['occasion'])) ?></span><?php endif ?>
   <h1><?= e($look['name']) ?></h1>
   <p class="share-owner"><?= $owner !== '' ? 'Un look de <b>' . e($owner) . '</b>, ' : 'Un look ' ?>creado con Vestia a partir de su armario real.</p>
   <p class="share-reason"><?= e((string) ($look['reason'] ?? '')) ?></p>
   <button class="btn btn-coral" data-open-auth="register">Crear mi armario <b>→</b></button>
  </div>
 </section>
 <?php if ($pieces): ?>
 <section class="share-pieces"><h2>Las prendas del look</h2><div class="garment-grid">
  <?php foreach ($pieces as $piece): ?>
  <article class="garment"><div class="garment-image"><?php if (($piece['thumb_path'] ?? '') !== ''): ?><img src="<?= e($piece['thumb_path']) ?>" alt="<?= e($piece['name']) ?>" loading="lazy" decoding="async"><?php else: ?><span class="garment-placeholder">✦</span><?php endif ?></div><div class="garment-body"><span class="garment-brand"><?= e($piece['brand']) ?></span><h3><?= e($piece['name']) ?></h3><div class="garment-meta"><?= e($piece['color']) ?></div><?php if (($piece['source_url'] ?? '') !== '' && str_starts_with($piece['source_url'], 'https://')): ?><a class="product-source" href="<?= e($piece['source_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">Ver producto real ↗</a><?php endif ?></div></article>
  <?php endforeach ?>
 </div></section>
 <?php endif ?>
 <?php if ($look['missing_items'] || $look['shopping']): ?>
 <section class="share-missing"><h2>Para completarlo</h2>
  <?php if ($look['missing_items']): ?><div class="chips"><?php foreach ($look['missing_items'] as $item): ?><span class="chip"><?= e($item) ?></span><?php endforeach ?></div><?php endif ?>
  <?php if ($look['shopping']): ?><div class="shopping"><small>COMPRAR EN INDITEX</small><?php foreach ($look['shopping'] as $item): if (!str_starts_with($item['url'], 'https://')) continue; ?><a href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer nofollow sponsored"><span><?= e($item['brand']) ?> · <?= e($item['name']) ?></span><b>↗</b></a><?php endforeach ?></div><?php endif ?>
 </section>
 <?php endif ?>
 <section class="how" id="how"><div><span class="step">01</span><h2>Fotografía o escanea</h2><p>Añade la foto y la referencia de la etiqueta de cada prenda.</p></div><div><span class="step">02</span><h2>Define tu estilo</h2><p>Dinos qué te gusta, qué evitas y para qué ocasiones vistes.</p></div><div><span class="step">03</span><h2>Descubre tus looks</h2><p>La IA analiza en segundo plano y combina la ropa que ya tienes.</p></div></section>
 <section class="share-cta"><h2>Tu armario también tiene algo que decir</h2><p>Digitaliza tus prendas y deja que Vestia te proponga combinaciones con lo que ya tienes.</p><button class="btn btn-coral" data-open-auth="register">Crear mi armario gratis</button><small>Sin tarjeta</small></section>
</main>
<div class="modal" id="auth-modal" hidden><div class="modal-card auth-card"><button class="close" data-close>×</button><span class="eyebrow">BIENVENIDO A VESTIA</span><h2 id="auth-title">Entrar</h2><form id="auth-form"><label class="register-only">Nombre<input name="name" autocomplete="name"></label><label>Email<input type="email" name="email" required autocomplete="email"></label><label>Contraseña<input type="password" name="password" required minlength="10" autocomplete="current-password"></label><button class="btn btn-coral full">Continuar</button><p class="form-error"></p></form><button class="text-btn" id="auth-switch">¿No tienes cuenta? Crear armario</button></div></div>
<div id="toast" role="status"></div>
<?php // Registering here lands on an empty wardrobe, which is the worst possible answer to
      // someone who just decided they want this: the app opens on "add your first garment". ?>
<script>window.APP=<?= json_encode(['csrf' => csrf_token(), 'after_auth' => '/?nueva-prenda=1'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script><script src="<?= asset('/assets/app.js') ?>"></script></body></html>
<?php
    exit;
}

function share_error_page(int $status, string $title, string $text): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= e($title) ?> | Vestia</title><link rel="stylesheet" href="<?= asset('/assets/app.css') ?>"></head>
<body><header class="topbar"><a class="brand" href="/"><span>V</span><div class="brand-label">Vestia</div></a><div><a class="btn btn-dark" href="/">Crear armario</a></div></header>
<main class="share"><section class="empty"><b><?= e($title) ?></b><p><?= e($text) ?></p></section></main></body></html>
<?php
    exit;
}
