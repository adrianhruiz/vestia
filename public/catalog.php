<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/Catalog.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
$action=is_string($_GET['action']??null)?$_GET['action']:'';
if (!user_id() || !catalog_admin()) {
 if($action!=='')json_response(['error'=>user_id()?'No tienes acceso al catálogo.':'Debes iniciar sesión.'],user_id()?403:401);
 http_response_code(user_id()?403:401);
 echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Catálogo privado · Vestia</title><main style="max-width:520px;margin:15vh auto;font-family:system-ui;padding:24px"><h1>Catálogo privado</h1><p>'.(user_id()?'Esta sección está reservada a los administradores autorizados.':'Inicia sesión con tu cuenta de administrador para consultar el catálogo.').'</p><a href="/">Volver a Vestia</a></main></html>';exit;
}
$catalogCsrf=csrf_token();
if($action==='scrape-start') {
 if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');json_response(['error'=>'Método no permitido.'],405);}
 require_csrf();session_write_close();catalog_scrape_control(true);
}
session_write_close();
if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET');json_response(['error'=>'Método no permitido.'],405);}
if($action==='scrape-status')catalog_scrape_control();
if($action!==''){try{catalog_api(catalog_db(),$action);}catch(Throwable $e){error_log('Catalog viewer: '.$e->getMessage());json_response(['error'=>'El catálogo se está preparando. Vuelve a intentarlo en unos instantes.'],503);}}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#183f36"><meta name="csrf-token" content="<?=e($catalogCsrf)?>"><title>Catálogo Inditex · Vestia</title><link rel="stylesheet" href="<?=e(asset('/assets/app.css'))?>"><link rel="stylesheet" href="<?=e(asset('/assets/catalog.css'))?>"></head>
<body class="catalog-body"><header class="catalog-top"><a class="brand" href="/"><span>V</span> Vestia</a><a class="catalog-back" href="/">← Mi armario</a><span class="catalog-admin">Administración</span></header>
<main class="catalog-shell"><div class="catalog-heading"><div><span class="eyebrow">BIBLIOTECA DE PRENDAS · ESPAÑA</span><h1>Catálogo Inditex</h1><p>Explora las prendas, sus variantes y todas sus imágenes disponibles.</p></div><div class="catalog-actions"><button class="btn btn-dark" id="catalog-scrape" type="button" disabled>Buscar novedades del catálogo</button><p class="catalog-scrape-date" id="catalog-scrape-date">Último scraping: consultando…</p><p class="catalog-scrape-feedback" id="catalog-scrape-feedback" role="status" aria-live="polite"></p><button class="btn btn-soft" id="catalog-refresh" type="button">↻ Actualizar vista</button></div></div>
<section class="catalog-summary" aria-label="Resumen del catálogo"><div><strong id="catalog-total">—</strong><span>productos guardados</span></div><div><strong id="catalog-brands-count">—</strong><span>marcas con productos</span></div><div><strong id="catalog-updated">—</strong><span>última captura de productos</span></div></section>
<details class="catalog-coverage"><summary>Estado de las 8 tiendas <span id="coverage-note">Cargando…</span></summary><p>El catálogo recoge lo que se ha podido descargar. La presencia de productos no confirma que una tienda esté completa.</p><div id="catalog-brand-status"></div></details>
<form id="catalog-filters"><div class="catalog-filter-main"><label>Buscar<input name="q" type="search" placeholder="Prenda, referencia o color" maxlength="150"></label><label>Marca<select name="brand" id="catalog-brand"><option value="">Todas las marcas</option></select></label><label>Categoría<select name="category" id="catalog-category"><option value="">Todas las categorías</option></select></label><button class="btn btn-dark" type="submit">Buscar</button></div><div class="catalog-filter-extra"><label>Color<input name="color" placeholder="Negro, crudo…" maxlength="80"></label><label>Precio mínimo<input name="min" type="number" min="0" step="0.01" placeholder="€"></label><label>Precio máximo<input name="max" type="number" min="0" step="0.01" placeholder="€"></label><label>Orden<select name="sort"><option value="newest">Últimas capturas</option><option value="price_asc">Precio: menor a mayor</option><option value="price_desc">Precio: mayor a menor</option><option value="name">Nombre</option></select></label><label class="catalog-check"><input name="available" type="checkbox" value="1">Disponibles en la captura</label><button class="catalog-reset" type="reset">Limpiar filtros</button></div></form>
<div class="catalog-results-line"><p id="catalog-result-count" role="status" aria-live="polite">Cargando catálogo…</p><span>Imágenes sin recortar</span></div><div id="catalog-grid" class="catalog-grid" aria-busy="true"></div><div id="catalog-error" role="alert" hidden></div><nav class="catalog-pagination" aria-label="Páginas del catálogo"><button class="btn btn-soft" id="catalog-prev">← Anterior</button><span id="catalog-page"></span><button class="btn btn-soft" id="catalog-next">Siguiente →</button></nav></main>
<dialog id="catalog-detail"><div class="catalog-detail-head"><span class="eyebrow">FICHA DE PRODUCTO</span><button id="catalog-close" aria-label="Cerrar ficha">×</button></div><div id="catalog-detail-content"></div></dialog>
<script src="<?=e(asset('/assets/catalog.js'))?>" defer></script></body></html>
