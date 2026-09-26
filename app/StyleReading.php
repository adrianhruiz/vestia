<?php
declare(strict_types=1);

/**
 * The photos somebody hands over so the stylist can describe their style, and what this app
 * is allowed to do with them.
 *
 * Until now every image here was of a garment. These are of people: a face, a body, a living
 * room behind them. That is why this is its own file rather than a few more lines in
 * Images.php -- how long they live, who can reach them and how many there can be is decided
 * in one place, where it can be checked in one read.
 *
 * The rules: they never land under public/, nothing serves them over HTTP, the worker
 * deletes them the moment the reading ends however it ends, and whatever a run that died
 * halfway leaves behind is swept by style_photos_purge().
 */

/**
 * Photos one reading may carry.
 *
 * One is enough to name a style, so one is what the screen requires. Three is where the next
 * photo stops showing the stylist anything the first two did not, and every one of them is
 * paid for twice: in the bytes of the request and in the input tokens they become.
 */
const STYLE_PHOTO_MAX_COUNT = 3;

/** The same ceiling a garment photo gets: it is the same phone producing it. */
const STYLE_PHOTO_MAX_BYTES = 8 * 1024 * 1024;

const STYLE_PHOTO_FORMATS = ['image/jpeg', 'image/png', 'image/webp'];

/** Outside public/, so no URL reaches them even by accident. */
const STYLE_PHOTO_DIR = APP_ROOT . '/storage/style';

/**
 * How long a photo may outlive the job that was meant to consume it.
 *
 * In the ordinary case this never applies: the worker deletes them itself as the reading
 * ends. It is for the run that never ended -- a worker killed mid-call, a queued job whose
 * worker was down all night. Anything that old is of no use to anybody and is exactly the
 * kind of file that quietly accumulates for a year.
 */
const STYLE_PHOTO_RETENTION_HOURS = 24;

/**
 * Readings one account may ask for in a day.
 *
 * It is the most expensive thing a brand-new account can do and the first thing it is
 * offered, which is precisely the shape of what a script wants. Two rather than one because
 * the first can come back wrong, and reading a style is not something anyone needs to do
 * again this week.
 */
const STYLE_READINGS_PER_DAY = 2;

/** What the person adds in their own words. Capped like every other text that becomes prompt. */
const STYLE_NOTE_MAX_LENGTH = STYLE_PROFILE_MAX_LENGTH;

/**
 * The profile fields a reading is allowed to fill.
 *
 * Deliberately not STYLE_PROFILE_KEYS. A photograph cannot answer a size or a budget, and a
 * model asked for one anyway returns "M" and "hasta 120 €" that the person then reads as
 * something we knew about them. Naming the set here rather than accepting whatever comes
 * back means a key added to the profile later does not silently become something the photos
 * are expected to answer. OpenAI::readStyle() builds its schema from this same list, so what
 * is asked for and what is accepted cannot drift apart.
 */
const STYLE_READING_KEYS = ['style', 'colors', 'occasions', 'avoid'];

/** The directory, created on first use so a fresh checkout needs no setup step. */
function style_photo_dir(): string {
    if (!is_dir(STYLE_PHOTO_DIR)) @mkdir(STYLE_PHOTO_DIR, 0775, true);
    return STYLE_PHOTO_DIR;
}

/** A new, unguessable name for one of $userId's photos. The prefix is what makes them findable again. */
function style_photo_name(int $userId): string {
    return $userId . '-' . bin2hex(random_bytes(12)) . '.jpg';
}

/**
 * The file behind a stored name, or null when the name is not one of ours or the file is
 * gone.
 *
 * The names travel inside jobs.input_json, written by the endpoint and read by the worker,
 * never by a request. Even so, nothing but a name this module itself produced gets through:
 * a job row is a string in a database, and "../../.env" arriving at unlink() would only need
 * one mistake somewhere else to become reachable.
 */
function style_photo_path(string $name): ?string {
    if (!preg_match('/^[0-9]+-[0-9a-f]{24}\.jpg$/', $name)) return null;
    $file = STYLE_PHOTO_DIR . '/' . $name;
    return is_file($file) ? $file : null;
}

/** Every photo $userId still has on disk. */
function style_photos_of_user(int $userId): array {
    return array_map('basename', glob(STYLE_PHOTO_DIR . '/' . $userId . '-*.jpg') ?: []);
}

/** Deletes stored photos. A name that is not ours is skipped, never trusted. */
function style_photos_delete(array $names): void {
    foreach ($names as $name) {
        if (!is_string($name)) continue;
        $file = style_photo_path($name);
        if ($file !== null) @unlink($file);
    }
}

/**
 * Deletes the photos no job is going to read any more, and answers how many. Called where
 * new ones are stored, the way record_signup() prunes its own table: it keeps the directory
 * self-maintaining without a timer to install and forget.
 */
function style_photos_purge(): int {
    $deadline = time() - STYLE_PHOTO_RETENTION_HOURS * 3600;
    $removed = 0;
    foreach (glob(STYLE_PHOTO_DIR . '/*.jpg') ?: [] as $file) {
        if ((int) @filemtime($file) > $deadline) continue;
        if (@unlink($file)) $removed++;
    }
    return $removed;
}

/**
 * The MIME type of an upload we are willing to read, or null for anything else. Read from
 * the bytes rather than from what the browser called the file.
 */
function style_photo_format(string $file): ?string {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    return in_array($mime, STYLE_PHOTO_FORMATS, true) ? (string) $mime : null;
}

/**
 * The readings this account has spent in the last day, newest first.
 *
 * Counted in PHP rather than in SQL, for the reason recent_analyses() gives: created_at is
 * an ISO-8601 string carrying a local offset, and comparing those as strings silently
 * reverses order across the two days a year the clocks change.
 *
 * Failed runs do not count. The person got nothing out of one, and their photos were deleted
 * with it, so the only way forward is to upload again -- charging them for that would leave
 * an account unable to do the one thing it was just invited to do.
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
 * The job payload: the photos to read and the note to read them with. One function so the
 * endpoint that writes it and the worker that reads it cannot drift apart.
 */
function style_reading_input(array $names, string $note): array {
    return [
        'photos' => array_values(array_filter($names, 'is_string')),
        'note' => mb_substr(trim($note), 0, STYLE_NOTE_MAX_LENGTH),
    ];
}

/**
 * The stylist's answer, reduced to what is worth storing: the fields a reading may fill,
 * trimmed and capped by style_profile() like anything else that reaches the profile, plus
 * the sentence the screen shows so the verdict is not a set of boxes that filled themselves.
 *
 * Empty fields are dropped, which is the whole point -- something the photos could not
 * answer has to reach the form empty rather than filled with a guess.
 */
function style_reading_result(array $answer): array {
    $summary = mb_substr(trim((string) ($answer['summary'] ?? '')), 0, STYLE_PROFILE_MAX_LENGTH);
    $profile = array_intersect_key(style_profile($answer), array_flip(STYLE_READING_KEYS));
    return $summary === '' ? $profile : ['summary' => $summary] + $profile;
}
