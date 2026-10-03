<?php
declare(strict_types=1);

// analysis_brief() resolves an occasion against STYLE_PRESETS and against this account's own
// styles, so the vocabulary is a real dependency and not something a caller has to remember
// to load first.
require_once __DIR__ . '/Styles.php';

/**
 * How much of a free-text note the stylist needs. It is the only unbounded thing a person
 * writes per garment, and up to forty of them ride in every prompt.
 */
const ANALYSIS_NOTES_MAX_LENGTH = 160;

/**
 * The wardrobe exactly as the stylist sees it: same rows, same order, same fields. The API
 * and the worker both call this, so the two can never disagree about what was sent.
 *
 * The ordering carries an id tiebreaker on purpose. created_at only has second resolution,
 * and a seed inserts dozens of rows within the same second, so without it the same wardrobe
 * comes back in a different order from one call to the next.
 */
function analysis_garments(PDO $pdo, int $userId): array {
    $limit = max(1, (int) env('ANALYSIS_MAX_ITEMS', '40'));
    $stmt = $pdo->prepare('SELECT id,name,brand,reference,category,color,size,season,notes FROM garments WHERE user_id=? ORDER BY favorite DESC, created_at DESC, id DESC LIMIT ?');
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $garments = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['notes'] = mb_substr(trim((string) $row['notes']), 0, ANALYSIS_NOTES_MAX_LENGTH);
        // An empty colour or season is a key and a null the model has to read for nothing.
        $garments[] = array_filter($row, static fn($value): bool => $value !== null && $value !== '');
    }
    return $garments;
}

/** How much of a budget line the stylist needs. It is a figure and a word, never a paragraph. */
const ANALYSIS_BUDGET_MAX_LENGTH = 120;

/**
 * Where the clothes in a proposed look are allowed to come from.
 *
 * It used to be a boolean -- search the shops or do not -- which could say "only what I own"
 * and "what I own, plus what is missing" but had no way of saying "I do not want to use my
 * wardrobe today, dress me from the shops". That third answer is a different question for the
 * model, not a louder version of the second, so the flag became three named values.
 *
 * 'wardrobe' is first because it is the one that spends nothing: no web search, no tokens
 * paid for its results. Anything unrecognised lands there too.
 */
const ANALYSIS_SOURCES = ['wardrobe', 'both', 'shops'];

/** One of ANALYSIS_SOURCES, whatever arrived. An unknown value is not an error, it is 'wardrobe'. */
function analysis_source(mixed $value): string {
    return in_array($value, ANALYSIS_SOURCES, true) ? (string) $value : 'wardrobe';
}

/**
 * What this person is willing to spend completing a look, or '' when nothing is being bought.
 *
 * It used to be a box in the style profile, which is the wrong place: "hasta 120 EUR" is an
 * answer to what somebody is buying today, not a property of who they are, and asked at
 * registration -- before there was anything to complete -- it got a number nobody meant.
 *
 * Read only when the clothes may come from a shop. With 'wardrobe' nothing is being bought, so
 * a figure left over in the form has no business reaching the prompt, and none reaching the
 * fingerprint either: two runs that differ by an unused number are the same run.
 */
function analysis_budget(array $brief): string {
    if (analysis_source($brief['source'] ?? '') === 'wardrobe') return '';
    $budget = $brief['budget'] ?? '';
    if (!is_scalar($budget)) return '';
    return mb_substr(trim((string) $budget), 0, ANALYSIS_BUDGET_MAX_LENGTH);
}

/**
 * The request itself, normalised: what for, from where, and for how much.
 *
 * This is the one thing the stylist is asked that is about today rather than about the person,
 * which is why it travels apart from `preferences` all the way down -- into input_json, into
 * the fingerprint and into the prompt as data.
 *
 * An occasion arrives as a STYLE_PRESETS slug, as 'custom:<id>' naming one of this account's
 * own styles, or as nothing at all, which is the ordinary "dress me for my day" and the
 * behaviour this app had before briefs existed. What is kept is the *label*, never the id: a
 * style somebody deletes and names again is the same request, and the looks already saved
 * under it keep saying what they were for.
 *
 * The one write: choosing a style of your own marks it as used, which is what moves its chip
 * to the front of the row next time. It happens here because here is where the choice is
 * understood, and it is as true of a reused answer as of a fresh one -- they still asked for it.
 */
function analysis_brief(PDO $pdo, int $userId, array $body): array {
    $raw = is_array($body['brief'] ?? null) ? $body['brief'] : [];
    $source = analysis_source($raw['source'] ?? '');
    $brief = ['occasion' => '', 'guidance' => '', 'source' => $source, 'budget' => analysis_budget(['source' => $source, 'budget' => $raw['budget'] ?? ''])];

    // Key order is fixed across every branch and across analysis_brief_stored(): the
    // fingerprint hashes the JSON of this array, so two identical briefs written in a
    // different order would be two different questions.
    $occasion = is_scalar($raw['occasion'] ?? null) ? trim((string) $raw['occasion']) : '';
    if (isset(STYLE_PRESETS[$occasion])) {
        $brief['occasion'] = STYLE_PRESETS[$occasion]['label'];
        $brief['guidance'] = STYLE_PRESETS[$occasion]['guidance'];
        return $brief;
    }
    if (preg_match('/^custom:(\d+)$/', $occasion, $match)) {
        $styleId = (int) $match[1];
        $label = user_style_label($pdo, $userId, $styleId);
        // Deleted in another tab, or never theirs: the request is answered without an
        // occasion rather than refused, which is what asking for nothing already does.
        if ($label !== null) {
            $brief['occasion'] = $label;
            user_style_touch($pdo, $userId, $styleId);
        }
    }
    return $brief;
}

/**
 * The brief of a job that has already been queued, read back from its input_json.
 *
 * Two shapes reach this. A job queued since briefs exist has one under 'brief'. A job queued
 * before has a shopping boolean and a budget at the top level, and it is still in the last ten
 * rows the history shows, so it is read rather than shown as blank: shopping on was today's
 * 'both'.
 */
function analysis_brief_stored(array $input): array {
    $raw = is_array($input['brief'] ?? null) ? $input['brief'] : ['source' => !empty($input['shopping']) ? 'both' : 'wardrobe', 'budget' => $input['budget'] ?? ''];
    $source = analysis_source($raw['source'] ?? '');
    return [
        'occasion' => is_scalar($raw['occasion'] ?? null) ? (string) $raw['occasion'] : '',
        'guidance' => is_scalar($raw['guidance'] ?? null) ? (string) $raw['guidance'] : '',
        'source' => $source,
        'budget' => analysis_budget(['source' => $source, 'budget' => $raw['budget'] ?? '']),
    ];
}

/**
 * Why this brief cannot be answered with this wardrobe, or null when it can.
 *
 * Two prendas has always been the floor, and for a look built out of what somebody owns it
 * still is -- one garment is not a combination. A look built by the shops does not need the
 * wardrobe at all, so an account with nothing in it may ask for one; what it must bring
 * instead is an occasion, because a stylist handed neither clothes nor a reason has been asked
 * for nothing in particular and will answer accordingly.
 *
 * Called by the endpoint, so a refusal costs no row, and again by the worker, because the
 * wardrobe can empty between queueing and picking the job up.
 */
function analysis_blocked(array $brief, int $garmentCount): ?string {
    if (($brief['source'] ?? '') === 'shops') {
        if (($brief['occasion'] ?? '') === '' && $garmentCount < 2) {
            return 'Para un look entero de tienda, elige un estilo o añade al menos dos prendas: sin una cosa ni la otra no sé para qué vestirte.';
        }
        return null;
    }
    return $garmentCount < 2 ? 'Añade al menos dos prendas.' : null;
}

/**
 * Bump when the instructions or the schema change enough that an old answer is no longer
 * the answer we would give today, so fingerprints from before stop matching.
 *
 * 2: the brief. The instructions gained an occasion and a third way of choosing where the
 * clothes come from, so every answer written before them was written to a different question.
 */
const ANALYSIS_PROMPT_VERSION = 2;

/**
 * Everything that decides what the stylist replies, in one hash. The garments are the rows
 * as they are actually sent, so editing a note or a colour changes the fingerprint on its
 * own — there is no separate "has the wardrobe changed" flag to keep in sync.
 */
function analysis_fingerprint(array $garments, array $input): string {
    return hash('sha256', (string) json_encode([
        'garments' => $garments,
        'input' => $input,
        'model' => env('OPENAI_MODEL', ''),
        'prompt' => ANALYSIS_PROMPT_VERSION,
    ], JSON_UNESCAPED_UNICODE));
}

/** How many analyses an ordinary account may start per hour. */
const ANALYSES_PER_HOUR = 2;

/**
 * The analyses this account has spent in the last hour, newest first.
 *
 * Counted in PHP rather than in SQL on purpose. created_at is an ISO-8601 string with a
 * local offset, so a string comparison silently reverses order across the two days a year
 * the clocks change; DateTimeImmutable reads the offset and gets it right. Reading the last
 * twenty rows costs nothing, and the limit itself keeps the window far below that.
 *
 * Failed runs are not counted: the person got nothing out of them. The global daily ceiling
 * is what stops a failure loop from draining the key.
 */
function recent_analyses(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT created_at FROM jobs WHERE user_id=? AND type='wardrobe_analysis' AND status<>'failed' ORDER BY id DESC LIMIT 20");
    $stmt->execute([$userId]);
    $since = new DateTimeImmutable('-1 hour');
    $recent = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
        try { $at = new DateTimeImmutable((string) $value); } catch (Exception) { continue; }
        if ($at >= $since) $recent[] = $at;
    }
    return $recent;
}

/** Test accounts run analyses back to back by design, so the limit does not apply to them. */
function analysis_limit_exempt(array $user): bool {
    if ((int) ($user['is_test'] ?? 0) === 1) return true;
    $exempt = array_filter(array_map('trim', explode(',', (string) env('RATE_LIMIT_EXEMPT_EMAILS', ''))));
    return in_array(strtolower((string) ($user['email'] ?? '')), array_map('strtolower', $exempt), true);
}

/** Seconds until this account may analyse again, or null when it may right now. */
function analysis_retry_after(PDO $pdo, array $user): ?int {
    if (analysis_limit_exempt($user)) return null;
    $recent = recent_analyses($pdo, (int) $user['id']);
    if (count($recent) < ANALYSES_PER_HOUR) return null;
    // The oldest one in the window is the one whose slot frees up first.
    return max(1, end($recent)->getTimestamp() + 3600 - time());
}

/**
 * Whether the installation has already spent its allowance of model calls today.
 *
 * The per-account hourly limit stops one person hammering the button; this is the backstop
 * for everything it does not cover — a worker stuck in a retry loop, a bug that queues jobs,
 * an account someone is abusing. Unset or 0 means no ceiling, which is the default so a
 * fresh checkout behaves as before.
 *
 * Matching started_at on a date prefix is safe: the column is a local ISO-8601 timestamp, so
 * the first ten characters are the local calendar day regardless of the offset.
 */
function daily_job_ceiling_reached(PDO $pdo, int $currentJobId): bool {
    $ceiling = (int) env('OPENAI_MAX_JOBS_PER_DAY', '0');
    if ($ceiling <= 0) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE started_at LIKE ? AND status<>'failed' AND id<>?");
    $stmt->execute([date('Y-m-d') . '%', $currentJobId]);
    return (int) $stmt->fetchColumn() >= $ceiling;
}
