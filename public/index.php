<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Catalog.php';
require dirname(__DIR__) . '/app/Email.php';
require dirname(__DIR__) . '/app/EmailValidation.php';
require dirname(__DIR__) . '/app/Images.php';
require dirname(__DIR__) . '/app/Wardrobe.php';
require dirname(__DIR__) . '/app/Outfits.php';
require dirname(__DIR__) . '/app/ProductLink.php';
require dirname(__DIR__) . '/app/LabelScan.php';
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
        // session belonging to nobody: logged in as user 0, with somebody else's wardrobe.
        try { $stmt = $pdo->prepare('INSERT INTO users(name,email,email_canonical,password_hash,style_profile,signup_share_token,created_at) VALUES(?,?,?,?,?,?,?)'); $stmt->execute([$name,$email,$canonical,password_hash($password,PASSWORD_DEFAULT),'{}',$ref,date(DATE_ATOM)]); $userId = (int)$pdo->lastInsertId(); }
        catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) json_response(['error' => 'Ese email ya está registrado.'], 409);
            error_log('register failed: ' . $e->getMessage());
            json_response(['error' => 'No se pudo completar el registro. Inténtalo de nuevo.'], 500);
        }
        // Only now: what the limit counts is accounts that exist, not forms that bounced.
        record_signup($pdo, $ipHash, $emailDomain);
        session_regenerate_id(true); $_SESSION['user_id'] = $userId;
        // Registering ends in their own wardrobe. The welcome that used to live on a page of
        // its own is a panel in there now, so there is nowhere else to send anybody: the
        // thing they were promised and the thing we are asking them for are on one screen.
        json_response(['ok' => true, 'next' => '/']);
    }
    if ($path === '/api/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf(); $d = request_json(); $stmt = $pdo->prepare('SELECT * FROM users WHERE email=?'); $stmt->execute([strtolower(trim((string)($d['email'] ?? '')))]); $user = $stmt->fetch();
        if (!$user || !password_verify((string)($d['password'] ?? ''), $user['password_hash'])) { usleep(250000); json_response(['error' => 'Email o contraseña incorrectos.'], 401); }
        session_regenerate_id(true); $_SESSION['user_id'] = (int)$user['id']; json_response(['ok' => true]);
    }
    if ($path === '/api/logout' && $_SERVER['REQUEST_METHOD'] === 'POST') { require_csrf(); $_SESSION = []; session_destroy(); json_response(['ok' => true]); }
    $uid = require_user();
    if ($path === '/api/label-scan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $file = $_FILES['label'] ?? [];
        if (($file['error']??UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size']??0)>8*1024*1024 || !is_uploaded_file($file['tmp_name']??'')) json_response(['error'=>'Elige una foto de etiqueta de hasta 8 MB.'],422);
        $attempts = array_values(array_filter($_SESSION['label_attempts']??[], static fn($t)=>$t>time()-60));
        if (count($attempts)>=6) json_response(['error'=>'Has hecho varias lecturas seguidas. Espera un minuto o escribe la referencia.'],429);
        $_SESSION['label_attempts'] = [...$attempts,time()];
        try { $label = label_read_image($file['tmp_name']); }
        catch (InvalidArgumentException $e) { json_response(['error'=>$e->getMessage()],422); }
        catch (Throwable $e) { error_log('label scan: '.$e->getMessage()); json_response(['error'=>'No se ha podido leer la etiqueta. Prueba con una foto más cercana y bien iluminada, o escribe la referencia.'],503); }
        json_response(['label'=>$label]);
    }
    if ($path === '/api/label-lookup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf(); $d = request_json();
        $reference = label_reference(is_string($d['reference']??null) ? $d['reference'] : '');
        $brand = is_string($d['brand']??null) ? $d['brand'] : '';
        if ($reference === null) json_response(['error'=>'Escribe la referencia de la etiqueta, por ejemplo 7545/043/800. El código de barras no es la referencia.'],422);
        if ($brand !== '' && !in_array($brand,LABEL_BRANDS,true)) json_response(['error'=>'Elige una marca de la lista.'],422);
        try { $matches = label_catalog_matches(catalog_db(),$reference,$brand); }
        catch (Throwable $e) { error_log('label lookup: '.$e->getMessage()); json_response(['error'=>'El catálogo no está disponible temporalmente. Puedes añadir la prenda manualmente.'],503); }
        json_response(['items'=>label_offer_matches($matches), 'truncated'=>count($matches)>12, 'reference'=>$reference]);
    }
    if ($path === '/api/label-image' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $choice = label_choice(is_string($_GET['token']??null) ? $_GET['token'] : '');
        if (!$choice) json_response(['error'=>'Busca la referencia de nuevo para ver la foto.'],404);
        session_write_close();
        [$status,$mime,$body] = label_photo($choice);
        if ($status !== 200 || !in_array($mime,['image/jpeg','image/png','image/webp','image/avif'],true)) json_response(['error'=>'Fotografía no disponible.'],404);
        header('Content-Type: '.$mime); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff'); echo $body; exit;
    }
    if ($path === '/api/garments' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $q = '%' . trim((string)($_GET['q'] ?? '')) . '%'; $cat = trim((string)($_GET['category'] ?? ''));
        $sql = 'SELECT * FROM garments WHERE user_id=? AND (name LIKE ? OR reference LIKE ? OR brand LIKE ?)'; $args = [$uid,$q,$q,$q];
        if ($cat !== '') { $sql .= ' AND category=?'; $args[] = $cat; } $sql .= ' ORDER BY favorite DESC, created_at DESC';
        $stmt = $pdo->prepare($sql); $stmt->execute($args); json_response(['items' => $stmt->fetchAll()]);
    }
    if ($path === '/api/garments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $brand = trim((string)($_POST['brand'] ?? '')); $name = trim((string)($_POST['name'] ?? '')); $category = trim((string)($_POST['category'] ?? ''));
        // The category is checked against the list now, not merely for being non-empty:
        // outfit_missing() decides whether a look is wearable by comparing against those
        // exact strings, so anything else here would be a garment that completes nothing.
        if ($name === '' || !in_array($brand, GARMENT_BRANDS, true) || !in_array($category, GARMENT_CATEGORIES, true)) json_response(['error' => 'Nombre, marca Inditex y categoría son obligatorios.'], 422);
        $imagePath = $thumbPath = null;
        $catalogToken = is_string($_POST['catalog_token']??null) ? $_POST['catalog_token'] : '';
        $catalogChoice = $catalogToken !== '' ? label_choice($catalogToken) : null;
        if ($catalogToken !== '' && !$catalogChoice) json_response(['error'=>'La selección ha caducado. Busca la referencia de nuevo.'],422);
        if ($catalogChoice && ($brand !== $catalogChoice['brand'] || label_reference((string)($_POST['reference']??'')) !== $catalogChoice['reference'])) json_response(['error'=>'Has cambiado la marca o referencia. Busca otra vez para seleccionar la prenda correcta.'],422);
        if (!empty($_FILES['image']['tmp_name'])) {
            if ($_FILES['image']['error'] !== UPLOAD_ERR_OK || $_FILES['image']['size'] > 8*1024*1024) json_response(['error' => 'La imagen no es válida o supera 8 MB.'], 422);
            $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file($_FILES['image']['tmp_name']);
            if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) json_response(['error' => 'Formato permitido: JPG, PNG o WebP.'], 422);
            if (!is_uploaded_file($_FILES['image']['tmp_name'])) json_response(['error' => 'La imagen no es válida.'], 422);
            try { [$image, $thumb] = store_garment_image($_FILES['image']['tmp_name'], (string)$mime, __DIR__ . '/uploads', $uid . '-' . bin2hex(random_bytes(12))); }
            catch (Throwable $e) { error_log('garment image failed: ' . $e->getMessage()); json_response(['error' => 'No se pudo procesar la foto.'], 500); }
            $imagePath = '/uploads/' . $image; $thumbPath = '/uploads/' . $thumb;
        } elseif ($catalogChoice && ($_POST['use_catalog_image']??'') === '1') {
            try { [$imagePath,$thumbPath] = label_store_photo($catalogChoice,$uid); }
            catch (Throwable $e) { json_response(['error'=>'No se ha podido guardar la foto oficial. Desmarca «Usar foto oficial» o añade tu propia foto.'],502); }
        }
        // Revalidate the store URL submitted by the form before rendering it in a card.
        $scanned = product_from_url((string)($_POST['source_url'] ?? ''));
        $stmt = $pdo->prepare('INSERT INTO garments(user_id,name,brand,reference,category,color,size,season,notes,image_path,thumb_path,source_url,price_note,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$uid,$name,$brand,trim((string)($_POST['reference']??'')),$category,trim((string)($_POST['color']??'')),trim((string)($_POST['size']??'')),trim((string)($_POST['season']??'')),trim((string)($_POST['notes']??'')),$imagePath,$thumbPath,$scanned['source_url']??null,mb_substr(trim((string)($_POST['price_note']??'')),0,100),date(DATE_ATOM)]);
        $garmentId = (int)$pdo->lastInsertId();
        // The welcome's panel opens this form with a look in mind, so the garment joins it in
        // the same request: two round trips to add one thing to one outfit is two chances for
        // the second to be the one that fails.
        $state = null;
        if (($outfitId = (int)($_POST['outfit_id'] ?? 0)) > 0 && outfit_add_garment($pdo,$uid,$outfitId,$garmentId)) {
            $stmt = $pdo->prepare('SELECT id,email,is_test,onboarding_done_at FROM users WHERE id=?'); $stmt->execute([$uid]);
            $state = onboarding_advance($pdo, $stmt->fetch() ?: []);
        }
        json_response(['ok' => true, 'id' => $garmentId, 'onboarding' => $state], 201);
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
        require_csrf(); $body=request_json();
        $stmt=$pdo->prepare('SELECT style_profile FROM users WHERE id=?'); $stmt->execute([$uid]);
        $stored=json_decode((string)$stmt->fetchColumn(),true)?:[];
        $pdo->prepare('UPDATE users SET style_profile=? WHERE id=?')->execute([json_encode(style_profile_update($body,$stored),JSON_UNESCAPED_UNICODE),$uid]);
        // Saving the profile used to be what closed the welcome, from whichever screen showed
        // the form. It is not any more: the welcome is two outfits and the reading that follows
        // them, so somebody who writes their own profile on day one is still owed the reading.
        // Only the panel's own form, accepting a verdict, says finish.
        if (!empty($body['finish'])) onboarding_done($pdo,$uid);
        json_response(['ok'=>true]);
    }
    if ($path === '/api/outfits' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['onboarding'=>onboarding_state($pdo,$uid)]);
    }
    if (preg_match('#^/api/outfits/(\d+)/garments$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='POST') {
        require_csrf(); $d=request_json();
        if (!outfit_add_garment($pdo,$uid,(int)$m[1],(int)($d['garment_id']??0))) json_response(['error'=>'Ese outfit o esa prenda no existen.'],404);
        $stmt=$pdo->prepare('SELECT id,email,is_test,onboarding_done_at FROM users WHERE id=?'); $stmt->execute([$uid]);
        json_response(['onboarding'=>onboarding_advance($pdo,$stmt->fetch()?:[])]);
    }
    if (preg_match('#^/api/outfits/(\d+)/garments/(\d+)$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='DELETE') {
        require_csrf();
        if (!outfit_remove_garment($pdo,$uid,(int)$m[1],(int)$m[2])) json_response(['error'=>'Esa prenda no estaba en ese outfit.'],404);
        // No onboarding_advance() here: taking a garment out can only ever make a look less
        // complete, and there is nothing to start.
        json_response(['onboarding'=>onboarding_state($pdo,$uid)]);
    }
    if ($path === '/api/product-link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf(); $d=request_json();
        $found=garment_from_link((string)($d['url']??''));
        // Said plainly rather than as "no válido": the link is either from one of the seven
        // shops or it is not, and somebody who pasted a search page needs to know which.
        if ($found===null) json_response(['error'=>'Ese enlace no es de una tienda de Inditex. Pega el de la ficha del producto, o añade la prenda con una foto.'],422);
        json_response(['garment'=>$found]);
    }
    if ($path === '/api/style-reading' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        // It used to be a multipart upload of photographs of the person, with every rule that
        // implies. There is no body any more: what gets read is the two outfits this account
        // has already grouped, so the request is a press and nothing else.
        $stmt=$pdo->prepare('SELECT id,email,is_test,onboarding_done_at FROM users WHERE id=?'); $stmt->execute([$uid]); $user=$stmt->fetch()?:[];
        $outfits=onboarding_outfits($pdo,$uid);
        if (!outfits_ready($outfits)) json_response(['error'=>'Necesito '.ONBOARDING_OUTFITS.' outfits completos y distintos entre sí: parte de arriba, parte de abajo y calzado, o un vestido con su calzado.'],422);
        // Pressing twice must not buy the same reading twice.
        $active=$pdo->prepare("SELECT id FROM jobs WHERE user_id=? AND type='style_reading' AND status IN ('queued','processing')"); $active->execute([$uid]);
        if ($job=$active->fetch()) json_response(['job_id'=>(int)$job['id'],'existing'=>true],202);
        $wait=style_reading_retry_after($pdo,$user);
        if ($wait!==null) json_response(['error'=>'Has usado tus '.STYLE_READINGS_PER_DAY.' lecturas de estilo de hoy. La siguiente estará disponible a las '.date('H:i',time()+$wait).'.','retry_after'=>$wait],429);
        json_response(['job_id'=>style_reading_queue($pdo,$uid,$outfits)],202);
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
        // A card that cannot be drawn is not a reason to refuse the link: a look whose
        // garments have no photo at all falls back to the hero as its preview.
        try { store_share_images($snapshot, $token); }
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

$currentUser = null; $profile=[]; $garments=null; $sizes=[]; $onboarding=null;
if ($uid=user_id()) {
    $stmt=db()->prepare('SELECT id,name,email,style_profile,onboarding_done_at FROM users WHERE id=?');$stmt->execute([$uid]);$currentUser=$stmt->fetch();$profile=json_decode($currentUser['style_profile']??'{}',true)?:[];
    // The unfiltered wardrobe travels with the page: the grid used to need a round trip
    // before it could show anything but "Cargando prendas…".
    $stmt=db()->prepare('SELECT * FROM garments WHERE user_id=? ORDER BY favorite DESC, created_at DESC');$stmt->execute([$uid]);$garments=$stmt->fetchAll();
    $sizes=remembered_sizes(db(),$uid);
    // So does the welcome, for the same reason and one more: a panel that appears a moment
    // after the grid, asking for two outfits, reads as an interruption rather than as the
    // first thing to do. Only while it is still owed -- it is two queries nobody else needs.
    if (onboarding_pending($currentUser)) $onboarding=onboarding_state(db(),$uid);
}
$garmentCount = $garments === null ? 0 : count($garments);
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#183f36"><meta name="description" content="Vestia digitaliza tu armario y te propone combinaciones y recomendaciones de estilo con IA a partir de las prendas que ya tienes."><title>Vestia — Tu armario, con intención</title><link rel="preload" as="font" type="font/woff2" href="/assets/fonts/dm-sans-latin.woff2" crossorigin><link rel="preload" as="font" type="font/woff2" href="/assets/fonts/playfair-display-latin.woff2" crossorigin><link rel="stylesheet" href="<?= asset('/assets/app.css') ?>"></head>
<body data-auth="<?= $currentUser?'1':'0' ?>">
<header class="topbar"><a class="brand" href="/"><span>V</span><div class="brand-label">Vestia<small class="app-version">Versión <?= e(trim((string)file_get_contents(APP_ROOT . '/VERSION'))) ?></small></div></a><nav><?= $currentUser?'<button class="nav-link" data-view="wardrobe">Armario</button><button class="nav-link" data-view="outfits">Outfits</button><button class="nav-link" data-view="profile">Mi estilo</button>':'<a href="#how">Cómo funciona</a>' ?></nav><div><?php if($currentUser): ?><div class="account"><button class="avatar" id="account-toggle" title="Tu cuenta" aria-haspopup="true" aria-expanded="false" aria-controls="account-menu"><?= e(mb_strtoupper(mb_substr($currentUser['name'],0,1))) ?></button><div class="account-menu" id="account-menu" role="menu" hidden><div class="account-id"><b><?= e($currentUser['name']) ?></b><span><?= e($currentUser['email']) ?></span></div><button class="account-item" role="menuitem" data-view="profile">Mi estilo</button><button class="account-item danger" role="menuitem" id="logout">Cerrar sesión</button></div></div><?php else: ?><button class="btn btn-ghost" data-open-auth="login">Entrar</button><button class="btn btn-dark" data-open-auth="register">Crear armario</button><?php endif ?></div></header>
<?php if(!$currentUser): ?>
<main><section class="hero"><div class="hero-copy"><div class="eyebrow">Tu estilo. Mejor pensado.</div><h1>Tu armario tiene<br><em>mucho que decir.</em></h1><p>Digitaliza tus prendas, descubre nuevas combinaciones y recibe recomendaciones inteligentes basadas en tu estilo real.</p><div class="hero-actions"><button class="btn btn-coral" data-open-auth="register">Crear mi armario <b>→</b></button><span>Gratis para empezar<br><small>Sin tarjeta</small></span></div><div class="trust"><strong>Inditex first</strong><span>ZARA</span><span>Massimo Dutti</span><span>Oysho</span><span>+4 marcas</span></div></div><div class="hero-image"><?php $heroWidths = [480, 768, 1100, 1586]; $heroSizes = '(max-width:900px) 100vw, 54vw'; ?><picture><source type="image/avif" srcset="<?= e(srcset('/assets/hero/wardrobe-hero-%d.avif', $heroWidths)) ?>" sizes="<?= $heroSizes ?>"><source type="image/webp" srcset="<?= e(srcset('/assets/hero/wardrobe-hero-%d.webp', $heroWidths)) ?>" sizes="<?= $heroSizes ?>"><img src="<?= asset('/assets/hero/wardrobe-hero-1100.jpg') ?>" srcset="<?= e(srcset('/assets/hero/wardrobe-hero-%d.jpg', $heroWidths)) ?>" sizes="<?= $heroSizes ?>" width="1586" height="992" fetchpriority="high" decoding="async" alt="Armario cápsula organizado"></picture><div class="floating-card"><i>✦</i><div><b>Look de hoy</b><span>3 prendas tuyas · 18°C</span></div></div></div></section>
<section class="how" id="how"><div><span class="step">01</span><h2>Fotografía o escanea</h2><p>Añade la foto y la referencia de la etiqueta de cada prenda.</p></div><div><span class="step">02</span><h2>Monta dos outfits</h2><p>Con dos looks tuyos, el estilista lee tu estilo y escribe tu perfil.</p></div><div><span class="step">03</span><h2>Descubre tus looks</h2><p>La IA analiza en segundo plano y crea outfits con enlaces verificados.</p></div></section></main>
<?php else: ?>
<main class="app-shell"><aside><div class="welcome"><span>BUENOS DÍAS</span><div class="greeting"><?= e(explode(' ',$currentUser['name'])[0]) ?>.</div><p>¿Qué quieres ponerte hoy?</p></div><button class="side-link active" data-view="wardrobe">▦ <span>Mi armario</span></button><button class="side-link" data-view="outfits">✦ <span>Outfits IA</span></button><button class="side-link" data-view="profile">◌ <span>Mi estilo</span></button><?php if(catalog_admin()): ?><a class="side-link" href="/catalog.php" style="text-decoration:none" aria-label="Catálogo Inditex">▤ <span>Catálogo Inditex</span></a><?php endif ?><div class="ai-card"><b>✦ Estilista IA</b><p>Analiza todo tu armario sin detener la web.</p><label class="ai-option"><input type="checkbox" id="want-shopping"> Buscar prendas que faltan en tiendas</label><label class="ai-budget" id="budget-field" hidden>Presupuesto<input id="shopping-budget" maxlength="<?= ANALYSIS_BUDGET_MAX_LENGTH ?>" placeholder="Hasta 120 €"></label><button class="btn btn-coral full" id="analyze">Analizar armario</button><small id="quota" class="quota"></small><div id="job-mini" hidden><div class="progress"><i></i></div><small>Preparando recomendaciones…</small></div></div></aside>
<section class="workspace view active" id="view-wardrobe"><div class="page-head"><div><span class="eyebrow">COLECCIÓN PERSONAL</span><h1>Mi armario</h1><p id="garment-count"><?= $garmentCount ?> <?= $garmentCount===1?'prenda':'prendas' ?> en tu colección</p></div><button class="btn btn-dark" id="add-garment">＋ Añadir prenda</button></div><?php // Painted by app.js from window.APP.onboarding, like the grid below it. ?><section class="onboarding-panel" id="onboarding-panel" hidden></section><div class="toolbar"><label class="search">⌕ <input id="search" placeholder="Buscar por prenda o referencia…"></label><select id="category-filter"><option value="">Todas las categorías</option><?php foreach(GARMENT_CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach ?></select><button class="btn btn-soft" id="add-from-shop">Escanear etiqueta</button></div><div id="garment-grid" class="garment-grid"></div></section>
<section class="workspace view" id="view-outfits"><div class="page-head"><div><span class="eyebrow">RECOMENDACIONES PERSONALES</span><h1>Tus outfits</h1><p>Combinaciones con las prendas que ya tienes.</p></div><button class="btn btn-coral" id="analyze-top">✦ Nuevo análisis</button></div><div id="jobs" class="jobs"><div class="empty"><b>Aún no hay looks</b><p>Analiza tu armario para crear tus primeras combinaciones.</p></div></div></section>
<section class="workspace view" id="view-profile"><div class="page-head"><div><span class="eyebrow">PERFIL DE ESTILO</span><h1>Cuéntale a Vestia quién eres</h1><p>Cuanto más contexto, mejores serán tus recomendaciones.</p></div><button class="btn btn-soft" id="reread-style">✦ Que lo lea el estilista</button></div><form id="profile-form" class="profile-form"><label>Tu estilo<textarea name="style" placeholder="Minimalista, casual sofisticado, urbano…"><?= e($profile['style']??'') ?></textarea></label><label>Colores que te gustan<input name="colors" value="<?= e($profile['colors']??'') ?>" placeholder="Verde, negro, tonos tierra…"></label><label>Evitar<input name="avoid" value="<?= e($profile['avoid']??'') ?>" placeholder="Estampados, prendas ajustadas…"></label><label>Ocasiones frecuentes<input name="occasions" value="<?= e($profile['occasions']??'') ?>" placeholder="Trabajo, fin de semana, cenas…"></label><button class="btn btn-dark">Guardar preferencias</button></form></section></main>
<?php endif ?>
<div class="modal" id="auth-modal" hidden><div class="modal-card auth-card"><button class="close" data-close>×</button><span class="eyebrow">BIENVENIDO A VESTIA</span><h2 id="auth-title">Entrar</h2><form id="auth-form"><label class="register-only">Nombre<input name="name" autocomplete="name"></label><label>Email<input type="email" name="email" required autocomplete="email"></label><label>Contraseña<div class="password-field"><input type="password" name="password" id="auth-password" required minlength="10" autocomplete="current-password"><button type="button" class="password-toggle" id="password-toggle" aria-pressed="false" aria-label="Mostrar contraseña" title="Mostrar contraseña"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M1.9 12S5.9 5.5 12 5.5 22.1 12 22.1 12 18.1 18.5 12 18.5 1.9 12 1.9 12Z"></path><circle cx="12" cy="12" r="3.2"></circle><line class="eye-slash" x1="4" y1="20" x2="20" y2="4"></line></svg></button></div></label><button class="btn btn-coral full">Continuar</button><p class="form-error"></p></form><button class="text-btn" id="auth-switch">¿No tienes cuenta? Crear armario</button></div></div>
<div class="modal" id="garment-modal" hidden><div class="modal-card wide"><button class="close" data-close>×</button><span class="eyebrow">NUEVA PRENDA</span><h2>Añade algo a tu armario</h2><form id="garment-form" enctype="multipart/form-data"><?php // Which look this is being added to, and where the tag pointed. Both are filled by the app, never typed. ?><input type="hidden" name="outfit_id" value=""><input type="hidden" name="source_url" value=""><input type="hidden" name="price_note" value=""><input type="hidden" name="catalog_token" value="">
<section class="label-scanner" aria-labelledby="label-title">
<h3 id="label-title">Añadir desde una etiqueta</h3>
<p>Fotografía la referencia impresa. Revisa lo leído y busca la prenda en el catálogo guardado.</p>
<label class="btn btn-soft label-camera">Fotografiar etiqueta<input id="label-photo" type="file" accept="image/jpeg,image/png,image/webp" capture="environment"></label>
<span class="label-help">JPG, PNG o WebP · hasta 8 MB. La foto se usa solo para leer la etiqueta.</span>
<div class="label-fields"><label>Marca de la etiqueta<select id="label-brand"><option value="">No la sé · buscar en todas</option><?php foreach(GARMENT_BRANDS as $b): ?><option><?= e($b) ?></option><?php endforeach ?></select></label><label>Referencia<input id="label-reference" inputmode="text" maxlength="40" placeholder="7545/043/800" list="label-references" autocomplete="off"><datalist id="label-references"></datalist></label><label>Talla de la etiqueta<input id="label-size" maxlength="20" placeholder="M"></label></div>
<button type="button" class="btn btn-dark" id="label-search">Buscar referencia</button>
<p id="label-status" role="status" aria-live="polite"></p><div id="label-results" class="label-results"></div>
<label id="label-use-photo" class="label-use-photo" hidden><input type="checkbox" name="use_catalog_image" value="1">Usar foto oficial</label>
</section><p class="link-lead" id="link-lead">También puedes pegar el enlace de la tienda o completar los datos manualmente.</p><div class="link-row"><input id="link-url" inputmode="url" placeholder="https://www.zara.com/es/…"><button type="button" class="btn btn-dark" id="link-fetch">Traer datos</button></div><label class="dropzone"><input type="file" name="image" accept="image/jpeg,image/png,image/webp" capture="environment"><img class="upload-preview" alt="Vista previa de la prenda" hidden><b>＋ Añadir foto</b><span>JPG, PNG o WebP · máx. 8 MB</span></label><div class="form-grid"><label>Nombre<input name="name" required placeholder="Camisa fluida"></label><label>Marca<select name="brand" required><?php foreach(GARMENT_BRANDS as $b): ?><option><?= e($b) ?></option><?php endforeach ?></select></label><label>Referencia de etiqueta<input name="reference" placeholder="Ej. 7545/043/800"></label><label>Categoría<select name="category" required><?php foreach(GARMENT_CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach ?></select></label><label>Color<input name="color" placeholder="Crudo"></label><label>Talla<input name="size" list="link-sizes" placeholder="M"><datalist id="link-sizes"></datalist></label><label>Temporada<select name="season"><option>Todo el año</option><option>Primavera / verano</option><option>Otoño / invierno</option></select></label><label>Notas<input name="notes" placeholder="Corte oversize, lino…"></label></div><button class="btn btn-dark full">Guardar prenda</button><p class="form-error"></p></form></div></div>
<?php if($currentUser): ?><div class="modal" id="reading-modal" hidden><div class="modal-card"><button class="close" data-close>×</button><span class="eyebrow">ESTO ES LO QUE HE VISTO</span><h2>Tu estilo, en tus palabras</h2><p class="onboarding-lead" id="reading-summary"></p><p class="onboarding-hint">Cámbialo si no te reconoces: es tu perfil, y el estilista trabajará con lo que quede aquí. «Evitar» no sale de tus outfits, así que esa la escribes tú.</p><form id="reading-form" class="profile-form"><label>Tu estilo<textarea name="style" placeholder="Minimalista, casual sofisticado, urbano…"></textarea></label><label>Colores que te gustan<input name="colors" placeholder="Verde, negro, tonos tierra…"></label><label>Ocasiones frecuentes<input name="occasions" placeholder="Trabajo, fin de semana, cenas…"></label><label>Evitar<input name="avoid" placeholder="Estampados, prendas ajustadas…"></label><button class="btn btn-dark">Guardar y seguir</button><p class="form-error"></p></form></div></div>
<div class="modal" id="share-modal" hidden><div class="modal-card"><button class="close" data-close>×</button><span class="eyebrow">COMPARTIR LOOK</span><h2 id="share-title">Compartir</h2><p class="share-note">Cualquiera con el enlace podrá ver este look y las prendas que lo componen. El resto de tu armario y de tu análisis no se comparte.</p><label class="share-link">Enlace<input id="share-url" readonly value="Creando el enlace…"></label><div class="share-actions"><button class="btn btn-dark" id="share-copy">Copiar enlace</button><button class="btn btn-coral" id="share-native" hidden>Compartir…</button></div><p class="share-stats" id="share-stats"></p><button class="text-btn" id="share-revoke" hidden>Dejar de compartir</button><p class="form-error"></p></div></div><?php endif ?>
<div id="toast" role="status"></div><script>window.APP=<?= json_encode(['csrf'=>csrf_token(),'garments'=>$garments,'sizes'=>$sizes,'onboarding'=>$onboarding,'categories'=>GARMENT_CATEGORIES], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script><script src="<?= asset('/assets/app.js') ?>"></script></body></html>
