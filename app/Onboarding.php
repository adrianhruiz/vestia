<?php
declare(strict_types=1);

/**
 * The welcome: the screen that stands between registering and an empty wardrobe.
 *
 * Registration used to hand back a session and drop the person on a grid with nothing in it,
 * and the style profile -- six empty boxes under "Mi estilo" -- was the second thing they
 * were asked to do on their own. Nobody did it, so the stylist worked from the clothes alone.
 *
 * Here it is the other way round: show it photos, and it writes the profile. What comes back
 * is never saved behind anybody's back -- it lands in the form, filled in, for the person to
 * correct and save. A machine describing your style from your photograph and being half
 * right, with no way to argue, is a bad first thing to happen to you.
 *
 * It can always be refused. "Lo haré luego" is as complete an answer as finishing it: a wall
 * between signing up and the product is worth less than the profile it would extract.
 */

const ONBOARDING_PATH = '/bienvenida';

/** Whether the welcome is still owed to this account. */
function onboarding_pending(?array $user): bool {
    return $user !== null && ($user['onboarding_done_at'] ?? null) === null;
}

/**
 * Writes down that the welcome is behind them, whether they finished it or waved it away.
 *
 * Only ever the first time: the date is when they stopped being new, and somebody redoing
 * their reading a year later has not just registered.
 */
function onboarding_done(PDO $pdo, int $userId): void {
    $pdo->prepare('UPDATE users SET onboarding_done_at=? WHERE id=? AND onboarding_done_at IS NULL')
        ->execute([date(DATE_ATOM), $userId]);
}

/**
 * Where somebody goes once the welcome is behind them.
 *
 * Arriving from a shared look and landing on an empty wardrobe is the worst possible answer
 * to someone who has just decided they want this, so that intent survives the welcome and
 * opens the add-garment form on the other side.
 *
 * It travels in the query string, which means it is written by whoever is asking: this
 * returns one of two paths it chose itself and never the value it was given, so no request
 * can turn the end of registration into a redirect somewhere else.
 */
function onboarding_next_path(string $after): string {
    return $after === 'nueva-prenda' ? '/?nueva-prenda=1' : '/';
}

/** The welcome screen. Reachable whenever there is a session, finished or not. */
function render_onboarding_page(array $user, string $after): never {
    $next = onboarding_next_path($after);
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#183f36">
<?php // Nothing here is for a stranger, and there is nothing to index. ?>
<meta name="robots" content="noindex,nofollow">
<title>Cuéntale a Vestia cómo vistes</title>
<link rel="preload" as="font" type="font/woff2" href="/assets/fonts/dm-sans-latin.woff2" crossorigin><link rel="preload" as="font" type="font/woff2" href="/assets/fonts/playfair-display-latin.woff2" crossorigin>
<link rel="stylesheet" href="<?= asset('/assets/app.css') ?>"></head>
<body data-onboarding="1">
<header class="topbar"><a class="brand" href="/"><span>V</span><div class="brand-label">Vestia<small class="app-version">Versión <?= e(trim((string) file_get_contents(APP_ROOT . '/VERSION'))) ?></small></div></a><div><a class="btn btn-ghost" href="<?= e($next) ?>">Ir a mi armario</a></div></header>

<main class="onboarding">
 <section class="onboarding-step" id="step-photos">
  <span class="eyebrow">PASO 1 DE 2</span>
  <h1>Enséñame cómo vistes, <?= e(explode(' ', $user['name'])[0]) ?>.</h1>
  <p class="onboarding-lead">Sube hasta <?= STYLE_PHOTO_MAX_COUNT ?> fotos: tuyas, o de looks que te gusten. Con una basta. El estilista las mira y escribe tu perfil, para que no tengas que rellenarlo tú.</p>
  <form id="style-form">
   <div class="style-slots">
    <?php for ($slot = 0; $slot < STYLE_PHOTO_MAX_COUNT; $slot++): ?>
    <label class="dropzone style-slot"><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp"><img class="style-preview" alt="" hidden><b>＋ Foto <?= $slot + 1 ?></b><span>JPG, PNG o WebP</span></label>
    <?php endfor ?>
   </div>
   <label class="style-note">¿Algo más que deba saber?<textarea name="note" maxlength="<?= STYLE_NOTE_MAX_LENGTH ?>" placeholder="Lo que no te pondrías nunca, tallas raras, presupuesto, para qué vistes cada día…"></textarea><small>Opcional. Lo que escribas aquí es lo único de lo que saldrá «qué evitar»: que algo no salga en tus fotos no significa que no te guste.</small></label>
   <button class="btn btn-coral full">Que el estilista lea mi estilo</button>
   <p class="form-error"></p>
  </form>
  <p class="onboarding-privacy">Tus fotos se envían al estilista para leer tu estilo y se borran en cuanto termina. No se publican, no se comparten y no aparecen en tu armario.</p>
  <button class="text-btn" id="onboarding-skip">Lo haré luego</button>
 </section>

 <section class="onboarding-step" id="step-reading" hidden>
  <span class="eyebrow">UN MOMENTO</span>
  <h1>Estoy mirando tus fotos.</h1>
  <div class="progress"><i></i></div>
  <p class="onboarding-lead" id="reading-phase">Preparando la lectura…</p>
 </section>

 <section class="onboarding-step" id="step-profile" hidden>
  <span class="eyebrow">PASO 2 DE 2</span>
  <h1>Esto es lo que he visto.</h1>
  <p class="onboarding-lead" id="reading-summary"></p>
  <p class="onboarding-hint">Cámbialo si no te reconoces: es tu perfil, y el estilista trabajará con lo que quede aquí.</p>
  <form id="onboarding-profile" class="profile-form">
   <label>Tu estilo<textarea name="style" placeholder="Minimalista, casual sofisticado, urbano…"></textarea></label>
   <label>Colores que te gustan<input name="colors" placeholder="Verde, negro, tonos tierra…"></label>
   <label>Ocasiones frecuentes<input name="occasions" placeholder="Trabajo, fin de semana, cenas…"></label>
   <label>Evitar<input name="avoid" placeholder="Estampados, prendas ajustadas…"></label>
   <button class="btn btn-dark">Guardar y entrar en mi armario</button>
   <p class="form-error"></p>
  </form>
 </section>
</main>

<div id="toast" role="status"></div><script>window.APP=<?= json_encode(['csrf' => csrf_token(), 'next' => $next], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script><script src="<?= asset('/assets/app.js') ?>"></script></body></html><?php
    exit;
}
