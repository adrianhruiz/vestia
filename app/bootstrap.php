<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

function load_env(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (getenv($key) === false || getenv($key) === '') putenv("{$key}={$value}");
    }
}

load_env(APP_ROOT . '/.env');
date_default_timezone_set('Europe/Madrid');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('vestia_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) session_start();

function env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $socket = env('DB_SOCKET', '');
    $dsn = $socket !== ''
        ? 'mysql:unix_socket=' . $socket . ';dbname=' . env('DB_DATABASE', 'vestia_pre') . ';charset=utf8mb4'
        : 'mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306') . ';dbname=' . env('DB_DATABASE', 'vestia_pre') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, env('DB_USERNAME', 'vestia_pre'), env('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, email VARCHAR(255) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, style_profile LONGTEXT NOT NULL, created_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS garments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, name VARCHAR(255) NOT NULL,
 brand VARCHAR(80) NOT NULL, reference VARCHAR(100), category VARCHAR(100) NOT NULL, color VARCHAR(100), size VARCHAR(50),
 season VARCHAR(100), notes TEXT, image_path VARCHAR(500), favorite TINYINT(1) NOT NULL DEFAULT 0,
 wear_count INT UNSIGNED NOT NULL DEFAULT 0, created_at VARCHAR(40) NOT NULL, source_url VARCHAR(1000), price_note VARCHAR(100),
 INDEX garments_user_idx(user_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, type VARCHAR(80) NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'queued', progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
 input_json LONGTEXT NOT NULL, result_json LONGTEXT, error TEXT,
 created_at VARCHAR(40) NOT NULL, started_at VARCHAR(40), finished_at VARCHAR(40),
 INDEX jobs_status_idx(status, created_at), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
}

function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function json_response(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
function user_id(): ?int { return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null; }
function require_user(): int { $id = user_id(); if (!$id) json_response(['error' => 'Debes iniciar sesión.'], 401); return $id; }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function require_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$token)) json_response(['error' => 'Sesión caducada. Recarga la página.'], 419);
}
function request_json(): array {
    $data = json_decode(file_get_contents('php://input') ?: '{}', true);
    return is_array($data) ? $data : [];
}
