<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Catalog.php';
require dirname(__DIR__) . '/app/Email.php';
require dirname(__DIR__) . '/app/EmailValidation.php';
require dirname(__DIR__) . '/app/Images.php';
require dirname(__DIR__) . '/app/Wardrobe.php';
require dirname(__DIR__) . '/app/SignupLimit.php';
require dirname(__DIR__) . '/app/StyleReading.php';
require dirname(__DIR__) . '/app/Onboarding.php';
require dirname(__DIR__) . '/app/Analysis.php';
require dirname(__DIR__) . '/app/Share.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api/')) {
    $pdo = db();
    if ($path === '/api/register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf(); $d = request_json();
        $name = trim((string)($d['name'] ?? '')); $email = strtolower(trim((string)($d['email'] ?? ''))); $password = (string)($d['password'] ?? '');
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) json_response(['error' => 'Revisa nombre, email y contraseña (mínimo 10 caracteres).'], 422);
        // Checked after the fields are known good, so a malformed request cannot spend
        // somebody else's allowance, and before the INSERT, so a refusal costs no row.
        $ipHash = signup_ip_hash(signup_client_ip());
        $emailDomain = email_domain_of($email) ?? '';
        $wait = signup_retry_after($pdo, $ipHash, $emailDomain);
        if ($wait !== null) json_response(['error' => 'Se han creado demasiadas cuentas desde aquí. Vuelve a intentarlo a las ' . date('H:i', time() + $wait) . '.', 'retry_after' => $wait], 429);
        // After the limit above, which is one indexed read: an origin that is already
        // blocked never gets as far as the DNS lookup in here, the only part of registering
        // that waits on the network.
        //
        // Said apart from the field check further up on purpose: "revisa el email" is no
        // help to someone whose address is written perfectly and whose provider we will not
        // take.
        if (($rejected = email_rejection($email)) !== null) json_response(['error' => [
            'syntax' => 'Esa dirección de correo no es válida.',
            'domain' => 'El dominio de ese correo no recibe mensajes. Revisa si hay una errata.',
            'disposable' => 'No admitimos correos temporales. Usa una dirección permanente.',
        ][$rejected]], 422);
        // The shared look this person came in through, if any. It is read from the session,
        // which only render_share_page() writes, never from the request body.
        $ref = isset($_SESSION['ref_share']) && preg_match('/^[a-f0-9]{32}$/', (string)$_SESSION['ref_share']) ? (string)$_SESSION['ref_share'] : null;
        // Asked before the INSERT so the answer can say which of the two it is: the same
        // address again, or a new spelling of an inbox that already has an account. The
        // UNIQUE on email_canonical is still what decides it -- this read can lose a race
        // with a second registration and the index cannot.
        $canonical = email_canonical($email);
        $stmt = $pdo->prepare('SELECT email FROM users WHERE email_canonical=? OR email=? LIMIT 1'); $stmt->execute([$canonical, $email]);
        if (($taken = $stmt->fetchColumn()) !== false) json_response(['error' => $taken === $email
            ? 'Ese email ya está registrado.'
            : 'Ya hay una cuenta con ese buzón. Las direcciones con +etiqueta o con puntos llegan al mismo sitio: entra con la que usaste al registrarte.'], 409);
        // Read here, on the line after the INSERT, and never further down: lastInsertId() is
        // a property of the connection, not of this statement, so every write that follows
        // overwrites it. record_signup() below does two -- its own INSERT, then the prune --
        // and a DELETE leaves it at 0, which is how registering ended up handing out a
        // session belonging to nobody: logged in as user 0, straight back out of /bienvenida.
        try { $stmt = $pdo->prepare('INSERT INTO users(name,email,email_canonical,password_hash,style_profile,signup_share_token,created_at) VALUES(?,?,?,?,?,?,?)'); $stmt->execute([$name,$email,$canonical,password_hash($password,PASSWORD_DEFAULT),'{}',$ref,date(DATE_ATOM)]); $userId = (int)$pdo->lastInsertId(); }
        catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) json_response(['error' => 'Ese email ya está registrado.'], 409);
            error_log('register failed: ' . $e->getMessage());
            json_response(['error' => 'No se pudo completar el registro. Inténtalo de nuevo.'], 500);
        }
        // Only now: what the limit counts is accounts that exist, not forms that bounced.
        record_signup($pdo, $ipHash, $emailDomain);
        session_regenerate_id(true); $_SESSION['user_id'] = $userId;
        // Registering ends on the welcome, not on an empty wardrobe. Decided here rather
        // than by the page, because only this side knows whether they came in through a
        // shared look and still owe themselves that first garment.
        json_response(['ok' => true, 'next' => ONBOARDING_PATH . ($ref !== null ? '?after=nueva-prenda' : '')]);
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
        $imagePath = $thumbPath = null;
        if (!empty($_FILES['image']['tmp_name'])) {
            if ($_FILES['image']['error'] !== UPLOAD_ERR_OK || $_FILES['image']['size'] > 8*1024*1024) json_response(['error' => 'La imagen no es válida o supera 8 MB.'], 422);
            $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file($_FILES['image']['tmp_name']);
            if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) json_response(['error' => 'Formato permitido: JPG, PNG o WebP.'], 422);
            if (!is_uploaded_file($_FILES['image']['tmp_name'])) json_response(['error' => 'La imagen no es válida.'], 422);
            try { [$image, $thumb] = store_garment_image($_FILES['image']['tmp_name'], (string)$mime, __DIR__ . '/uploads', $uid . '-' . bin2hex(random_bytes(12))); }
            catch (Throwable $e) { error_log('garment image failed: ' . $e->getMessage()); json_response(['error' => 'No se pudo procesar la foto.'], 500); }
            $imagePath = '/uploads/' . $image; $thumbPath = '/uploads/' . $thumb;
        }
        $stmt = $pdo->prepare('INSERT INTO garments(user_id,name,brand,reference,category,color,size,season,notes,image_path,thumb_path,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$uid,$name,$brand,trim((string)($_POST['reference']??'')),$category,trim((string)($_POST['color']??'')),trim((string)($_POST['size']??'')),trim((string)($_POST['season']??'')),trim((string)($_POST['notes']??'')),$imagePath,$thumbPath,date(DATE_ATOM)]);
        json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()], 201);
    }
    if (preg_match('#^/api/garments/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        require_csrf(); $stmt = $pdo->prepare('SELECT image_path,thumb_path FROM garments WHERE id=? AND user_id=?'); $stmt->execute([(int)$m[1],$uid]); $item = $stmt->fetch();
        if (!$item) json_response(['error'=>'Prenda no encontrada.'],404); $pdo->prepare('DELETE FROM garments WHERE id=? AND user_id=?')->execute([(int)$m[1],$uid]);
        foreach ([$item['image_path'], $item['thumb_path']] as $stored) {
            // Only files this app wrote into uploads/: image_path also points at seed assets.
            if (!$stored || !str_starts_with((string)$stored, '/uploads/')) continue;
            $file = __DIR__ . $stored; if (is_file($file)) unlink($file);
        }
        json_response(['ok'=>true]);
    }
    if ($path === '/api/profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $stmt=$pdo->prepare('SELECT style_profile FROM users WHERE id=?'); $stmt->execute([$uid]);
        $stored=json_decode((string)$stmt->fetchColumn(),true)?:[];
        $pdo->prepare('UPDATE users SET style_profile=? WHERE id=?')->execute([json_encode(style_profile_update(request_json(),$stored),JSON_UNESCAPED_UNICODE),$uid]);
        // Saving a profile is what the welcome was for, wherever the form was shown.
        onboarding_done($pdo,$uid); json_response(['ok'=>true]);
    }
    if ($path === '/api/onboarding/skip' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf(); onboarding_done($pdo,$uid); json_response(['ok'=>true]);
    }
    if ($path === '/api/style-reading' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        // Sent as photos[], so $_FILES arrives column by column rather than one entry per
        // file. An empty slot is a slot nobody filled, not an error worth a message.
        $uploads = [];
        foreach ((array)($_FILES['photos']['tmp_name'] ?? []) as $i => $tmp) {
            if ((int)($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $uploads[] = ['tmp' => (string)$tmp, 'size' => (int)($_FILES['photos']['size'][$i] ?? 0), 'error' => (int)$_FILES['photos']['error'][$i]];
        }
        if (!$uploads) json_response(['error' => 'Añade al menos una foto para que el estilista pueda leer tu estilo.'], 422);
        if (count($uploads) > STYLE_PHOTO_MAX_COUNT) json_response(['error' => 'Como mucho ' . STYLE_PHOTO_MAX_COUNT . ' fotos.'], 422);
        // Pressing twice must not buy the same reading twice.
        $active = $pdo->prepare("SELECT id FROM jobs WHERE user_id=? AND type='style_reading' AND status IN ('queued','processing')"); $active->execute([$uid]);
        if ($job = $active->fetch()) json_response(['job_id' => (int)$job['id'], 'existing' => true], 202);
        $stmt = $pdo->prepare('SELECT id,email,is_test FROM users WHERE id=?'); $stmt->execute([$uid]); $user = $stmt->fetch();
        $wait = style_reading_retry_after($pdo, $user ?: []);
        if ($wait !== null) json_response(['error' => 'Has usado tus ' . STYLE_READINGS_PER_DAY . ' lecturas de estilo de hoy. La siguiente estará disponible a las ' . date('H:i', time() + $wait) . '.', 'retry_after' => $wait], 429);
        // Every photo is checked before any of them is written: half an upload stored is a
        // directory to clean up and a reading of a style nobody described.
        foreach ($uploads as $i => $upload) {
            if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > STYLE_PHOTO_MAX_BYTES) json_response(['error' => 'Cada foto tiene que ocupar menos de 8 MB.'], 422);
            if (!is_uploaded_file($upload['tmp'])) json_response(['error' => 'Esa foto no es válida.'], 422);
            if (($uploads[$i]['mime'] = style_photo_format($upload['tmp'])) === null) json_response(['error' => 'Formato permitido: JPG, PNG o WebP.'], 422);
        }
        // Whatever the last attempt left behind goes now: an account never holds more photos
        // than the reading it is asking for.
        style_photos_delete(style_photos_of_user($uid));
        $names = [];
        try { foreach ($uploads as $upload) { $name = style_photo_name($uid); store_style_photo($upload['tmp'], (string)$upload['mime'], style_photo_dir(), $name); $names[] = $name; } }
        catch (Throwable $e) {
            style_photos_delete($names);
            error_log('style photo failed: ' . $e->getMessage());
            json_response(['error' => 'No se pudo procesar alguna de las fotos.'], 500);
        }
        style_photos_purge();
        $stmt = $pdo->prepare("INSERT INTO jobs(user_id,type,status,input_json,created_at) VALUES(?,'style_reading','queued',?,?)");
        $stmt->execute([$uid, json_encode(style_reading_input($names, (string)($_POST['note'] ?? '')), JSON_UNESCAPED_UNICODE), date(DATE_ATOM)]);
        json_response(['job_id' => (int)$pdo->lastInsertId()], 202);
    }
    if ($path === '/api/analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf(); $count = (int)$pdo->query("SELECT COUNT(*) FROM garments WHERE user_id={$uid}")->fetchColumn();
        if ($count < 2) json_response(['error'=>'Añade al menos dos prendas.'],422);
        $active = $pdo->prepare("SELECT id FROM jobs WHERE user_id=? AND type='wardrobe_analysis' AND status IN ('queued','processing')"); $active->execute([$uid]); if ($job=$active->fetch()) json_response(['job_id'=>(int)$job['id'],'existing'=>true]);
        $stmt=$pdo->prepare('SELECT id,email,is_test,style_profile FROM users WHERE id=?'); $stmt->execute([$uid]); $user=$stmt->fetch();
        $wait=analysis_retry_after($pdo,$user?:[]);
        if ($wait!==null) json_response(['error'=>'Has usado tus '.ANALYSES_PER_HOUR.' análisis de esta hora. El siguiente estará disponible a las '.date('H:i',time()+$wait).'.','retry_after'=>$wait],429);
        $profile=json_decode($user['style_profile']?:'{}',true);
        // The request body used to be array_merge'd in raw and stored, so anything a client
        // posted became prompt we paid for. It gets the same six capped keys as the form.
        $body=request_json();
        $input=['preferences'=>style_profile($body,$profile?:[]),'shopping'=>!empty($body['shopping']),'budget'=>analysis_budget($body)];
        $fingerprint=analysis_fingerprint(analysis_garments($pdo,$uid),$input);
        // Same wardrobe, same profile, same question: the answer is already upstairs.
        if (empty($body['force'])) {
            $stmt=$pdo->prepare("SELECT id,finished_at FROM jobs WHERE user_id=? AND type='wardrobe_analysis' AND status='completed' AND fingerprint=? ORDER BY id DESC LIMIT 1"); $stmt->execute([$uid,$fingerprint]);
            if ($previous=$stmt->fetch()) json_response(['reused'=>true,'job_id'=>(int)$previous['id'],'finished_at'=>$previous['finished_at']]);
        }
        $stmt=$pdo->prepare("INSERT INTO jobs(user_id,type,fingerprint,status,input_json,created_at) VALUES(?,'wardrobe_analysis',?,'queued',?,?)"); $stmt->execute([$uid,$fingerprint,json_encode($input,JSON_UNESCAPED_UNICODE),date(DATE_ATOM)]);
        json_response(['job_id'=>(int)$pdo->lastInsertId()],202);
    }
    if (preg_match('#^/api/jobs/(\d+)/outfits/(\d+)/image$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='POST') {
        require_csrf(); $analysisId=(int)$m[1]; $index=(int)$m[2];
        $stmt=$pdo->prepare("SELECT result_json FROM jobs WHERE id=? AND user_id=? AND type='wardrobe_analysis' AND status='completed'"); $stmt->execute([$analysisId,$uid]); $raw=$stmt->fetchColumn();
        if ($raw===false) json_response(['error'=>'Análisis no encontrado.'],404);
        $outfit=(json_decode((string)$raw,true)['outfits']??[])[$index]??null;
        if (!is_array($outfit)) json_response(['error'=>'Ese look no existe.'],404);
        if (!empty($outfit['image_url'])) json_response(['image_url'=>$outfit['image_url'],'ready'=>true]);
        // Pressing twice must not buy the same picture twice.
        $payload=json_encode(['analysis_job_id'=>$analysisId,'outfit_index'=>$index]);
        $stmt=$pdo->prepare("SELECT id FROM jobs WHERE user_id=? AND type='outfit_image' AND status IN ('queued','processing') AND input_json=?"); $stmt->execute([$uid,$payload]);
        if ($existing=$stmt->fetchColumn()) json_response(['job_id'=>(int)$existing,'existing'=>true],202);
        $pdo->prepare("INSERT INTO jobs(user_id,type,status,input_json,created_at) VALUES(?,'outfit_image','queued',?,?)")->execute([$uid,$payload,date(DATE_ATOM)]);
        json_response(['job_id'=>(int)$pdo->lastInsertId()],202);
    }
    if (preg_match('#^/api/jobs/(\d+)/cancel$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='POST') {
        require_csrf(); $id=(int)$m[1];
        $stmt=$pdo->prepare('SELECT status FROM jobs WHERE id=? AND user_id=?'); $stmt->execute([$id,$uid]); $status=$stmt->fetchColumn();
        if ($status===false) json_response(['error'=>'Análisis no encontrado.'],404);
        if (!in_array($status,['queued','processing'],true)) json_response(['error'=>'Ese análisis ya ha terminado.'],409);
        // A queued job has spent nothing, so it stops right here. A running one has already
        // paid for at least the text call: flag it and let the worker stop at its next step.
        if ($status==='queued') $pdo->prepare("UPDATE jobs SET status='cancelled', cancel_requested=1, phase=NULL, finished_at=? WHERE id=? AND status='queued'")->execute([date(DATE_ATOM),$id]);
        else $pdo->prepare('UPDATE jobs SET cancel_requested=1 WHERE id=?')->execute([$id]);
        json_response(['ok'=>true,'stopped'=>$status==='queued']);
    }
    if (preg_match('#^/api/jobs/(\d+)$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='GET') {
        $stmt=$pdo->prepare('SELECT id,status,progress,phase,result_json,error,created_at,finished_at FROM jobs WHERE id=? AND user_id=?'); $stmt->execute([(int)$m[1],$uid]); $job=$stmt->fetch(); if(!$job) json_response(['error'=>'Análisis no encontrado.'],404);
        $job['result']=$job['result_json']?json_decode($job['result_json'],true):null; unset($job['result_json']); json_response(['job'=>$job]);
    }
    if ($path === '/api/jobs' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // The list is metadata only. It used to carry result_json for all ten analyses when
        // the only one ever rendered is the newest completed, so that one travels apart.
        $stmt=$pdo->prepare("SELECT id,status,progress,phase,error,created_at,finished_at FROM jobs WHERE user_id=? AND type='wardrobe_analysis' ORDER BY id DESC LIMIT 10"); $stmt->execute([$uid]); $jobs=$stmt->fetchAll();
        $latest=null; foreach($jobs as $j) if($j['status']==='completed'){$latest=(int)$j['id']; break;}
        $result=null;
        if($latest){ $stmt=$pdo->prepare('SELECT result_json FROM jobs WHERE id=? AND user_id=?'); $stmt->execute([$latest,$uid]); $raw=$stmt->fetchColumn(); $result=$raw?json_decode($raw,true):null; }
        $stmt=$pdo->prepare('SELECT id,email,is_test FROM users WHERE id=?'); $stmt->execute([$uid]); $user=$stmt->fetch()?:[];
        $left=analysis_limit_exempt($user)?null:max(0,ANALYSES_PER_HOUR-count(recent_analyses($pdo,$uid)));
        // Which of these looks are already public, so every card knows whether its button
        // says "Compartir" or "Compartido".
        $shares=[];
        if($latest){ $stmt=$pdo->prepare('SELECT outfit_index,token,views FROM outfit_shares WHERE job_id=? AND user_id=? AND revoked_at IS NULL'); $stmt->execute([$latest,$uid]);
            foreach($stmt->fetchAll() as $row) $shares[(int)$row['outfit_index']]=['token'=>$row['token'],'url'=>share_url($row['token']),'views'=>(int)$row['views']]; }
        json_response(['jobs'=>$jobs,'result_job_id'=>$latest,'result'=>$result,'analyses_left'=>$left,'shares'=>$shares]);
    }
    if ($path === '/api/outfits/share' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf(); $d=request_json(); $analysisId=(int)($d['job_id']??0); $index=(int)($d['index']??-1);
        $stmt=$pdo->prepare("SELECT result_json FROM jobs WHERE id=? AND user_id=? AND type='wardrobe_analysis' AND status='completed'"); $stmt->execute([$analysisId,$uid]); $raw=$stmt->fetchColumn();
        if ($raw===false) json_response(['error'=>'Análisis no encontrado.'],404);
        $outfit=(json_decode((string)$raw,true)['outfits']??[])[$index]??null;
        if (!is_array($outfit)) json_response(['error'=>'Ese look no existe.'],404);
        $stmt=$pdo->prepare('SELECT id,token,revoked_at,views FROM outfit_shares WHERE job_id=? AND outfit_index=?'); $stmt->execute([$analysisId,$index]); $share=$stmt->fetch();
        // Already public: hand back the link that is already out there rather than minting a
        // second one for the same look.
        if ($share && $share['revoked_at']===null) json_response(['url'=>share_url($share['token']),'token'=>$share['token'],'views'=>(int)$share['views']]);
        $stmt=$pdo->prepare('SELECT name FROM users WHERE id=?'); $stmt->execute([$uid]); $ownerName=explode(' ',trim((string)$stmt->fetchColumn()))[0];
        $garments=[]; $ids=array_values(array_unique(array_map('intval',(array)($outfit['garment_ids']??[]))));
        if ($ids) {
            $stmt=$pdo->prepare('SELECT id,name,brand,color,category,image_path,thumb_path,source_url FROM garments WHERE user_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
            $stmt->execute(array_merge([$uid],$ids));
            foreach($stmt->fetchAll() as $row) $garments[(int)$row['id']]=$row;
        }
        $snapshot=share_snapshot($outfit,$garments,$ownerName); $token=share_token();
        if ($share) {
            // Re-sharing a look that was retired gets a new token: the old link was pulled on
            // purpose and must not come back to life in the pocket of whoever still has it.
            delete_share_images($share['token']);
            $pdo->prepare('UPDATE outfit_shares SET token=?,snapshot_json=?,views=0,revoked_at=NULL,created_at=? WHERE id=?')->execute([$token,json_encode($snapshot,JSON_UNESCAPED_UNICODE),date(DATE_ATOM),$share['id']]);
        } else {
            $active=$pdo->prepare('SELECT COUNT(*) FROM outfit_shares WHERE user_id=? AND revoked_at IS NULL'); $active->execute([$uid]);
            if ((int)$active->fetchColumn()>=SHARE_MAX_ACTIVE) json_response(['error'=>'Tienes '.SHARE_MAX_ACTIVE.' looks compartidos. Retira alguno para compartir otro.'],409);
            $pdo->prepare('INSERT INTO outfit_shares(user_id,job_id,outfit_index,token,snapshot_json,created_at) VALUES(?,?,?,?,?,?)')->execute([$uid,$analysisId,$index,$token,json_encode($snapshot,JSON_UNESCAPED_UNICODE),date(DATE_ATOM)]);
        }
        // A picture that cannot be drawn is not a reason to refuse the link: the page falls
        // back to the collage and the preview to the hero.
        try { store_share_images($snapshot, empty($outfit['image_url'])?null:APP_ROOT.'/storage/outfits/job-'.$analysisId.'-outfit-'.($index+1).'.png', $token); }
        catch (Throwable $e) { error_log('share image failed: '.$e->getMessage()); }
        json_response(['url'=>share_url($token),'token'=>$token,'views'=>0],201);
    }
    if ($path === '/api/outfits/unshare' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf(); $d=request_json();
        $stmt=$pdo->prepare('SELECT id,token FROM outfit_shares WHERE token=? AND user_id=? AND revoked_at IS NULL'); $stmt->execute([(string)($d['token']??''),$uid]); $share=$stmt->fetch();
        if (!$share) json_response(['error'=>'Ese enlace no existe o ya lo habías retirado.'],404);
        $pdo->prepare('UPDATE outfit_shares SET revoked_at=? WHERE id=?')->execute([date(DATE_ATOM),$share['id']]);
        delete_share_images($share['token']);
        json_response(['ok'=>true]);
    }
    json_response(['error'=>'Ruta no encontrada.'],404);
}

// A shared look. Routed here rather than through a RewriteRule because the built-in server
// the README starts in local has no .htaccess and falls back to this file on its own.
if (preg_match('#^/o/([a-f0-9]{32})$#', $path, $m)) render_share_page($m[1]);

// The welcome. Always reachable with a session, finished or not: a style changes, and
// redoing the reading has to be possible from "Mi estilo".
if ($path === ONBOARDING_PATH) {
    $stmt=db()->prepare('SELECT id,name FROM users WHERE id=?'); $stmt->execute([user_id() ?: 0]);
    if ($person=$stmt->fetch()) render_onboarding_page($person, (string)($_GET['after'] ?? ''));
    header('Location: /'); exit;
}

$currentUser = null; $profile=[]; $garments=null; $sizes=[];
if ($uid=user_id()) {
    $stmt=db()->prepare('SELECT id,name,email,style_profile,onboarding_done_at FROM users WHERE id=?');$stmt->execute([$uid]);$currentUser=$stmt->fetch();$profile=json_decode($currentUser['style_profile']??'{}',true)?:[];
    // The unfiltered wardrobe travels with the page: the grid used to need a round trip
    // before it could show anything but "Cargando prendas…".
    $stmt=db()->prepare('SELECT * FROM garments WHERE user_id=? ORDER BY favorite DESC, created_at DESC');$stmt->execute([$uid]);$garments=$stmt->fetchAll();
    $sizes=remembered_sizes(db(),$uid);
}
$garmentCount = $garments === null ? 0 : count($garments);
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#183f36"><meta name="description" content="Vestia digitaliza tu armario y te propone combinaciones y recomendaciones de estilo con IA a partir de las prendas que ya tienes."><title>Vestia — Tu armario, con intención</title><link rel="preload" as="font" type="font/woff2" href="/assets/fonts/dm-sans-latin.woff2" crossorigin><link rel="preload" as="font" type="font/woff2" href="/assets/fonts/playfair-display-latin.woff2" crossorigin><link rel="stylesheet" href="<?= asset('/assets/app.css') ?>"></head>
<body data-auth="<?= $currentUser?'1':'0' ?>">
<header class="topbar"><a class="brand" href="/"><span>V</span><div class="brand-label">Vestia<small class="app-version">Versión <?= e(trim((string)file_get_contents(APP_ROOT . '/VERSION'))) ?></small></div></a><nav><?= $currentUser?'<button class="nav-link" data-view="wardrobe">Armario</button><button class="nav-link" data-view="outfits">Outfits</button><button class="nav-link" data-view="profile">Mi estilo</button>':'<a href="#how">Cómo funciona</a>' ?></nav><div><?php if($currentUser): ?><button class="avatar" id="logout" title="Cerrar sesión"><?= e(mb_strtoupper(mb_substr($currentUser['name'],0,1))) ?></button><?php else: ?><button class="btn btn-ghost" data-open-auth="login">Entrar</button><button class="btn btn-dark" data-open-auth="register">Crear armario</button><?php endif ?></div></header>
<?php if(!$currentUser): ?>
<main><section class="hero"><div class="hero-copy"><div class="eyebrow">Tu estilo. Mejor pensado.</div><h1>Tu armario tiene<br><em>mucho que decir.</em></h1><p>Digitaliza tus prendas, descubre nuevas combinaciones y recibe recomendaciones inteligentes basadas en tu estilo real.</p><div class="hero-actions"><button class="btn btn-coral" data-open-auth="register">Crear mi armario <b>→</b></button><span>Gratis para empezar<br><small>Sin tarjeta</small></span></div><div class="trust"><strong>Inditex first</strong><span>ZARA</span><span>Massimo Dutti</span><span>Oysho</span><span>+4 marcas</span></div></div><div class="hero-image"><?php $heroWidths = [480, 768, 1100, 1586]; $heroSizes = '(max-width:900px) 100vw, 54vw'; ?><picture><source type="image/avif" srcset="<?= e(srcset('/assets/hero/wardrobe-hero-%d.avif', $heroWidths)) ?>" sizes="<?= $heroSizes ?>"><source type="image/webp" srcset="<?= e(srcset('/assets/hero/wardrobe-hero-%d.webp', $heroWidths)) ?>" sizes="<?= $heroSizes ?>"><img src="<?= asset('/assets/hero/wardrobe-hero-1100.jpg') ?>" srcset="<?= e(srcset('/assets/hero/wardrobe-hero-%d.jpg', $heroWidths)) ?>" sizes="<?= $heroSizes ?>" width="1586" height="992" fetchpriority="high" decoding="async" alt="Armario cápsula organizado"></picture><div class="floating-card"><i>✦</i><div><b>Look de hoy</b><span>3 prendas tuyas · 18°C</span></div></div></div></section>
<section class="how" id="how"><div><span class="step">01</span><h2>Fotografía o escanea</h2><p>Añade la foto y la referencia de la etiqueta de cada prenda.</p></div><div><span class="step">02</span><h2>Define tu estilo</h2><p>Dinos qué te gusta, qué evitas y para qué ocasiones vistes.</p></div><div><span class="step">03</span><h2>Descubre tus looks</h2><p>La IA analiza en segundo plano y crea outfits con enlaces verificados.</p></div></section></main>
<?php else: ?>
<main class="app-shell"><aside><div class="welcome"><span>BUENOS DÍAS</span><div class="greeting"><?= e(explode(' ',$currentUser['name'])[0]) ?>.</div><p>¿Qué quieres ponerte hoy?</p></div><?php if(onboarding_pending($currentUser)): ?><a class="nudge" href="<?= ONBOARDING_PATH ?>"><b>✦ Describe tu estilo</b><span>Sube una foto y el estilista lo escribe por ti.</span></a><?php endif ?><button class="side-link active" data-view="wardrobe">▦ <span>Mi armario</span></button><button class="side-link" data-view="outfits">✦ <span>Outfits IA</span></button><button class="side-link" data-view="profile">◌ <span>Mi estilo</span></button><?php if(catalog_admin()): ?><a class="side-link" href="/catalog.php" style="text-decoration:none" aria-label="Catálogo Inditex">▤ <span>Catálogo Inditex</span></a><?php endif ?><div class="ai-card"><b>✦ Estilista IA</b><p>Analiza todo tu armario sin detener la web.</p><label class="ai-option"><input type="checkbox" id="want-shopping"> Buscar prendas que faltan en tiendas</label><label class="ai-budget" id="budget-field" hidden>Presupuesto<input id="shopping-budget" maxlength="<?= ANALYSIS_BUDGET_MAX_LENGTH ?>" placeholder="Hasta 120 €"></label><button class="btn btn-coral full" id="analyze">Analizar armario</button><small id="quota" class="quota"></small><div id="job-mini" hidden><div class="progress"><i></i></div><small>Preparando recomendaciones…</small></div></div></aside>
<section class="workspace view active" id="view-wardrobe"><div class="page-head"><div><span class="eyebrow">COLECCIÓN PERSONAL</span><h1>Mi armario</h1><p id="garment-count"><?= $garmentCount ?> <?= $garmentCount===1?'prenda':'prendas' ?> en tu colección</p></div><button class="btn btn-dark" id="add-garment">＋ Añadir prenda</button></div><div class="toolbar"><label class="search">⌕ <input id="search" placeholder="Buscar por prenda o referencia…"></label><select id="category-filter"><option value="">Todas las categorías</option><option>Partes de arriba</option><option>Partes de abajo</option><option>Vestidos</option><option>Abrigos</option><option>Calzado</option><option>Accesorios</option></select><button class="btn btn-soft" id="scan-label">▣ Escanear etiqueta</button></div><div id="garment-grid" class="garment-grid"></div></section>
<section class="workspace view" id="view-outfits"><div class="page-head"><div><span class="eyebrow">RECOMENDACIONES PERSONALES</span><h1>Tus outfits</h1><p>Combinaciones con las prendas que ya tienes.</p></div><button class="btn btn-coral" id="analyze-top">✦ Nuevo análisis</button></div><div id="jobs" class="jobs"><div class="empty"><b>Aún no hay looks</b><p>Analiza tu armario para crear tus primeras combinaciones.</p></div></div></section>
<section class="workspace view" id="view-profile"><div class="page-head"><div><span class="eyebrow">PERFIL DE ESTILO</span><h1>Cuéntale a Vestia quién eres</h1><p>Cuanto más contexto, mejores serán tus recomendaciones.</p></div><a class="btn btn-soft" href="<?= ONBOARDING_PATH ?>">✦ Que lo lea el estilista</a></div><form id="profile-form" class="profile-form"><label>Tu estilo<textarea name="style" placeholder="Minimalista, casual sofisticado, urbano…"><?= e($profile['style']??'') ?></textarea></label><label>Colores que te gustan<input name="colors" value="<?= e($profile['colors']??'') ?>" placeholder="Verde, negro, tonos tierra…"></label><label>Evitar<input name="avoid" value="<?= e($profile['avoid']??'') ?>" placeholder="Estampados, prendas ajustadas…"></label><label>Ocasiones frecuentes<input name="occasions" value="<?= e($profile['occasions']??'') ?>" placeholder="Trabajo, fin de semana, cenas…"></label><button class="btn btn-dark">Guardar preferencias</button></form></section></main>
<?php endif ?>
<div class="modal" id="auth-modal" hidden><div class="modal-card auth-card"><button class="close" data-close>×</button><span class="eyebrow">BIENVENIDO A VESTIA</span><h2 id="auth-title">Entrar</h2><form id="auth-form"><label class="register-only">Nombre<input name="name" autocomplete="name"></label><label>Email<input type="email" name="email" required autocomplete="email"></label><label>Contraseña<input type="password" name="password" required minlength="10" autocomplete="current-password"></label><button class="btn btn-coral full">Continuar</button><p class="form-error"></p></form><button class="text-btn" id="auth-switch">¿No tienes cuenta? Crear armario</button></div></div>
<div class="modal" id="garment-modal" hidden><div class="modal-card wide"><button class="close" data-close>×</button><span class="eyebrow">NUEVA PRENDA</span><h2>Añade algo a tu armario</h2><form id="garment-form" enctype="multipart/form-data"><label class="dropzone"><input type="file" name="image" accept="image/jpeg,image/png,image/webp" capture="environment"><img class="upload-preview" alt="Vista previa de la prenda" hidden><b>＋ Añadir foto</b><span>JPG, PNG o WebP · máx. 8 MB</span></label><div class="form-grid"><label>Nombre<input name="name" required placeholder="Camisa fluida"></label><label>Marca<select name="brand" required><option>Zara</option><option>Massimo Dutti</option><option>Bershka</option><option>Pull&Bear</option><option>Stradivarius</option><option>Oysho</option><option>Lefties</option></select></label><label>Referencia de etiqueta<input name="reference" placeholder="Ej. 7545/043/800"></label><label>Categoría<select name="category" required><option>Partes de arriba</option><option>Partes de abajo</option><option>Vestidos</option><option>Abrigos</option><option>Calzado</option><option>Accesorios</option></select></label><label>Color<input name="color" placeholder="Crudo"></label><label>Talla<input name="size" placeholder="M"></label><label>Temporada<select name="season"><option>Todo el año</option><option>Primavera / verano</option><option>Otoño / invierno</option></select></label><label>Notas<input name="notes" placeholder="Corte oversize, lino…"></label></div><button class="btn btn-dark full">Guardar prenda</button><p class="form-error"></p></form></div></div>
<?php if($currentUser): ?><div class="modal" id="share-modal" hidden><div class="modal-card"><button class="close" data-close>×</button><span class="eyebrow">COMPARTIR LOOK</span><h2 id="share-title">Compartir</h2><p class="share-note">Cualquiera con el enlace podrá ver este look y las prendas que lo componen. El resto de tu armario y de tu análisis no se comparte.</p><label class="share-link">Enlace<input id="share-url" readonly value="Creando el enlace…"></label><div class="share-actions"><button class="btn btn-dark" id="share-copy">Copiar enlace</button><button class="btn btn-coral" id="share-native" hidden>Compartir…</button></div><p class="share-stats" id="share-stats"></p><button class="text-btn" id="share-revoke" hidden>Dejar de compartir</button><p class="form-error"></p></div></div><?php endif ?>
<div id="toast" role="status"></div><script>window.APP=<?= json_encode(['csrf'=>csrf_token(),'garments'=>$garments,'sizes'=>$sizes], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script><script src="<?= asset('/assets/app.js') ?>"></script></body></html>
