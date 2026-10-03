<?php
declare(strict_types=1);

/**
 * The vocabulary of a request to the stylist: the occasions this app knows by name, and the
 * ones each person adds for themselves.
 *
 * It is deliberately separate from users.style_profile. A profile says who somebody is and
 * barely changes; an occasion says what they are doing today. Mixing the two is how
 * "Ocasiones frecuentes" ended up being asked at registration and answered with whatever
 * came to mind, and it is why the stylist had no way of being asked for a look for a coffee
 * in particular.
 */

/**
 * The occasions that ship with the app, slug => label and the sentence the model gets.
 *
 * The sentence is the reason this is a map and not a list of words. "Café" on its own is
 * ambiguous enough that a stylist would ask, and a model that cannot ask invents; one line of
 * what the occasion actually means costs nothing per request and is the difference between a
 * look for a coffee and a look for a word.
 *
 * Slugs are what travels in requests and what is stored, so they are the part that may not
 * change once published: a stored brief naming 'cafe' has to keep resolving after the label
 * above it is reworded.
 */
const STYLE_PRESETS = [
    'cafe' => ['label' => 'Café', 'guidance' => 'un café o un plan de tarde sin planes: cómodo pero arreglado, nada de etiqueta'],
    'fiesta' => ['label' => 'Fiesta', 'guidance' => 'una fiesta o una noche fuera: se puede arriesgar más con brillos, cortes y color'],
    'deporte' => ['label' => 'Deporte', 'guidance' => 'entrenar o moverse: prima el tejido técnico, la comodidad y el calzado deportivo'],
    'oficina' => ['label' => 'Oficina', 'guidance' => 'un día de trabajo de oficina: sobrio y cómodo para ocho horas, sin disfraz'],
    'cita' => ['label' => 'Cita', 'guidance' => 'una cita: arreglado y favorecedor sin parecer que se ha intentado demasiado'],
    'viaje' => ['label' => 'Viaje', 'guidance' => 'un día de viaje o aeropuerto: capas, tejidos que no se arrugan y calzado para andar'],
    'casa' => ['label' => 'Finde en casa', 'guidance' => 'un fin de semana por casa o un recado cerca: comodidad por encima de todo'],
    'evento' => ['label' => 'Evento', 'guidance' => 'un evento con cierta etiqueta -- una boda, una cena formal, una celebración'],
];

/** Longest an occasion somebody writes may be. It is a word or two, never a sentence. */
const STYLE_LABEL_MAX_LENGTH = 40;

/** How many styles of their own one account may keep. */
const USER_STYLES_MAX = 12;

/**
 * One line of what somebody typed, fit to be stored and shown.
 *
 * Everything that is not a label gets taken out here rather than checked for later: control
 * characters, newlines and runs of whitespace all collapse, because a chip is one line and a
 * brief that reaches the model has to be one line too. What is left is capped, and the cap is
 * on characters and not bytes -- "ñ" is one character of the forty somebody gets.
 */
function style_label_clean(string $raw): string {
    $clean = preg_replace('/[\p{C}\s]+/u', ' ', $raw) ?? '';
    return mb_substr(trim($clean), 0, STYLE_LABEL_MAX_LENGTH);
}

/**
 * The form of a label that decides whether two of them are the same style.
 *
 * Lowercase and without accents, so "Biblioteca", "biblioteca" and "BIBLIOTECA" are one chip,
 * and so is "cafe" against the "Café" that ships with the app. Transliterating via iconv
 * rather than a hand-written table: it is in every PHP build this app runs on, and the
 * //TRANSLIT target degrades to something harmless rather than throwing when a character has
 * no ASCII form. Whatever survives is lowercased, which is the comparison we actually want.
 */
function style_canonical(string $label): string {
    $clean = style_label_clean($label);
    if ($clean === '') return '';
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $clean);
    // iconv answers false on a string it cannot map at all; the label itself is the fallback,
    // folded, which still makes two spellings of the same word match each other.
    return mb_strtolower($ascii === false ? $clean : $ascii);
}

/** The preset a written label is really asking for, or null when it is something new. */
function style_preset_slug(string $label): ?string {
    $canonical = style_canonical($label);
    if ($canonical === '') return null;
    foreach (STYLE_PRESETS as $slug => $preset) {
        if (style_canonical($preset['label']) === $canonical) return $slug;
    }
    return null;
}

/**
 * The styles this account has named, the most recently used first.
 *
 * A style saved and never used sorts after every style that has been, which is what
 * last_used_at IS NULL does in that ORDER BY, and id breaks the remaining tie so the order is
 * stable between two calls that see the same rows.
 */
function user_styles(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT id,label FROM user_styles WHERE user_id=? ORDER BY last_used_at IS NULL, last_used_at DESC, id DESC');
    $stmt->execute([$userId]);
    return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'label' => (string) $row['label']], $stmt->fetchAll());
}

/**
 * Saves a style somebody named, and says what it did.
 *
 * Four answers, and only one of them is a failure the caller turns into an error:
 *
 *   ['error' => 'empty']        nothing readable was typed
 *   ['error' => 'full']         the account is at USER_STYLES_MAX
 *   ['preset' => 'cafe']        they wrote a name this app already has; use that chip
 *   ['style' => [...], 'existing' => bool]   saved, or already there
 *
 * "Already there" is not an error on purpose: somebody who types a style they saved last week
 * wanted to select it, and that is what they get. The UNIQUE key is what makes that true even
 * if two requests race, so the duplicate is read back rather than guessed at.
 */
function user_style_create(PDO $pdo, int $userId, string $raw): array {
    $label = style_label_clean($raw);
    if ($label === '') return ['error' => 'empty'];

    $preset = style_preset_slug($label);
    if ($preset !== null) return ['preset' => $preset];

    $canonical = style_canonical($label);
    $existing = $pdo->prepare('SELECT id,label FROM user_styles WHERE user_id=? AND canonical=?');
    $existing->execute([$userId, $canonical]);
    if ($row = $existing->fetch()) return ['style' => ['id' => (int) $row['id'], 'label' => (string) $row['label']], 'existing' => true];

    // Counted after the duplicate check, so a full list can still answer with the chip
    // somebody already has instead of refusing to look it up.
    $count = $pdo->prepare('SELECT COUNT(*) FROM user_styles WHERE user_id=?');
    $count->execute([$userId]);
    if ((int) $count->fetchColumn() >= USER_STYLES_MAX) return ['error' => 'full'];

    $pdo->prepare('INSERT INTO user_styles(user_id,label,canonical,created_at) VALUES(?,?,?,?)')
        ->execute([$userId, $label, $canonical, date(DATE_ATOM)]);
    return ['style' => ['id' => (int) $pdo->lastInsertId(), 'label' => $label], 'existing' => false];
}

/** Forgets a style. False when this account had no such style to forget. */
function user_style_delete(PDO $pdo, int $userId, int $styleId): bool {
    $stmt = $pdo->prepare('DELETE FROM user_styles WHERE id=? AND user_id=?');
    $stmt->execute([$styleId, $userId]);
    return $stmt->rowCount() > 0;
}

/** One of this account's styles by id, or null. The user_id in the WHERE is the whole point. */
function user_style_label(PDO $pdo, int $userId, int $styleId): ?string {
    $stmt = $pdo->prepare('SELECT label FROM user_styles WHERE id=? AND user_id=?');
    $stmt->execute([$styleId, $userId]);
    $label = $stmt->fetchColumn();
    return $label === false ? null : (string) $label;
}

/** Notes that a style was just used, which is what moves its chip to the front. */
function user_style_touch(PDO $pdo, int $userId, int $styleId): void {
    $pdo->prepare('UPDATE user_styles SET last_used_at=? WHERE id=? AND user_id=?')->execute([date(DATE_ATOM), $styleId, $userId]);
}
