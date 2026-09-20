#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/OpenAI.php';

$once = in_array('--once', $argv, true);
do {
    $pdo = db();
    $pdo->beginTransaction();
    $job = $pdo->query("SELECT * FROM jobs WHERE status='queued' ORDER BY id LIMIT 1")->fetch();
    if ($job) {
        $stmt = $pdo->prepare("UPDATE jobs SET status='processing', progress=10, started_at=:now WHERE id=:id AND status='queued'");
        $stmt->execute(['now' => date(DATE_ATOM), 'id' => $job['id']]);
        if ($stmt->rowCount() !== 1) $job = false;
    }
    $pdo->commit();
    if (!$job) { if ($once) break; sleep(3); continue; }
    try {
        load_env(APP_ROOT . '/.env');
        $key = env('OPENAI_API_KEY', '');
        if ($key === '') throw new RuntimeException('Configura OPENAI_API_KEY en .env.');
        $limit = max(1, (int)env('ANALYSIS_MAX_ITEMS', '40'));
        $stmt = $pdo->prepare('SELECT id,name,brand,reference,category,color,size,season,notes,image_path FROM garments WHERE user_id=? ORDER BY favorite DESC, created_at DESC LIMIT ?');
        $stmt->bindValue(1, (int)$job['user_id'], PDO::PARAM_INT); $stmt->bindValue(2, $limit, PDO::PARAM_INT); $stmt->execute();
        $garments = $stmt->fetchAll();
        if (count($garments) < 2) throw new RuntimeException('Añade al menos dos prendas antes de analizar.');
        $input = json_decode($job['input_json'], true) ?: [];
        $ai = new OpenAI($key);
        $result = $ai->analyze($garments, $input);
        $pdo->prepare('UPDATE jobs SET progress=70 WHERE id=?')->execute([$job['id']]);
        foreach ($result['outfits'] as $index => &$outfit) {
            try {
                $image = $ai->outfitImage($outfit, $garments);
                if ($image) {
                    $name = 'job-' . $job['id'] . '-outfit-' . ($index + 1) . '.png';
                    file_put_contents(APP_ROOT . '/storage/outfits/' . $name, base64_decode($image, true));
                    $outfit['image_url'] = '/outfit-image.php?job=' . $job['id'] . '&file=' . rawurlencode($name);
                }
            } catch (Throwable $imageError) { $outfit['image_error'] = $imageError->getMessage(); }
            $progress = 70 + (int)(25 * (($index + 1) / max(1, count($result['outfits']))));
            $pdo->prepare('UPDATE jobs SET progress=? WHERE id=?')->execute([$progress, $job['id']]);
        }
        unset($outfit);
        $pdo->prepare("UPDATE jobs SET status='completed', progress=100, result_json=?, finished_at=? WHERE id=?")
            ->execute([json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date(DATE_ATOM), $job['id']]);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE jobs SET status='failed', error=?, finished_at=? WHERE id=?")->execute([$e->getMessage(), date(DATE_ATOM), $job['id']]);
        file_put_contents(APP_ROOT . '/storage/logs/worker.log', date(DATE_ATOM) . ' ' . $e . PHP_EOL, FILE_APPEND);
    }
} while (true);
