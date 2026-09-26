<?php
declare(strict_types=1);

final class VestiaLogCapture {
    public static array $context = [];
    public static function db(): PDO {
        $db = new PDO('sqlite:' . dirname(__DIR__) . '/storage/events.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout=1500');
        return $db;
    }
    public static function sanitize(mixed $value, string $key = '', string $secret = ''): mixed {
        if (preg_match('/^(authorization|api[_-]?key|password|password_hash|cookie|set-cookie|access_token|secret)$/i', $key)) return '[oculto]';
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) $out[$k] = self::sanitize($v, (string)$k, $secret);
            return $out;
        }
        if (!is_string($value)) return $value;
        if (str_starts_with($value, 'data:image/') || ($key === 'result' && strlen($value) > 1000)) {
            $bytes = str_starts_with($value, 'data:') ? substr($value, (int)strpos($value, ',') + 1) : $value;
            $decoded = base64_decode($bytes, true);
            return ['omitted' => 'Contenido de imagen omitido; no se conserva una copia en el registro.', 'bytes' => strlen($decoded === false ? $bytes : $decoded), 'sha256' => hash('sha256', $decoded === false ? $bytes : $decoded)];
        }
        if ($secret !== '') $value = str_replace($secret, '[clave oculta]', $value);
        return preg_replace('/\bsk-[A-Za-z0-9_-]{16,}/', '[clave oculta]', $value);
    }
    public static function start(string $path, array $payload, string $secret): ?string {
        try {
            $ctx = self::$context;
            if (!in_array($ctx['environment'] ?? '', ['raul', 'adrian'], true) || empty($ctx['user_id'])) return null;
            $id = bin2hex(random_bytes(16));
            $db = self::db();
            $stmt = $db->prepare('INSERT INTO calls(id,environment,user_id,job_id,operation,started_at,status,model,request_json,prompt_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $safe = self::sanitize($payload, '', $secret);
            $prompt = [$payload['instructions'] ?? '', $payload['text'] ?? [], $payload['tools'] ?? []];
            $stmt->execute([$id,$ctx['environment'],$ctx['user_id'],$ctx['job_id'],$ctx['operation'],gmdate('c'),'processing',$payload['model'] ?? '',json_encode(['endpoint'=>$path,'payload'=>$safe],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),substr(hash('sha256',json_encode($prompt)),0,12)]);
            return $id;
        } catch (Throwable $e) { error_log('Vestia log: no se pudo registrar el inicio de una llamada.'); return null; }
    }
    public static function finish(?string $id, mixed $data, int $http, float $duration, ?string $error, string $requestId, string $secret): void {
        if ($id === null) return;
        try {
            $safe = self::sanitize($data, '', $secret);
            $usage = is_array($data) ? ($data['usage'] ?? []) : [];
            $status = $error !== null ? 'failed' : (($data['status'] ?? '') === 'incomplete' ? 'incomplete' : 'completed');
            if (is_array($data) && ($data['status'] ?? '') === 'failed') $status = 'failed';
            self::db()->prepare('UPDATE calls SET finished_at=?,status=?,http_status=?,duration_ms=?,response_json=?,error=?,request_id=?,input_tokens=?,output_tokens=?,total_tokens=? WHERE id=?')->execute([
                gmdate('c'),$status,$http,(int)round($duration*1000),json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),self::sanitize($error,'',$secret),$requestId,
                $usage['input_tokens']??null,$usage['output_tokens']??null,$usage['total_tokens']??null,$id
            ]);
        } catch (Throwable $e) { error_log('Vestia log: no se pudo registrar el final de una llamada.'); }
    }
}
