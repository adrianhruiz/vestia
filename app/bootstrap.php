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
// VESTIA_NO_SESSION is for the public endpoints a stranger or a crawler hits: they have no
// account to read and no business being handed a cookie. share-image.php defines it.
if (PHP_SAPI !== 'cli' && !defined('VESTIA_NO_SESSION') && session_status() !== PHP_SESSION_ACTIVE) session_start();

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

/**
 * Applies every pending file in database/migrations, oldest first, and records it in
 * schema_migrations so each environment ends up with the exact same schema no matter when
 * its database was created. Runs on every boot: the steady state is a single SELECT.
 */
function migrate(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
 version VARCHAR(255) NOT NULL PRIMARY KEY, applied_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
    sort($files);
    $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $pending = array_filter($files, static fn(string $file): bool => !in_array(basename($file), $applied, true));
    if (!$pending) return;

    // Only one process applies them: web requests and the worker can boot at the same time.
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute(['vestia_migrations']);
    if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Could not acquire the migration lock.');
    try {
        $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $record = $pdo->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(?,?)');
        foreach ($pending as $file) {
            $version = basename($file);
            if (in_array($version, $applied, true)) continue;
            // One statement at a time: PDO::exec() only reports the error of the first one.
            foreach (sql_statements((string) file_get_contents($file)) as $statement) $pdo->exec($statement);
            $record->execute([$version, date(DATE_ATOM)]);
        }
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['vestia_migrations']);
    }
}

/** Splits a migration file into statements, dropping comments and blank lines. */
function sql_statements(string $sql): array {
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    $parts = array_map(static fn(string $part): string => trim($part, "; \t\n\r"), preg_split('/;\s*[\r\n]+/', $sql) ?: []);
    return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
}

/**
 * The only keys the stylist ever receives about a person. Both the profile form and the
 * analysis request filter through this: whatever reaches the model has to be something we
 * asked for, at a length we chose.
 */
const STYLE_PROFILE_KEYS = ['style', 'colors', 'avoid', 'sizes', 'budget', 'occasions'];
const STYLE_PROFILE_MAX_LENGTH = 500;

/** Keeps only the known keys, as trimmed strings, capped. Anything else is dropped. */
function style_profile(array ...$sources): array {
    $profile = [];
    foreach (STYLE_PROFILE_KEYS as $key) {
        foreach ($sources as $source) {
            if (!isset($source[$key]) || !is_scalar($source[$key])) continue;
            $value = trim((string) $source[$key]);
            if ($value === '') continue;
            $profile[$key] = mb_substr($value, 0, STYLE_PROFILE_MAX_LENGTH);
            break;
        }
    }
    return $profile;
}

/**
 * The profile after a form that is only about part of it.
 *
 * A key the request mentions is taken from the request, empty included, so a field can still
 * be cleared by emptying its box. A key it does not mention at all keeps what it had: the
 * welcome screen asks about four of the six and must not wipe the two it never showed.
 */
function style_profile_update(array $body, array $stored): array {
    return style_profile($body) + style_profile(array_diff_key($stored, $body));
}

function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }

/**
 * Versioned URL for a file under public/. The token changes whenever the file does, which
 * is what makes the one-year immutable cache in public/.htaccess safe: a deploy publishes
 * new URLs instead of waiting for a stale copy to expire.
 */
function asset(string $path): string {
    static $versions = [];
    if (!array_key_exists($path, $versions)) {
        $file = APP_ROOT . '/public' . $path;
        $mtime = is_file($file) ? filemtime($file) : false;
        $versions[$path] = $mtime === false ? null : substr(dechex($mtime), -8);
    }
    return $versions[$path] === null ? $path : $path . '?v=' . $versions[$path];
}

/**
 * srcset for a family of pre-rendered widths, e.g.
 * srcset('/assets/hero/wardrobe-hero-%d.avif', [480, 768]).
 */
function srcset(string $template, array $widths): string {
    $candidates = [];
    foreach ($widths as $width) $candidates[] = asset(sprintf($template, $width)) . ' ' . $width . 'w';
    return implode(', ', $candidates);
}

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
