#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/OpenAI.php';
require APP_ROOT . '/app/Analysis.php';

/** Thrown when the user asked to stop a run, so the catch below can tell it from a failure. */
final class JobCancelled extends RuntimeException {}

/**
 * The analysis: read the wardrobe, ask the stylist. It does not draw anything any more.
 * Generating all five outfit images here meant paying for five pictures on every run, most
 * of which nobody opened; run_outfit_image() draws one when someone asks for it.
 */
function run_wardrobe_analysis(PDO $pdo, OpenAI $ai, array $job, callable $announce, callable $stopIfCancelled): array {
    $garments = analysis_garments($pdo, (int) $job['user_id']);
    if (count($garments) < 2) throw new RuntimeException('Añade al menos dos prendas antes de analizar.');
    $input = json_decode($job['input_json'], true) ?: [];
    // Jobs queued before the shopping flag existed stored the profile flat and always searched.
    $preferences = is_array($input['preferences'] ?? null) ? $input['preferences'] : $input;
    $withShopping = (bool) ($input['shopping'] ?? true);
    // The wardrobe can have moved between queueing and picking the job up. Record what was
    // really sent, or a later identical request would reuse an answer about other clothes.
    $pdo->prepare('UPDATE jobs SET fingerprint=? WHERE id=?')->execute([analysis_fingerprint($garments, $input), $job['id']]);
    $stopIfCancelled();
    $announce(count($garments) . ' prendas leídas · ' . ($withShopping ? 'buscando combinaciones y compras' : 'buscando combinaciones'), 30);
    $result = $ai->analyze($garments, $preferences, $withShopping);
    $stopIfCancelled();
    return $result;
}

/** One picture, for the one look whose card someone pressed. */
function run_outfit_image(PDO $pdo, OpenAI $ai, array $job, callable $announce, callable $stopIfCancelled): array {
    $input = json_decode($job['input_json'], true) ?: [];
    $analysisId = (int) ($input['analysis_job_id'] ?? 0);
    $index = (int) ($input['outfit_index'] ?? -1);

    $stmt = $pdo->prepare("SELECT result_json FROM jobs WHERE id=? AND user_id=? AND type='wardrobe_analysis' AND status='completed'");
    $stmt->execute([$analysisId, (int) $job['user_id']]);
    $outfit = (json_decode((string) $stmt->fetchColumn(), true)['outfits'] ?? [])[$index] ?? null;
    if (!is_array($outfit)) throw new RuntimeException('Ese look ya no existe.');

    $stopIfCancelled();
    $announce('Dibujando ' . ($outfit['name'] ?? 'el look'), 40);
    $image = $ai->outfitImage($outfit, analysis_garments($pdo, (int) $job['user_id']));
    if ($image === null) throw new RuntimeException('La IA no devolvió ninguna imagen.');

    $name = 'job-' . $analysisId . '-outfit-' . ($index + 1) . '.png';
    if (file_put_contents(APP_ROOT . '/storage/outfits/' . $name, base64_decode($image, true)) === false) {
        throw new RuntimeException('No se pudo guardar la imagen.');
    }
    $url = '/outfit-image.php?job=' . $analysisId . '&file=' . rawurlencode($name);

    // The analysis result is a shared document from here on, so re-read it under a lock
    // rather than patching the copy this job started with.
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT result_json FROM jobs WHERE id=? FOR UPDATE');
        $stmt->execute([$analysisId]);
        $current = json_decode((string) $stmt->fetchColumn(), true) ?: [];
        if (isset($current['outfits'][$index])) {
            $current['outfits'][$index]['image_url'] = $url;
            unset($current['outfits'][$index]['image_error']);
            $pdo->prepare('UPDATE jobs SET result_json=? WHERE id=?')
                ->execute([json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $analysisId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    return ['image_url' => $url];
}

$once = in_array('--once', $argv, true);
do {
    $pdo = db();
    $pdo->beginTransaction();
    $job = $pdo->query("SELECT * FROM jobs WHERE status='queued' ORDER BY id LIMIT 1")->fetch();
    if ($job) {
        $stmt = $pdo->prepare("UPDATE jobs SET status='processing', progress=10, phase='Leyendo tu armario', started_at=:now WHERE id=:id AND status='queued'");
        $stmt->execute(['now' => date(DATE_ATOM), 'id' => $job['id']]);
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
        $ai = new OpenAI($key);
        $result = $job['type'] === 'outfit_image'
            ? run_outfit_image($pdo, $ai, $job, $announce, $stopIfCancelled)
            : run_wardrobe_analysis($pdo, $ai, $job, $announce, $stopIfCancelled);
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
