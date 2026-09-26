<?php
declare(strict_types=1);

/**
 * The stylist reading somebody's style off the looks they put together.
 *
 * This file used to be about photographs of people -- a face, a body, a living room behind
 * them -- and most of it was the rules for holding such a thing: where it could live, who
 * could reach it, how few of them there could be, how fast they had to be destroyed. None of
 * that is here any more, because nothing is uploaded any more. The input is two outfits from
 * the person's own wardrobe (app/Outfits.php), which is data this app already keeps for its
 * own reasons and does not have to apologise for.
 *
 * What is left is the same in shape as before: a queued job, a limit on how often it may be
 * asked for, and a verdict that lands in the form filled in rather than being saved behind
 * anybody's back. A machine describing your style and being half right, with no way to
 * argue, is a bad first thing to happen to you.
 */

/**
 * Readings one account may ask for in a day.
 *
 * It is the most expensive thing a brand-new account can do and it is offered as soon as two
 * looks exist, which is precisely the shape of what a script wants. Two rather than one
 * because the first can come back wrong, and reading a style is not something anyone needs
 * to do again this week.
 */
const STYLE_READINGS_PER_DAY = 2;

/**
 * The profile fields a reading is allowed to fill.
 *
 * Deliberately not STYLE_PROFILE_KEYS, and shorter than it by one. Clothes cannot answer a
 * size or a budget -- neither is in the profile any more for that reason -- and they cannot
 * answer `avoid` either: two looks somebody owns say nothing about what they would refuse to
 * wear, and `avoid` is the field that decides what the stylist will never propose again.
 * Filling it from an absence would quietly forbid every kind of garment nobody happened to
 * group. It reaches the form empty, for the person to write themselves.
 *
 * OpenAI::readStyle() builds its schema from this same list, so what is asked for and what is
 * accepted cannot drift apart.
 */
const STYLE_READING_KEYS = ['style', 'colors', 'occasions'];

/** How much of a per-garment note the stylist needs. The same cap the wardrobe analysis uses. */
const STYLE_READING_NOTES_MAX_LENGTH = 160;

/**
 * The readings this account has spent in the last day, newest first.
 *
 * Counted in PHP rather than in SQL, for the reason recent_analyses() gives: created_at is
 * an ISO-8601 string carrying a local offset, and comparing those as strings silently
 * reverses order across the two days a year the clocks change.
 *
 * Failed runs do not count. The person got nothing out of one, so charging them for it would
 * leave an account unable to do the one thing it was just invited to do.
 */
function recent_style_readings(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT created_at FROM jobs WHERE user_id=? AND type='style_reading' AND status<>'failed' ORDER BY id DESC LIMIT 20");
    $stmt->execute([$userId]);

    $since = new DateTimeImmutable('-1 day');
    $recent = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
        try { $at = new DateTimeImmutable((string) $value); } catch (Exception) { continue; }
        if ($at >= $since) $recent[] = $at;
    }
    return $recent;
}

/**
 * Seconds until this account may ask for another reading, or null when it may right now.
 *
 * Exempt accounts are the ones analysis_limit_exempt() already knows about: a test wardrobe
 * runs these back to back by design, and having to remember two separate exemption lists is
 * how one of them ends up wrong.
 */
function style_reading_retry_after(PDO $pdo, array $user): ?int {
    if (analysis_limit_exempt($user)) return null;
    $recent = recent_style_readings($pdo, (int) ($user['id'] ?? 0));
    if (count($recent) < STYLE_READINGS_PER_DAY) return null;
    // The oldest one inside the window is the one whose slot frees up first.
    return max(1, end($recent)->getTimestamp() + 86400 - time());
}

/**
 * The reading this account already has in flight or already finished, or null.
 *
 * One row does three jobs: it stops the second press buying a second reading, it is what the
 * wardrobe panel resumes from after a reload in the middle of a run, and once it is
 * completed it is where the verdict is read back from, since the verdict is not saved
 * anywhere else until the person accepts it.
 */
function latest_style_reading(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT id,status,progress,phase,result_json,error FROM jobs WHERE user_id=? AND type='style_reading' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $job = $stmt->fetch();
    if (!$job) return null;
    $job['id'] = (int) $job['id'];
    $job['result'] = $job['result_json'] ? json_decode((string) $job['result_json'], true) : null;
    unset($job['result_json']);
    return $job;
}

/**
 * The job payload: the looks to read, reduced to what the stylist can use.
 *
 * Written here and read by the worker, so the two cannot drift apart. It is a snapshot on
 * purpose -- the job is the record of what was actually read, and a look edited while the
 * reading was queued must not change the meaning of an answer that is already coming back.
 *
 * Ids, photos and prices are dropped. The stylist is being asked what these clothes say
 * about a person, and an id says nothing; nothing here is ever matched back to a row.
 */
function style_reading_input(array $outfits): array {
    $looks = [];
    // The first two and no more, which is the same set outfits_ready() judges. Somebody with
    // ten grouped looks would be a better read and a much more expensive one, and two is what
    // the instructions in readStyle() are written around.
    foreach (array_slice($outfits, 0, ONBOARDING_OUTFITS) as $outfit) {
        $garments = [];
        foreach ($outfit['garments'] ?? [] as $garment) {
            $garments[] = array_filter([
                'name' => (string) ($garment['name'] ?? ''),
                'brand' => (string) ($garment['brand'] ?? ''),
                'category' => (string) ($garment['category'] ?? ''),
                'color' => (string) ($garment['color'] ?? ''),
                'size' => (string) ($garment['size'] ?? ''),
                'season' => (string) ($garment['season'] ?? ''),
                'notes' => mb_substr(trim((string) ($garment['notes'] ?? '')), 0, STYLE_READING_NOTES_MAX_LENGTH),
            ], static fn(string $value): bool => $value !== '');
        }
        if ($garments) $looks[] = ['name' => (string) ($outfit['name'] ?? ''), 'garments' => $garments];
    }
    return ['outfits' => $looks];
}

/**
 * Queues one reading of these looks and answers the id of the job that will carry it.
 *
 * The caller decides whether it may: the limit, whether one is already in flight and whether
 * the welcome is still owed are all policy, and they are asked in the endpoint and in
 * onboarding_advance() rather than hidden in here. This writes the row.
 */
function style_reading_queue(PDO $pdo, int $userId, array $outfits): int {
    $stmt = $pdo->prepare("INSERT INTO jobs(user_id,type,status,input_json,created_at) VALUES(?,'style_reading','queued',?,?)");
    $stmt->execute([$userId, json_encode(style_reading_input($outfits), JSON_UNESCAPED_UNICODE), date(DATE_ATOM)]);
    // Read on the line after the INSERT and nowhere further down: lastInsertId() belongs to
    // the connection, not to this statement.
    return (int) $pdo->lastInsertId();
}

/**
 * The stylist's answer, reduced to what is worth storing: the fields a reading may fill,
 * trimmed and capped by style_profile() like anything else that reaches the profile, plus
 * the sentence the screen shows so the verdict is not a set of boxes that filled themselves.
 *
 * Empty fields are dropped, which is the whole point -- something the clothes could not
 * answer has to reach the form empty rather than filled with a guess.
 */
function style_reading_result(array $answer): array {
    $summary = mb_substr(trim((string) ($answer['summary'] ?? '')), 0, STYLE_PROFILE_MAX_LENGTH);
    $profile = array_intersect_key(style_profile($answer), array_flip(STYLE_READING_KEYS));
    return $summary === '' ? $profile : ['summary' => $summary] + $profile;
}
