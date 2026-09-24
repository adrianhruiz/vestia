
(()=>{'use strict';
const $=s=>document.querySelector(s), form=$('#catalog-filters'), grid=$('#catalog-grid'), dialog=$('#catalog-detail');
let brands={},page=1,pages=1,requestNumber=0,detailNumber=0;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money=(v,c='EUR')=>v===null?'Precio no disponible':new Intl.NumberFormat('es-ES',{style:'currency',currency:c}).format(v);
const date=v=>v?new Date(v).toLocaleDateString('es-ES'):'Sin capturas';
const image=(id,full=false)=>'/catalog.php?action=image&id='+Number(id)+(full?'&size=full':'');
const productImage=(id,position=0,full=false)=>'/catalog.php?action=product-image&id='+Number(id)+'&position='+Number(position)+(full?'&size=full':'');
const photoUrl=(photo,product,full=false)=>photo.source_index!==undefined?productImage(product,photo.source_index,full):image(photo.id,full);
async function api(params){const r=await fetch('/catalog.php?'+new URLSearchParams(params),{cache:'no-store'});const d=await r.json();if(!r.ok)throw new Error(d.error||'No se pudo cargar el catálogo.');return d;}
function fields(){const p=Object.fromEntries(new FormData(form));p.page=page;p.action='list';return p;}
async function meta(){const d=await api({action:'meta'});brands=Object.fromEntries(d.brands.map(b=>[b.id,b.name]));const current=form.elements.brand.value,cat=form.elements.category.value;
 $('#catalog-brand').innerHTML='<option value="">Todas las marcas</option>'+d.brands.map(b=>'<option value="'+esc(b.id)+'">'+esc(b.name)+' ('+b.count+')</option>').join('');form.elements.brand.value=current;
 $('#catalog-category').innerHTML='<option value="">Todas las categorías</option>'+d.categories.map(c=>'<option>'+esc(c)+'</option>').join('');form.elements.category.value=cat;
 $('#catalog-total').textContent=d.brands.reduce((n,b)=>n+b.count,0).toLocaleString('es-ES');const count=d.brands.filter(b=>b.count).length;$('#catalog-brands-count').textContent=count+' / '+d.brands.length;$('#coverage-note').textContent=count+' marcas con datos · cobertura pendiente de completar';
 const dates=d.brands.map(b=>b.updated_at).filter(Boolean).sort();$('#catalog-updated').textContent=date(dates.at(-1));
 const states={blocked:'Último intento bloqueado',failed:'Último intento fallido',running:'Recopilación en curso',partial:'Recopilación parcial',complete:'Recopilación finalizada',unsupported:'Extracción pendiente',interrupted:'Recopilación interrumpida'};
 $('#catalog-brand-status').innerHTML=d.brands.map(b=>{const s=d.source_status.find(x=>x.brand===b.id);return '<div class="coverage-row"><b>'+esc(b.name)+'</b><span>'+b.count+' prendas</span><span>'+esc(s?(states[s.status]||'Cobertura sin verificar'):'Cobertura sin verificar')+(b.updated_at?' · captura '+date(b.updated_at):' · sin capturas')+'</span></div>';}).join('');
}
async function load(){const n=++requestNumber;grid.setAttribute('aria-busy','true');$('#catalog-error').hidden=true;
 try{const d=await api(fields());if(n!==requestNumber)return;page=d.page;pages=d.pages;
 $('#catalog-result-count').textContent=d.total.toLocaleString('es-ES')+' productos encontrados';$('#catalog-page').textContent='Página '+page+' de '+pages;
 $('#catalog-prev').disabled=page<=1;$('#catalog-next').disabled=page>=pages;
 grid.innerHTML=d.items.length?d.items.map(p=>'<button class="catalog-card" data-id="'+p.id+'" aria-label="Ver '+esc(p.name)+'"><div class="catalog-photo">'+('<img src="'+(p.main_image?image(p.main_image):productImage(p.id))+'" alt="'+esc(p.name)+'" loading="lazy" decoding="async">')+'</div><div class="catalog-card-info"><div class="catalog-card-top"><span class="catalog-card-brand">'+esc(brands[p.brand]||p.brand)+'</span><span class="catalog-card-price">'+money(p.price,p.currency)+'</span></div><h2>'+esc(p.name)+'</h2><small>'+esc(p.reference)+'</small><small>'+esc(p.color_text||'Color no informado')+'</small></div></button>').join(''):'<div class="catalog-empty"><h2>No hay productos para estos filtros</h2><p>Prueba otra marca o limpia los filtros. Algunas tiendas todavía no tienen capturas.</p></div>';
 grid.querySelectorAll('img').forEach(im=>im.addEventListener('error',()=>{im.replaceWith(Object.assign(document.createElement('span'),{className:'catalog-no-photo',textContent:'Imagen no disponible'}));}));
 }catch(e){if(n!==requestNumber)return;$('#catalog-error').hidden=false;$('#catalog-error').textContent=e.message;$('#catalog-result-count').textContent='No se ha podido cargar el catálogo.';}
 finally{if(n===requestNumber)grid.setAttribute('aria-busy','false');}
}
const pills=a=>'<div class="catalog-pills">'+(Array.isArray(a)?a:[]).map(v=>'<span class="catalog-pill">'+esc(typeof v==='object'?v.name||JSON.stringify(v):v)+'</span>').join('')+'</div>';
function storeLink(p){try{const u=new URL(p.url);const domains=['www.zara.com','www.pullandbear.com','www.massimodutti.com','www.bershka.com','www.stradivarius.com','www.oysho.com','www.zarahome.com','www.lefties.com'];return u.protocol==='https:'&&domains.includes(u.hostname)?'<a class="btn btn-dark" href="'+esc(u.href)+'" target="_blank" rel="noopener noreferrer">Ver en la tienda ↗</a>':'';}catch{return '';}}
async function detail(id){const n=++detailNumber;$('#catalog-detail-content').innerHTML='<p style="padding:30px" role="status">Cargando ficha…</p>';dialog.showModal();
 try{const {product:p}=await api({action:'detail',id});if(n!==detailNumber||!dialog.open)return;const images=p.images||[];
 $('#catalog-detail-content').innerHTML='<div class="catalog-detail-grid"><div>'+(images.length?'<img class="catalog-hero-photo" id="catalog-hero" src="'+photoUrl(images[0],p.id,true)+'" alt="'+esc(p.name)+'"><div class="catalog-thumbnails">'+images.map((im,i)=>'<button data-image="'+i+'" aria-label="Ver imagen '+(i+1)+'" aria-pressed="'+(i===0)+'"><img src="'+photoUrl(im,p.id)+'" alt="" loading="lazy"></button>').join('')+'</div>':'<div class="catalog-hero-photo catalog-empty">Sin imágenes disponibles</div>')+'<p class="catalog-legal-note">Las fotografías se muestran completas, sin recortar. La vista principal aún no tiene revisión visual.</p></div><div class="catalog-detail-copy"><span class="eyebrow">'+esc(brands[p.brand]||p.brand)+'</span><h2>'+esc(p.name)+'</h2><div class="catalog-detail-price">'+money(p.price,p.currency)+'</div>'+pills(p.tags)+'<p>'+esc(p.description||'La captura no incluye una descripción adicional.')+'</p><dl class="catalog-meta"><dt>Referencia</dt><dd>'+esc(p.reference)+'</dd><dt>Categoría</dt><dd>'+esc(p.category||'No informada')+'</dd><dt>Colores</dt><dd>'+(p.colors?.length?pills(p.colors):'No informados')+'</dd><dt>Tallas</dt><dd>'+(p.sizes?.length?pills(p.sizes):'No informadas en esta captura')+'</dd><dt>Disponibilidad</dt><dd>'+esc(({in_stock:'Disponible en la captura',out_of_stock:'Agotado en la captura'})[p.availability]||p.availability||'No informada')+'</dd><dt>Captura</dt><dd>'+date(p.captured_at)+'</dd><dt>Imágenes</dt><dd>'+images.length+'</dd></dl>'+storeLink(p)+'<p class="catalog-legal-note">Precio y disponibilidad corresponden a la captura; comprueba los datos actuales en la tienda.</p><details><summary>Composición y cuidados</summary><pre>'+esc(p.composition?JSON.stringify(p.composition,null,2):'No disponibles en esta captura.')+'</pre></details><details><summary>Todos los datos guardados</summary><pre>'+esc(JSON.stringify(p.raw,null,2))+'</pre></details></div></div>';
 $('#catalog-detail-content').querySelectorAll('[data-image]').forEach(b=>b.addEventListener('click',()=>{$('#catalog-hero').src=photoUrl(images[Number(b.dataset.image)],p.id,true);dialog.querySelectorAll('[data-image]').forEach(x=>x.setAttribute('aria-pressed',String(x===b)));}));
 }catch(e){$('#catalog-detail-content').innerHTML='<p role="alert" style="padding:30px">'+esc(e.message)+'</p>';}
}
grid.addEventListener('click',e=>{const b=e.target.closest('[data-id]');if(b)detail(b.dataset.id);});
$('#catalog-close').addEventListener('click',()=>dialog.close());dialog.addEventListener('click',e=>{if(e.target===dialog&&e.clientX<dialog.getBoundingClientRect().left)dialog.close();});
form.addEventListener('submit',e=>{e.preventDefault();page=1;load();});form.addEventListener('reset',()=>{setTimeout(()=>{page=1;load();},0);});
$('#catalog-prev').addEventListener('click',()=>{page--;load();});$('#catalog-next').addEventListener('click',()=>{page++;load();});
$('#catalog-refresh').addEventListener('click',async()=>{try{await meta();await load();}catch(e){$('#catalog-error').hidden=false;$('#catalog-error').textContent=e.message;}});
(async()=>{try{await meta();await load();}catch(e){grid.setAttribute('aria-busy','false');$('#catalog-error').hidden=false;$('#catalog-error').textContent=e.message;}})();
const scrapeButton=$('#catalog-scrape'),scrapeDate=$('#catalog-scrape-date'),scrapeFeedback=$('#catalog-scrape-feedback');
let scrapeRequest=false,statusRequest=false;
const scrapeTime=v=>new Intl.DateTimeFormat('es-ES',{dateStyle:'short',timeStyle:'short',timeZone:'Europe/Madrid'}).format(new Date(v));
function renderScrapeStatus(d){
 scrapeButton.disabled=Boolean(d.busy)||scrapeRequest;
 scrapeButton.textContent=d.starting?'Iniciando recopilación…':d.retrying?'Reintentando recopilación…':d.busy?'Recopilación en curso…':'Buscar novedades del catálogo';
 const last=d.last_run,states={running:'en curso',complete:'finalizado',incomplete:'finalizado con incidencias',partial:'parcial',blocked:'bloqueado',failed:'fallido',interrupted:'interrumpido'};
 scrapeDate.textContent=last?'Último scraping: '+scrapeTime(last.finished_at||last.started_at)+' · '+(states[last.status]||last.status)+' (hora peninsular)':'Último scraping: todavía no se ha realizado';
}
async function scrapeStatus(){
 if(statusRequest||scrapeRequest)return;statusRequest=true;
 try{renderScrapeStatus(await api({action:'scrape-status'}));}
 catch(e){scrapeButton.disabled=true;scrapeDate.textContent='No se ha podido consultar la fecha del último scraping.';}
 finally{statusRequest=false;}
}
scrapeButton.addEventListener('click',async()=>{
 if(scrapeRequest)return;scrapeRequest=true;scrapeButton.disabled=true;scrapeButton.textContent='Iniciando recopilación…';scrapeFeedback.textContent='';
 try{const r=await fetch('/catalog.php?action=scrape-start',{method:'POST',cache:'no-store',headers:{'X-CSRF-Token':$('meta[name="csrf-token"]').content}});const d=await r.json();if(!r.ok)throw new Error(d.error||'No se pudo iniciar la recopilación.');scrapeFeedback.textContent=d.message;renderScrapeStatus(d);}
 catch(e){scrapeFeedback.textContent=e.message;}
 finally{scrapeRequest=false;await scrapeStatus();}
});
scrapeStatus();setInterval(()=>{if(!document.hidden)scrapeStatus();},15000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)scrapeStatus();});
})();
