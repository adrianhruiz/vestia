<?php
declare(strict_types=1);
function catalog_admin(): bool {
 $uid=user_id(); if (!$uid) return false;
 $s=db()->prepare('SELECT 1 FROM catalog_administrators a JOIN users u ON u.id=a.user_id WHERE a.user_id=?');
 $s->execute([$uid]); return (bool)$s->fetchColumn();
}
function catalog_db(): PDO {
 $p=new PDO('sqlite:file:/var/lib/inditex-catalog/viewer/catalog.sqlite3?mode=ro',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $p->exec('PRAGMA query_only=ON'); return $p;
}
function catalog_filters(): array {
 $parts=['1=1'];$args=[];
 foreach(['brand','category'] as $key) if (is_string($_GET[$key]??null) && $_GET[$key]!=='') {$parts[]="$key=?";$args[]=mb_substr($_GET[$key],0,150);}
 if (is_string($_GET['q']??null) && trim($_GET['q'])!=='') {$q='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_substr(trim($_GET['q']),0,150)).'%';$parts[]="(name LIKE ? ESCAPE '!' OR reference LIKE ? ESCAPE '!' OR color_text LIKE ? ESCAPE '!')";array_push($args,$q,$q,$q);}
 if (is_string($_GET['color']??null) && $_GET['color']!=='') {$parts[]="color_text LIKE ? ESCAPE '!'";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_substr($_GET['color'],0,80)).'%';}
 foreach(['min'=>'price>=?','max'=>'price<=?'] as $key=>$condition) if (isset($_GET[$key]) && is_scalar($_GET[$key]) && is_numeric($_GET[$key])) {$parts[]=$condition;$args[]=max(0,(float)$_GET[$key]);}
 if (($_GET['available']??'')==='1') $parts[]="availability IN ('in_stock','InStock','https://schema.org/InStock')";
 return [implode(' AND ',$parts),$args];
}
function catalog_image_gateway(string $route,array $params): array {
 $c=curl_init('http://localhost/'.$route.'?'.http_build_query($params));
 curl_setopt_array($c,[CURLOPT_UNIX_SOCKET_PATH=>'/var/lib/inditex-catalog/viewer/image-gateway.sock',CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false]);
 $body=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$mime=(string)curl_getinfo($c,CURLINFO_CONTENT_TYPE);curl_close($c);
 return [$body===false?502:$status,$mime,$body===false?'':$body];
}
function catalog_scrape_control(bool $start=false): never {
 $output=[];$exit=0;
 exec('/usr/bin/sudo -n /usr/local/sbin/vestia-catalog-control '.($start?'start':'status').' 2>/dev/null',$output,$exit);
 $data=json_decode(implode("\n",$output),true);
 if($exit!==0||!is_array($data)||isset($data['error']))json_response(['error'=>'No se ha podido consultar o iniciar la recopilación. Inténtalo de nuevo.'],503);
 json_response($data,$start&&!empty($data['accepted'])?202:200);
}
function catalog_api(PDO $db,string $action): never {
 if ($action==='product-image') {
  $params=['product'=>max(0,(int)($_GET['id']??0)),'position'=>max(0,(int)($_GET['position']??0)),'size'=>($_GET['size']??'')==='full'?'full':'thumb'];
  [$status,$mime,$body]=catalog_image_gateway('image',$params);
  if($status!==200||!in_array($mime,['image/jpeg','image/png','image/webp','image/avif'],true))json_response(['error'=>'Imagen temporalmente no disponible.'], $status===404?404:502);
  header('Content-Type: '.$mime);header('Content-Length: '.strlen($body));echo $body;exit;
 }
 if ($action==='list') {
  [$where,$args]=catalog_filters();$s=$db->prepare("SELECT COUNT(*) FROM products WHERE $where");$s->execute($args);$total=(int)$s->fetchColumn();
  $page=max(1,min(100000,(int)($_GET['page']??1)));$limit=24;$pages=max(1,(int)ceil($total/$limit));$page=min($page,$pages);
  $sort=['newest'=>'captured_at DESC,id DESC','price_asc'=>'price IS NULL,price ASC,id DESC','price_desc'=>'price IS NULL,price DESC,id DESC','name'=>'name COLLATE NOCASE,id DESC'][$_GET['sort']??'newest']??'captured_at DESC,id DESC';
  $s=$db->prepare("SELECT id,brand,name,reference,category,color_text,price,currency,availability,captured_at,main_image FROM products WHERE $where ORDER BY $sort LIMIT $limit OFFSET ".(($page-1)*$limit));$s->execute($args);
  json_response(['items'=>$s->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>$pages]);
 }
 if ($action==='meta') {
  $brands=json_decode($db->query("SELECT value FROM metadata WHERE key='brands'")->fetchColumn(),true);
  $counts=$db->query('SELECT brand,COUNT(*) count,MAX(captured_at) updated_at FROM products GROUP BY brand')->fetchAll();$map=array_column($counts,null,'brand');
  $all=[];foreach($brands as $id=>$name)$all[]=['id'=>$id,'name'=>$name,'count'=>(int)($map[$id]['count']??0),'updated_at'=>$map[$id]['updated_at']??null];
  $status=json_decode($db->query("SELECT value FROM metadata WHERE key='source_status'")->fetchColumn()?:'[]',true);
  json_response(['brands'=>$all,'categories'=>$db->query("SELECT DISTINCT category FROM products WHERE category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN),'generated_at'=>json_decode($db->query("SELECT value FROM metadata WHERE key='generated_at'")->fetchColumn(),true),'source_status'=>$status]);
 }
 if ($action==='detail') {
  $s=$db->prepare('SELECT * FROM products WHERE id=?');$s->execute([(int)($_GET['id']??0)]);$p=$s->fetch();if(!$p)json_response(['error'=>'Prenda no encontrada.'],404);
  foreach(['colors','sizes','tags','composition','images','raw'] as $k){$p[$k]=json_decode($p[$k.'_json']??'null',true);unset($p[$k.'_json']);}
  [$status,,$body]=catalog_image_gateway('sources',['product'=>(int)$p['id']]);
  if($status===200){$sources=json_decode($body,true);if(!empty($sources['images']))$p['images']=$sources['images'];}
  json_response(['product'=>$p]);
 }
 if ($action==='image') {
  $s=$db->prepare('SELECT * FROM images WHERE id=?');$s->execute([(int)($_GET['id']??0)]);$p=$s->fetch();if(!$p)json_response(['error'=>'Imagen no encontrada.'],404);
  $thumb=($_GET['size']??'thumb')!=='full';$relative=$p[$thumb?'thumb':'path'];
  $root='/var/lib/inditex-catalog/';$file=realpath($root.$relative);$base=realpath($root.($thumb?'viewer/thumbs':'media'));
  if(!$file||!$base||!str_starts_with($file,$base.'/')||!is_readable($file))json_response(['error'=>'Imagen no disponible.'],404);
  if(!in_array($p['mime'],['image/jpeg','image/png','image/webp','image/avif'],true))json_response(['error'=>'Formato no disponible.'],404);
  header('Content-Type: '.($thumb?'image/webp':$p['mime']));header('Content-Length: '.filesize($file));readfile($file);exit;
 }
 json_response(['error'=>'Ruta no encontrada.'],404);
}
