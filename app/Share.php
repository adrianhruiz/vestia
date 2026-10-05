<?php
declare(strict_types=1);

require_once APP_ROOT . '/app/Images.php';

/**
 * A shared look: the snapshot stored in outfit_shares, the preview card drawn for it and
 * the page a stranger lands on at /o/{token}.
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
/** Garments drawn into the preview card of a look. */
const SHARE_OG_PIECES = 4;
/** Garments laid out in the hero of a shared look: a big tile and the four around it. */
const SHARE_FLATLAY_PIECES = 5;
/** Links one account may keep alive at once, so the endpoint is not unlimited storage. */
const SHARE_MAX_ACTIVE = 50;

function share_token(): string { return bin2hex(random_bytes(16)); }

function share_dir(): string {
    $dir = APP_ROOT . '/storage/shares';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

/**
 * The files a share may own: what the chat app previews, and -- only for links created back
 * when Vestia generated a picture of each look -- the copy the page itself paints. Nothing
 * writes a page copy any more; the key stays so the shares that already have one keep it
 * instead of silently losing their picture.
 */
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
            'url' => (string) ($item['url'] ?? ''), 'price_note' => share_plain_text((string) ($item['price_note'] ?? ''))];
    }
    return [
        'name' => (string) ($outfit['name'] ?? 'Un look'),
        'occasion' => (string) ($outfit['occasion'] ?? ''),
        'reason' => share_plain_text((string) ($outfit['reason'] ?? '')),
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
 * The preview card of a shared look. The garments themselves make it: four columns of real
 * clothes say more about Vestia than a stock photo would.
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
 * Writes the public preview card of a share, built from the photos of its own garments. A
 * look where none of them has a photo leaves nothing to draw, and no card is written: the
 * page falls back to the hero in its og:image.
 */
function store_share_images(array $snapshot, string $token): void {
    $card = share_og_from_garments($snapshot['garments'] ?? []);
    if ($card === null) return;
    imagejpeg($card, share_files($token)['og'], IMAGE_QUALITY);
    imagedestroy($card);
}

/** Shortens free text to fit a meta description without cutting a word in half. */
function share_excerpt(string $text, int $length = 155): string {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if (mb_strlen($text) <= $length) return $text;
    $cut = mb_substr($text, 0, $length);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ' .,;:') . '…';
}

/**
 * The stylist searches the web and cites what it found the way a chat does, as markdown:
 * "…en blanco. ([massimodutti.com](https://…))". On a page of plain text that is a raw URL
 * in the middle of a sentence. A citation in brackets goes whole; any other link keeps its
 * words.
 */
function share_plain_text(string $text): string {
    $text = preg_replace('/\s*\(\[[^\]]*\]\([^)\s]*\)\)/u', '', $text) ?? $text;
    $text = preg_replace('/\[([^\]]*)\]\([^)\s]*\)/u', '$1', $text) ?? $text;
    return trim(preg_replace('/ {2,}/', ' ', $text) ?? $text);
}

/**
 * The price out of a price note, which is a sentence ("Verificado en web oficial de Bershka:
 * 29,99 €, color negro… dentro del presupuesto de 100 €"). The first amount that is not the
 * budget is the price; a note with no amount has no price to show, and says nothing.
 */
function share_price(string $note): string {
    if (!preg_match_all('/(?<![\d.,])\d+(?:[.,]\d{3})*(?:[.,]\d{2})?\s?€/u', $note, $found, PREG_OFFSET_CAPTURE)) return '';
    foreach ($found[0] as [$amount, $offset]) {
        if (str_contains(mb_strtolower(substr($note, max(0, $offset - 30), min(30, $offset))), 'presupuesto')) continue;
        return (string) preg_replace('/\s?€$/u', "\u{00A0}€", $amount);
    }
    return '';
}

/**
 * Catalogue names arrive in the shop's own capitals: "DEPORTIVO TECNICO", "Cazadora acolchada
 * THERMAL RESISTANCE". Set in a serif next to each other they shout, so a name in capitals
 * becomes a sentence, and a word in capitals inside a name becomes a word.
 */
function share_display_name(string $name): string {
    $letters = (string) preg_replace('/\P{L}/u', '', $name);
    if ($letters !== '' && mb_strtoupper($letters) === $letters && mb_strlen($letters) > 3) {
        $lower = mb_strtolower($name);
        return mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
    }
    return (string) preg_replace_callback('/(?<!\p{L})\p{Lu}{4,}(?!\p{L})/u',
        static fn(array $word): string => mb_substr($word[0], 0, 1) . mb_strtolower(mb_substr($word[0], 1)), $name);
}

/**
 * The pieces with a photo, in the order the hero lays them out: the piece that carries the
 * look takes the big tile, and shoes go last, where the wide tile at the bottom fits them.
 */
function share_flatlay(array $pieces): array {
    $photos = array_values(array_filter($pieces, static fn(array $piece): bool => ($piece['thumb_path'] ?? '') !== ''));
    if (!$photos) return [];
    $rank = static function (array $piece): int {
        $order = array_search($piece['category'] ?? '', ['Abrigos', 'Vestidos', 'Partes de arriba', 'Partes de abajo', 'Accesorios', 'Calzado'], true);
        return $order === false ? 4 : $order;
    };
    $lead = 0;
    foreach ($photos as $index => $piece) if ($rank($piece) < $rank($photos[$lead])) $lead = $index;
    $rest = $photos;
    array_splice($rest, $lead, 1);
    usort($rest, static fn(array $a, array $b): int => (($a['category'] ?? '') === 'Calzado') <=> (($b['category'] ?? '') === 'Calzado'));
    return array_slice([$photos[$lead], ...$rest], 0, SHARE_FLATLAY_PIECES);
}

/**
 * Shoes and bags are shot on their own against a plain background, so in the hero they keep
 * their whole shape instead of filling the tile. Clothes are photographed on a person and
 * fill theirs.
 */
function share_is_product_shot(array $piece): bool {
    return in_array($piece['category'] ?? '', ['Calzado', 'Accesorios'], true);
}

/**
 * The background a product photo was shot on, read from its corners, so the tile around it
 * can be painted the same colour. Every shop lights its studio differently, and a tile in the
 * page's own grey around a photo in a slightly different one shows as a box inside a box.
 */
function share_photo_backdrop(string $path): string {
    $file = share_public_file($path);
    $image = $file === null ? false : @imagecreatefromstring((string) file_get_contents($file));
    if ($image === false) return '';
    $width = imagesx($image); $height = imagesy($image);
    $sum = [0, 0, 0];
    foreach ([[1, 1], [$width - 2, 1], [1, $height - 2], [$width - 2, $height - 2]] as [$x, $y]) {
        $rgb = imagecolorsforindex($image, imagecolorat($image, max(0, $x), max(0, $y)));
        $sum[0] += $rgb['red']; $sum[1] += $rgb['green']; $sum[2] += $rgb['blue'];
    }
    imagedestroy($image);
    return vsprintf('#%02x%02x%02x', array_map(static fn(int $channel): int => intdiv($channel, 4), $sum));
}

/** The owner as a name: "adri" is how someone typed it at sign-up, "Adri" is how it reads. */
function share_owner_name(string $name): string {
    $name = trim($name);
    return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
}

/**
 * What the look still needs, each with where to get it. The stylist writes the two lists
 * separately, so they are paired only when they line up one to one; otherwise the needs are
 * listed on their own and every link names its own product.
 */
function share_missing_rows(array $missing, array $shopping): array {
    $shopping = array_values(array_filter(array_map(
        static fn($item): array => (array) $item + ['brand' => '', 'name' => '', 'url' => '', 'price_note' => ''], $shopping),
        static fn(array $item): bool => str_starts_with((string) $item['url'], 'https://')));
    $missing = array_values(array_filter($missing, 'is_string'));
    if (count($missing) === count($shopping)) {
        return array_map(static fn(string $need, array $item): array => ['need' => $need, 'shop' => $item], $missing, $shopping);
    }
    return [...array_map(static fn(string $need): array => ['need' => $need, 'shop' => null], $missing),
        ...array_map(static fn(array $item): array => ['need' => (string) $item['name'], 'shop' => $item], $shopping)];
}

/** The look at /o/{token}, or the branded 404/410 when there is nothing to show. */
function render_share_page(string $token): never {
    $stmt = db()->prepare('SELECT id,token,snapshot_json,revoked_at FROM outfit_shares WHERE token=?');
    $stmt->execute([$token]);
    $share = $stmt->fetch();
    if (!$share) share_error_page(404, 'Este look no existe', 'El enlace no es correcto, o el look nunca llegó a compartirse.');
    if ($share['revoked_at'] !== null) share_error_page(410, 'Este look ya no se comparte', 'Quien lo compartió ha retirado el enlace.');
    db()->prepare('UPDATE outfit_shares SET views=views+1 WHERE id=?')->execute([$share['id']]);
    // Someone already signed in has nothing to register for: the page greets them with their
    // own account and sends them back to their wardrobe instead of selling them Vestia.
    $viewer = null;
    if ($uid = user_id()) {
        $stmt = db()->prepare('SELECT name,email FROM users WHERE id=?');
        $stmt->execute([$uid]);
        $viewer = $stmt->fetch() ?: null;
    }
    // Whoever registers from here arrived through this look. /api/register writes it down,
    // which is the only way to know afterwards whether sharing brings anyone in.
    if ($viewer === null) $_SESSION['ref_share'] = $token;

    // Defaults rather than ?? at every use: the template reads these a dozen times, and a
    // row written by an older version of share_snapshot() must still render.
    $look = (json_decode((string) $share['snapshot_json'], true) ?: [])
        + ['name' => 'Un look', 'occasion' => '', 'reason' => '', 'missing_items' => [], 'shopping' => [], 'garments' => [], 'owner_name' => ''];
    $owner = share_owner_name((string) $look['owner_name']);
    $pieces = array_map(static fn($piece): array => (array) $piece
        + ['name' => '', 'brand' => '', 'color' => '', 'category' => '', 'thumb_path' => '', 'source_url' => ''], (array) $look['garments']);
    $reason = share_plain_text((string) $look['reason']);
    $flatlay = share_flatlay($pieces);
    // Only links from back when Vestia generated a picture per look have one of these. The
    // real garments come first even then: that picture drew clothes the person does not own.
    $hasPicture = !$flatlay && is_file(share_files($token)['page']);
    $missingRows = share_missing_rows((array) $look['missing_items'], (array) $look['shopping']);
    // A look whose garments have no photo at all leaves nothing to draw a card from, so the
    // preview falls back to the hero. Only our own card can promise 1200x630.
    $hasCard = is_file(share_files($token)['og']);
    $ogImage = $hasCard ? share_image_url($token, 'og') : base_url() . asset('/assets/hero/wardrobe-hero-1100.jpg');
    $title = $look['name'] . ($owner !== '' ? ' — un look de ' . $owner : '') . ' | Vestia';
    $description = share_excerpt($reason) ?: 'Un look creado con Vestia a partir de un armario real.';
    $productLink = static fn(array $piece): string => str_starts_with((string) ($piece['source_url'] ?? ''), 'https://') ? (string) $piece['source_url'] : '';
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
<?php // No version number here: it means something to us and nothing to a stranger. ?>
<header class="topbar"><a class="brand" href="/"><span>V</span>Vestia</a><div><?php if ($viewer): ?><a class="btn btn-dark" href="/">Mi armario</a><div class="account"><button class="avatar" id="account-toggle" title="Tu cuenta" aria-haspopup="true" aria-expanded="false" aria-controls="account-menu"><?= e(mb_strtoupper(mb_substr((string) $viewer['name'], 0, 1))) ?></button><div class="account-menu" id="account-menu" role="menu" hidden><div class="account-id"><b><?= e($viewer['name']) ?></b><span><?= e($viewer['email']) ?></span></div><a class="account-item" role="menuitem" href="/">Mi armario</a><button class="account-item danger" role="menuitem" id="logout">Cerrar sesión</button></div></div><?php else: ?><button class="btn btn-ghost" data-open-auth="login">Entrar</button><button class="btn btn-coral" data-open-auth="register">Crear mi armario</button><?php endif ?></div></header>
<main class="share">
 <section class="share-look<?= $flatlay || $hasPicture ? '' : ' is-text' ?>">
  <?php if ($flatlay): ?>
  <figure class="share-flatlay" data-count="<?= count($flatlay) ?>"><?php foreach ($flatlay as $index => $piece): $link = $productLink($piece); $tile = share_is_product_shot($piece) ? ' class="is-product" style="background:' . e(share_photo_backdrop((string) $piece['thumb_path']) ?: '#fff') . '"' : ''; ?><<?= ($link !== '' ? 'a href="' . e($link) . '" target="_blank" rel="noopener noreferrer nofollow"' : 'span') . $tile ?>><img src="<?= e($piece['thumb_path']) ?>" alt="<?= e(share_display_name((string) $piece['name'])) ?>"<?= $index === 0 ? ' fetchpriority="high"' : '' ?> decoding="async"></<?= $link !== '' ? 'a' : 'span' ?>><?php endforeach ?></figure>
  <?php elseif ($hasPicture): $size = @getimagesize(share_files($token)['page']); ?>
  <figure class="share-figure"><img src="<?= e(share_image_url($token, 'page')) ?>" alt="<?= e($look['name']) ?>"<?= $size ? ' width="' . (int) $size[0] . '" height="' . (int) $size[1] . '"' : '' ?> fetchpriority="high" decoding="async"></figure>
  <?php endif ?>
  <div class="share-intro">
   <?php if ($look['occasion'] !== ''): ?><span class="eyebrow"><?= e(mb_strtoupper((string) $look['occasion'])) ?></span><?php endif ?>
   <h1><?= e($look['name']) ?></h1>
   <p class="share-owner"><?php if ($owner !== ''): ?><i aria-hidden="true"><?= e(mb_strtoupper(mb_substr($owner, 0, 1))) ?></i><span>Un look de <b><?= e($owner) ?></b>, hecho con su armario real</span><?php else: ?><span>Un look hecho con Vestia a partir de un armario real</span><?php endif ?></p>
   <?php if ($reason !== ''): ?><p class="share-reason"><?= e($reason) ?></p><?php endif ?>
   <div class="share-act"><?php if ($viewer): ?><a class="btn btn-coral" href="/">Ir a mi armario <b>→</b></a><?php else: ?><div><button class="btn btn-coral" data-open-auth="register">Crear mi armario <b>→</b></button><small>Gratis, sin tarjeta</small></div><?php endif ?><button class="share-copy" type="button" data-copy-link="<?= e(share_url($token)) ?>" data-share-label="Compartir este look">Copiar enlace del look</button></div>
  </div>
 </section>
 <?php if ($pieces): ?>
 <section><div class="share-head"><h2>Las prendas del look</h2><span><?= count($pieces) ?> <?= count($pieces) === 1 ? 'prenda' : 'prendas' ?></span></div><ul class="share-pieces">
  <?php foreach ($pieces as $piece): $link = $productLink($piece); $kind = e((string) $piece['brand']); ?>
  <li><<?= $link !== '' ? 'a class="share-piece" href="' . e($link) . '" target="_blank" rel="noopener noreferrer nofollow"' : 'div class="share-piece"' ?>><figure><?php if ($piece['thumb_path'] !== ''): ?><img src="<?= e($piece['thumb_path']) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><span class="garment-placeholder">✦</span><?php endif ?></figure><div><?php if ($kind !== ''): ?><small><?= $kind ?></small><?php endif ?><h3><?= e(share_display_name((string) $piece['name'])) ?></h3><?php if ($piece['color'] !== ''): ?><p><?= e($piece['color']) ?></p><?php endif ?><?php if ($link !== ''): ?><em>Ver en <?= e($piece['brand'] !== '' ? $piece['brand'] : 'la tienda') ?> ↗</em><?php endif ?></div></<?= $link !== '' ? 'a' : 'div' ?>></li>
  <?php endforeach ?>
 </ul></section>
 <?php endif ?>
 <?php if ($missingRows): ?>
 <section><div class="share-head"><h2>Para completarlo</h2><?php if (array_filter(array_column($missingRows, 'shop'))): ?><span class="eyebrow">COMPRAR EN INDITEX</span><?php endif ?></div><ul class="share-missing">
  <?php foreach ($missingRows as $row): $shop = $row['shop']; $price = $shop ? share_price((string) $shop['price_note']) : ''; ?>
  <li><span class="share-need"><?= e($row['need']) ?></span><?php if ($shop): ?><span class="share-shop"><?= e($row['need'] === $shop['name'] ? $shop['brand'] : trim($shop['brand'] . ' · ' . $shop['name'], ' ·')) ?></span><span class="share-buy"><?php if ($price !== ''): ?><b><?= e($price) ?></b><?php endif ?><a class="btn btn-dark" href="<?= e($shop['url']) ?>" target="_blank" rel="noopener noreferrer nofollow sponsored">Ver en <?= e($shop['brand'] !== '' ? $shop['brand'] : 'la tienda') ?> ↗</a></span><?php endif ?></li>
  <?php endforeach ?>
 </ul></section>
 <?php endif ?>
 <section><div class="share-head"><h2>Cómo se hizo este look</h2></div><ol class="share-how"><li><span class="step">01</span><h3>Fotografía o escanea</h3><p>Añade la foto y la referencia de la etiqueta de cada prenda.</p></li><li><span class="step">02</span><h3>Define tu estilo</h3><p>Dinos qué te gusta, qué evitas y para qué ocasiones vistes.</p></li><li><span class="step">03</span><h3>Descubre tus looks</h3><p>La IA combina la ropa que ya tienes y te dice qué falta.</p></li></ol></section>
 <?php if ($viewer): ?>
 <section class="share-cta"><div><h2>¿Y con tu armario?</h2><p>Pide a Vestia nuevas combinaciones con las prendas que ya tienes.</p></div><div class="share-go"><a class="btn btn-coral" href="/">Ir a mi armario</a></div></section>
 <?php else: ?>
 <section class="share-cta"><div><h2>Tu armario también tiene algo que decir</h2><p>Digitaliza tus prendas y deja que Vestia te proponga combinaciones con lo que ya tienes.</p></div><div class="share-go"><button class="btn btn-coral" data-open-auth="register">Crear mi armario</button><small>Gratis, sin tarjeta</small></div></section>
 <?php endif ?>
</main>
<footer class="share-foot"><a class="brand" href="/"><span>V</span>Vestia</a><span><?= $owner !== '' ? 'Look compartido por ' . e($owner) . ' · ' : '' ?>Las prendas enlazan a la tienda oficial</span></footer>
<?php if (!$viewer): ?><div class="modal" id="auth-modal" hidden><div class="modal-card auth-card"><button class="close" data-close>×</button><span class="eyebrow">BIENVENIDO A VESTIA</span><h2 id="auth-title">Entrar</h2><form id="auth-form"><label class="register-only">Nombre<input name="name" autocomplete="name"></label><label>Email<input type="email" name="email" required autocomplete="email"></label><label>Contraseña<input type="password" name="password" required minlength="10" autocomplete="current-password"></label><button class="btn btn-coral full">Continuar</button><p class="form-error"></p></form><button class="text-btn" id="auth-switch">¿No tienes cuenta? Crear armario</button></div></div><?php endif ?>
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
<body><header class="topbar"><a class="brand" href="/"><span>V</span><div class="brand-label">Vestia</div></a><div><a class="btn btn-dark" href="/">Crear mi armario</a></div></header>
<main class="share"><section class="empty"><b><?= e($title) ?></b><p><?= e($text) ?></p></section></main></body></html>
<?php
    exit;
}
