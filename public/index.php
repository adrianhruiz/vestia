<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api/')) {
    $pdo = db();
    if ($path === '/api/register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf(); $d = request_json();
        $name = trim((string)($d['name'] ?? '')); $email = strtolower(trim((string)($d['email'] ?? ''))); $password = (string)($d['password'] ?? '');
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) json_response(['error' => 'Revisa nombre, email y contraseña (mínimo 10 caracteres).'], 422);
        try { $stmt = $pdo->prepare('INSERT INTO users(name,email,password_hash,style_profile,created_at) VALUES(?,?,?,?,?)'); $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),'{}',date(DATE_ATOM)]); }
        catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) json_response(['error' => 'Ese email ya está registrado.'], 409);
            error_log('register failed: ' . $e->getMessage());
            json_response(['error' => 'No se pudo completar el registro. Inténtalo de nuevo.'], 500);
        }
        session_regenerate_id(true); $_SESSION['user_id'] = (int)$pdo->lastInsertId(); json_response(['ok' => true]);
    }
    if ($path === '/api/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf(); $d = request_json(); $stmt = $pdo->prepare('SELECT * FROM users WHERE email=?'); $stmt->execute([strtolower(trim((string)($d['email'] ?? '')))]); $user = $stmt->fetch();
        if (!$user || !password_verify((string)($d['password'] ?? ''), $user['password_hash'])) { usleep(250000); json_response(['error' => 'Email o contraseña incorrectos.'], 401); }
        session_regenerate_id(true); $_SESSION['user_id'] = (int)$user['id']; json_response(['ok' => true]);
    }
    if ($path === '/api/logout' && $_SERVER['REQUEST_METHOD'] === 'POST') { require_csrf(); $_SESSION = []; session_destroy(); json_response(['ok' => true]); }
    $uid = require_user();
    if ($path === '/api/garments' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $q = '%' . trim((string)($_GET['q'] ?? '')) . '%'; $cat = trim((string)($_GET['category'] ?? ''));
        $sql = 'SELECT * FROM garments WHERE user_id=? AND (name LIKE ? OR reference LIKE ? OR brand LIKE ?)'; $args = [$uid,$q,$q,$q];
        if ($cat !== '') { $sql .= ' AND category=?'; $args[] = $cat; } $sql .= ' ORDER BY favorite DESC, created_at DESC';
        $stmt = $pdo->prepare($sql); $stmt->execute($args); json_response(['items' => $stmt->fetchAll()]);
    }
    if ($path === '/api/garments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $brands = ['Zara','Massimo Dutti','Bershka','Pull&Bear','Stradivarius','Oysho','Lefties'];
        $brand = trim((string)($_POST['brand'] ?? '')); $name = trim((string)($_POST['name'] ?? '')); $category = trim((string)($_POST['category'] ?? ''));
        if ($name === '' || $category === '' || !in_array($brand, $brands, true)) json_response(['error' => 'Nombre, marca Inditex y categoría son obligatorios.'], 422);
        $imagePath = null;
        if (!empty($_FILES['image']['tmp_name'])) {
            if ($_FILES['image']['error'] !== UPLOAD_ERR_OK || $_FILES['image']['size'] > 8*1024*1024) json_response(['error' => 'La imagen no es válida o supera 8 MB.'], 422);
            $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file($_FILES['image']['tmp_name']); $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
            if (!$ext) json_response(['error' => 'Formato permitido: JPG, PNG o WebP.'], 422);
            $filename = $uid . '-' . bin2hex(random_bytes(12)) . '.' . $ext; $dest = __DIR__ . '/uploads/' . $filename;
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) json_response(['error' => 'No se pudo guardar la foto.'], 500);
            $imagePath = '/uploads/' . $filename;
        }
        $stmt = $pdo->prepare('INSERT INTO garments(user_id,name,brand,reference,category,color,size,season,notes,image_path,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$uid,$name,$brand,trim((string)($_POST['reference']??'')),$category,trim((string)($_POST['color']??'')),trim((string)($_POST['size']??'')),trim((string)($_POST['season']??'')),trim((string)($_POST['notes']??'')),$imagePath,date(DATE_ATOM)]);
        json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()], 201);
    }
    if (preg_match('#^/api/garments/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        require_csrf(); $stmt = $pdo->prepare('SELECT image_path FROM garments WHERE id=? AND user_id=?'); $stmt->execute([(int)$m[1],$uid]); $item = $stmt->fetch();
        if (!$item) json_response(['error'=>'Prenda no encontrada.'],404); $pdo->prepare('DELETE FROM garments WHERE id=? AND user_id=?')->execute([(int)$m[1],$uid]);
        if ($item['image_path']) { $file = __DIR__ . $item['image_path']; if (is_file($file)) unlink($file); } json_response(['ok'=>true]);
    }
    if ($path === '/api/profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf(); $d = request_json(); $allowed = ['style','colors','avoid','sizes','budget','occasions']; $profile=[]; foreach($allowed as $key) $profile[$key]=mb_substr(trim((string)($d[$key]??'')),0,500);
        $pdo->prepare('UPDATE users SET style_profile=? WHERE id=?')->execute([json_encode($profile,JSON_UNESCAPED_UNICODE),$uid]); json_response(['ok'=>true]);
    }
    if ($path === '/api/analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf(); $count = (int)$pdo->query("SELECT COUNT(*) FROM garments WHERE user_id={$uid}")->fetchColumn();
        if ($count < 2) json_response(['error'=>'Añade al menos dos prendas.'],422);
        $active = $pdo->prepare("SELECT id FROM jobs WHERE user_id=? AND status IN ('queued','processing')"); $active->execute([$uid]); if ($job=$active->fetch()) json_response(['job_id'=>(int)$job['id'],'existing'=>true]);
        $stmt=$pdo->prepare('SELECT style_profile FROM users WHERE id=?'); $stmt->execute([$uid]); $profile=json_decode($stmt->fetchColumn()?:'{}',true);
        $input=array_merge($profile?:[],request_json()); $stmt=$pdo->prepare("INSERT INTO jobs(user_id,type,status,input_json,created_at) VALUES(?,'wardrobe_analysis','queued',?,?)"); $stmt->execute([$uid,json_encode($input,JSON_UNESCAPED_UNICODE),date(DATE_ATOM)]);
        json_response(['job_id'=>(int)$pdo->lastInsertId()],202);
    }
    if (preg_match('#^/api/jobs/(\d+)$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='GET') {
        $stmt=$pdo->prepare('SELECT id,status,progress,result_json,error,created_at,finished_at FROM jobs WHERE id=? AND user_id=?'); $stmt->execute([(int)$m[1],$uid]); $job=$stmt->fetch(); if(!$job) json_response(['error'=>'Análisis no encontrado.'],404);
        $job['result']=$job['result_json']?json_decode($job['result_json'],true):null; unset($job['result_json']); json_response(['job'=>$job]);
    }
    if ($path === '/api/jobs' && $_SERVER['REQUEST_METHOD'] === 'GET') { $stmt=$pdo->prepare('SELECT id,status,progress,result_json,error,created_at,finished_at FROM jobs WHERE user_id=? ORDER BY id DESC LIMIT 10'); $stmt->execute([$uid]); $jobs=$stmt->fetchAll(); foreach($jobs as &$j){$j['result']=$j['result_json']?json_decode($j['result_json'],true):null;unset($j['result_json']);} json_response(['jobs'=>$jobs]); }
    json_response(['error'=>'Ruta no encontrada.'],404);
}

$currentUser = null; $profile=[];
if ($uid=user_id()) { $stmt=db()->prepare('SELECT id,name,email,style_profile FROM users WHERE id=?');$stmt->execute([$uid]);$currentUser=$stmt->fetch();$profile=json_decode($currentUser['style_profile']??'{}',true)?:[]; }
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#183f36"><title>Vestia — Tu armario, con intención</title><link rel="stylesheet" href="<?= asset('/assets/app.css') ?>"><link rel="stylesheet" href="<?= asset('/assets/demo.css') ?>"></head>
<body data-auth="<?= $currentUser?'1':'0' ?>">
<header class="topbar"><a class="brand" href="/"><span>V</span> Vestia</a><nav><?= $currentUser?'<button class="nav-link" data-view="wardrobe">Armario</button><button class="nav-link" data-view="outfits">Outfits</button><button class="nav-link" data-view="profile">Mi estilo</button>':'<a href="#how">Cómo funciona</a>' ?></nav><div><?php if($currentUser): ?><button class="avatar" id="logout" title="Cerrar sesión"><?= e(mb_strtoupper(mb_substr($currentUser['name'],0,1))) ?></button><?php else: ?><button class="btn btn-ghost" data-open-auth="login">Entrar</button><button class="btn btn-dark" data-open-auth="register">Crear armario</button><?php endif ?></div></header>
<?php if(!$currentUser): ?>
<main><section class="hero"><div class="hero-copy"><div class="eyebrow">Tu estilo. Mejor pensado.</div><h1>Tu armario tiene<br><em>mucho que decir.</em></h1><p>Digitaliza tus prendas, descubre nuevas combinaciones y recibe recomendaciones inteligentes basadas en tu estilo real.</p><div class="hero-actions"><button class="btn btn-coral" data-open-auth="register">Crear mi armario <b>→</b></button><span>Gratis para empezar<br><small>Sin tarjeta</small></span></div><div class="trust"><strong>Inditex first</strong><span>ZARA</span><span>Massimo Dutti</span><span>Oysho</span><span>+4 marcas</span></div></div><div class="hero-image"><img src="<?= asset('/assets/wardrobe-hero.png') ?>" alt="Armario cápsula organizado"><div class="floating-card"><i>✦</i><div><b>Look de hoy</b><span>3 prendas tuyas · 18°C</span></div></div></div></section>
<section class="how" id="how"><div><span class="step">01</span><h3>Fotografía o escanea</h3><p>Añade la foto y la referencia de la etiqueta de cada prenda.</p></div><div><span class="step">02</span><h3>Define tu estilo</h3><p>Dinos qué te gusta, qué evitas y para qué ocasiones vistes.</p></div><div><span class="step">03</span><h3>Descubre tus looks</h3><p>La IA analiza en segundo plano y crea outfits con enlaces verificados.</p></div></section></main>
<?php else: ?>
<main class="app-shell"><aside><div class="welcome"><span>BUENOS DÍAS</span><h2><?= e(explode(' ',$currentUser['name'])[0]) ?>.</h2><p>¿Qué quieres ponerte hoy?</p></div><button class="side-link active" data-view="wardrobe">▦ <span>Mi armario</span></button><button class="side-link" data-view="outfits">✦ <span>Outfits IA</span></button><button class="side-link" data-view="profile">◌ <span>Mi estilo</span></button><div class="ai-card"><b>✦ Estilista IA</b><p>Analiza todo tu armario sin detener la web.</p><button class="btn btn-coral full" id="analyze">Analizar armario</button><div id="job-mini" hidden><div class="progress"><i></i></div><small>Preparando recomendaciones…</small></div></div></aside>
<section class="workspace view active" id="view-wardrobe"><div class="page-head"><div><span class="eyebrow">COLECCIÓN PERSONAL</span><h1>Mi armario</h1><p id="garment-count">Cargando prendas…</p></div><button class="btn btn-dark" id="add-garment">＋ Añadir prenda</button></div><div class="toolbar"><label class="search">⌕ <input id="search" placeholder="Buscar por prenda o referencia…"></label><select id="category-filter"><option value="">Todas las categorías</option><option>Partes de arriba</option><option>Partes de abajo</option><option>Vestidos</option><option>Abrigos</option><option>Calzado</option><option>Accesorios</option></select><button class="btn btn-soft" id="scan-label">▣ Escanear etiqueta</button></div><div id="garment-grid" class="garment-grid"></div></section>
<section class="workspace view" id="view-outfits"><div class="page-head"><div><span class="eyebrow">RECOMENDACIONES PERSONALES</span><h1>Tus outfits</h1><p>Combinaciones con tus prendas y compras que completan el look.</p></div><button class="btn btn-coral" id="analyze-top">✦ Nuevo análisis</button></div><div id="jobs" class="jobs"><div class="empty"><b>Aún no hay looks</b><p>Analiza tu armario para crear tus primeras combinaciones.</p></div></div></section>
<section class="workspace view" id="view-profile"><div class="page-head"><div><span class="eyebrow">PERFIL DE ESTILO</span><h1>Cuéntale a Vestia quién eres</h1><p>Cuanto más contexto, mejores serán tus recomendaciones.</p></div></div><form id="profile-form" class="profile-form"><label>Tu estilo<textarea name="style" placeholder="Minimalista, casual sofisticado, urbano…"><?= e($profile['style']??'') ?></textarea></label><label>Colores que te gustan<input name="colors" value="<?= e($profile['colors']??'') ?>" placeholder="Verde, negro, tonos tierra…"></label><label>Evitar<input name="avoid" value="<?= e($profile['avoid']??'') ?>" placeholder="Estampados, prendas ajustadas…"></label><label>Tallas habituales<input name="sizes" value="<?= e($profile['sizes']??'') ?>" placeholder="M, 40, calzado 43…"></label><label>Presupuesto para completar un outfit<input name="budget" value="<?= e($profile['budget']??'') ?>" placeholder="Hasta 120 €"></label><label>Ocasiones frecuentes<input name="occasions" value="<?= e($profile['occasions']??'') ?>" placeholder="Trabajo, fin de semana, cenas…"></label><button class="btn btn-dark">Guardar preferencias</button></form></section></main>
<?php endif ?>
<div class="modal" id="auth-modal" hidden><div class="modal-card auth-card"><button class="close" data-close>×</button><span class="eyebrow">BIENVENIDO A VESTIA</span><h2 id="auth-title">Entrar</h2><form id="auth-form"><label class="register-only">Nombre<input name="name" autocomplete="name"></label><label>Email<input type="email" name="email" required autocomplete="email"></label><label>Contraseña<input type="password" name="password" required minlength="10" autocomplete="current-password"></label><button class="btn btn-coral full">Continuar</button><p class="form-error"></p></form><button class="text-btn" id="auth-switch">¿No tienes cuenta? Crear armario</button></div></div>
<div class="modal" id="garment-modal" hidden><div class="modal-card wide"><button class="close" data-close>×</button><span class="eyebrow">NUEVA PRENDA</span><h2>Añade algo a tu armario</h2><form id="garment-form" enctype="multipart/form-data"><label class="dropzone"><input type="file" name="image" accept="image/jpeg,image/png,image/webp" capture="environment"><img class="upload-preview" alt="Vista previa de la prenda" hidden><b>＋ Añadir foto</b><span>JPG, PNG o WebP · máx. 8 MB</span></label><div class="form-grid"><label>Nombre<input name="name" required placeholder="Camisa fluida"></label><label>Marca<select name="brand" required><option>Zara</option><option>Massimo Dutti</option><option>Bershka</option><option>Pull&Bear</option><option>Stradivarius</option><option>Oysho</option><option>Lefties</option></select></label><label>Referencia de etiqueta<input name="reference" placeholder="Ej. 7545/043/800"></label><label>Categoría<select name="category" required><option>Partes de arriba</option><option>Partes de abajo</option><option>Vestidos</option><option>Abrigos</option><option>Calzado</option><option>Accesorios</option></select></label><label>Color<input name="color" placeholder="Crudo"></label><label>Talla<input name="size" placeholder="M"></label><label>Temporada<select name="season"><option>Todo el año</option><option>Primavera / verano</option><option>Otoño / invierno</option></select></label><label>Notas<input name="notes" placeholder="Corte oversize, lino…"></label></div><button class="btn btn-dark full">Guardar prenda</button><p class="form-error"></p></form></div></div>
<div id="toast" role="status"></div><script>window.APP={csrf:<?= json_encode(csrf_token()) ?>};</script><script src="<?= asset('/assets/app.js') ?>"></script></body></html>
