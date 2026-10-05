#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/OpenAI.php';
require APP_ROOT . '/app/Styles.php';
require APP_ROOT . '/app/Analysis.php';
require APP_ROOT . '/app/StyleReading.php';

/** Thrown when the user asked to stop a run, so the catch below can tell it from a failure. */
final class JobCancelled extends RuntimeException {}

/** The analysis: read the wardrobe, ask the stylist. It does not draw anything. */
function run_wardrobe_analysis(PDO $pdo, OpenAI $ai, array $job, callable $announce, callable $stopIfCancelled): array {
    $garments = analysis_garments($pdo, (int) $job['user_id']);
    $input = json_decode($job['input_json'], true) ?: [];
    // Jobs queued before the shopping flag existed stored the profile flat and always searched.
    $preferences = is_array($input['preferences'] ?? null) ? $input['preferences'] : $input;
    $brief = analysis_brief_stored($input);
    // Asked again here and not only at the endpoint: the wardrobe can have emptied between
    // queueing and picking the job up, and how many garments a brief needs depends on it.
    $blocked = analysis_blocked($brief, count($garments));
    if ($blocked !== null) throw new RuntimeException($blocked);
    // The wardrobe can have moved between queueing and picking the job up. Record what was
    // really sent, or a later identical request would reuse an answer about other clothes.
    $pdo->prepare('UPDATE jobs SET fingerprint=? WHERE id=?')->execute([analysis_fingerprint($garments, $input), $job['id']]);
    $stopIfCancelled();
    $announce(analysis_phase($brief, count($garments)), 30);
    $result = $ai->analyze($garments, $preferences, $brief);
    $stopIfCancelled();
    return $result;
}

/**
 * The style reading: show the stylist two of this person's own outfits, keep the verdict.
 *
 * Nothing is deleted on the way out any more, and there is no finally clause, because nothing
 * was uploaded: the looks this reads were snapshotted into input_json when the job was queued
 * and they stay in the wardrobe afterwards. The verdict is not written to the profile here
 * either -- it travels back as a result the person accepts or edits first.
 */
function run_style_reading(PDO $pdo, OpenAI $ai, array $job, callable $announce, callable $stopIfCancelled): array {
    $input = json_decode($job['input_json'], true) ?: [];
    $outfits = array_values(array_filter((array) ($input['outfits'] ?? []), 'is_array'));
    // The snapshot is written by the endpoint that queues this, so an empty one means the
    // looks were taken apart between the queue and this line -- or that this job is older
    // than the outfits it was meant to read.
    if (!$outfits) throw new RuntimeException('Esos outfits ya no están completos. Vuelve a montarlos y pídeme la lectura otra vez.');

    $stopIfCancelled();
    $announce('Mirando tus ' . count($outfits) . ' outfits', 40);
    $answer = $ai->readStyle($outfits);
    $stopIfCancelled();
    return style_reading_result($answer);
}

/**
 * What the progress bar says once the wardrobe has been read: the brief, in one line.
 *
 * The honest progress of a run that is one slow call is the name of the step it is on, and
 * with three sources the step is no longer the same sentence every time. A look built by the
 * shops has not "read 40 garments" in any meaningful sense, so it does not claim to.
 */
function analysis_phase(array $brief, int $garments): string {
    $what = $brief['occasion'] === '' ? 'buscando combinaciones' : 'buscando looks para ' . mb_strtolower($brief['occasion']);
    return match ($brief['source']) {
        'shops' => $what . ' en las tiendas',
        'both' => $garments . ' prendas leídas · ' . $what . ' y compras',
        default => $garments . ' prendas leídas · ' . $what,
    };
}

/** What the bar says before the job has done anything. Each type starts somewhere different. */
function job_initial_phase(string $type): string {
    return $type === 'style_reading' ? 'Abriendo tus outfits' : 'Leyendo tu armario';
}

$once = in_array('--once', $argv, true);
do {
    $pdo = db();
    $pdo->beginTransaction();
    $job = $pdo->query("SELECT * FROM jobs WHERE status='queued' ORDER BY id LIMIT 1")->fetch();
    if ($job) {
        $stmt = $pdo->prepare("UPDATE jobs SET status='processing', progress=10, phase=:phase, started_at=:now WHERE id=:id AND status='queued'");
        $stmt->execute(['phase' => job_initial_phase((string) $job['type']), 'now' => date(DATE_ATOM), 'id' => $job['id']]);
        if ($stmt->rowCount() !== 1) $job = false;
    }
    $pdo->commit();
    if (!$job) { if ($once) break; sleep(3); continue; }
    // What the bar says and whether the user pulled the plug. The analysis is a handful of
    // slow calls, so the only honest progress is the name of the step it is on.
    $announce = static function (string $phase, int $progress) use ($pdo, $job): void {
        $pdo->prepare('UPDATE jobs SET phase=?, progress=? WHERE id=?')->execute([$phase, $progress, $job['id']]);
    };
    $stopIfCancelled = static function () use ($pdo, $job): void {
        $stmt = $pdo->prepare('SELECT cancel_requested FROM jobs WHERE id=?');
        $stmt->execute([$job['id']]);
        if ((int) $stmt->fetchColumn() === 1) throw new JobCancelled();
    };

    try {
        load_env(APP_ROOT . '/.env');
        $key = env('OPENAI_API_KEY', '');
        if ($key === '') throw new RuntimeException('Configura OPENAI_API_KEY en .env.');
        if (daily_job_ceiling_reached($pdo, (int) $job['id'])) {
            throw new RuntimeException('Hoy se ha alcanzado el tope de peticiones a la IA de este entorno. Vuelve a intentarlo mañana.');
        }
        require_once APP_ROOT . '/log/app/Capture.php';
        VestiaLogCapture::$context = ['environment' => basename(APP_ROOT), 'user_id' => (int)$job['user_id'], 'job_id' => (int)$job['id'], 'operation' => (string)$job['type']];
        $ai = new OpenAI($key);
        $result = match ($job['type']) {
            'style_reading' => run_style_reading($pdo, $ai, $job, $announce, $stopIfCancelled),
            default => run_wardrobe_analysis($pdo, $ai, $job, $announce, $stopIfCancelled),
        };
        $pdo->prepare("UPDATE jobs SET status='completed', progress=100, phase=NULL, result_json=?, finished_at=? WHERE id=?")
            ->execute([json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date(DATE_ATOM), $job['id']]);
    } catch (JobCancelled) {
        $pdo->prepare("UPDATE jobs SET status='cancelled', phase=NULL, finished_at=? WHERE id=?")
            ->execute([date(DATE_ATOM), $job['id']]);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE jobs SET status='failed', phase=NULL, error=?, finished_at=? WHERE id=?")->execute([$e->getMessage(), date(DATE_ATOM), $job['id']]);
        file_put_contents(APP_ROOT . '/storage/logs/worker.log', date(DATE_ATOM) . ' ' . $e . PHP_EOL, FILE_APPEND);
    }
} while (true);
