<?php
declare(strict_types=1);
require __DIR__.'/Capture.php';
const SLOT_ROOT = __DIR__ . '/../../..';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
ini_set('session.use_strict_mode','1');
session_name('__Host-vestia_log');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
function e(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function slot_db(string $slot): PDO {
    if (!in_array($slot,['raul','adrian'],true)) throw new RuntimeException('Entorno no permitido.');
    $env=[];
    foreach(file(SLOT_ROOT.'/'.$slot.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line) {
        $line=trim($line); if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
        [$k,$v]=array_map('trim',explode('=',$line,2));
        if(str_starts_with($k,'DB_'))$env[$k]=trim($v,"\"'");
    }
    $dsn= !empty($env['DB_SOCKET']) ? 'mysql:unix_socket='.$env['DB_SOCKET'] : 'mysql:host='.($env['DB_HOST']??'127.0.0.1').';port='.($env['DB_PORT']??'3306');
    $db=new PDO($dsn.';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec('SET SESSION TRANSACTION READ ONLY');
    return $db;
}
function csrf(): void {
    if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) { http_response_code(403); exit('La sesión ha caducado. Recarga la página.'); }
}
function identity(): ?array {
    $a=$_SESSION['auth']??null;
    if(!$a)return null;
    if(time()-($a['at']??0)>28800){unset($_SESSION['auth']);return null;}
    $db=slot_db($a['slot']);
    $s=$db->prepare('SELECT id,name,password_hash FROM users WHERE id=?');$s->execute([$a['id']]);$u=$s->fetch();
    if(!$u||!hash_equals($a['stamp'],hash('sha256',$u['password_hash']))){unset($_SESSION['auth']);return null;}
    return ['slot'=>$a['slot'],'id'=>(int)$u['id'],'name'=>$u['name']];
}
function json_out(mixed $data,int $code=200): never {http_response_code($code);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
