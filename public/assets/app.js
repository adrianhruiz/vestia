const $=(s,c=document)=>c.querySelector(s), $$=(s,c=document)=>[...c.querySelectorAll(s)];
const api=async(url,options={})=>{options.headers={...(options.headers||{}),'X-CSRF-Token':window.APP.csrf};const r=await fetch(url,options);const d=await r.json().catch(()=>({error:'Respuesta no válida'}));if(!r.ok)throw new Error(d.error||'Ha ocurrido un error');return d};
const toast=m=>{const t=$('#toast');t.textContent=m;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2600)};
const openModal=id=>$(id).hidden=false;const closeModal=m=>m.closest('.modal').hidden=true;
$$('[data-close]').forEach(b=>b.onclick=()=>closeModal(b));$$('.modal').forEach(m=>m.onclick=e=>{if(e.target===m)m.hidden=true});
let authMode='login';function setAuth(mode){authMode=mode;$('#auth-title').textContent=mode==='login'?'Entrar':'Crea tu armario';$('.register-only').style.display=mode==='register'?'grid':'none';$('#auth-switch').textContent=mode==='login'?'¿No tienes cuenta? Crear armario':'Ya tengo cuenta';openModal('#auth-modal')}
$$('[data-open-auth]').forEach(b=>b.onclick=()=>setAuth(b.dataset.openAuth));$('#auth-switch')?.addEventListener('click',()=>setAuth(authMode==='login'?'register':'login'));
$('#auth-form')?.addEventListener('submit',async e=>{e.preventDefault();const f=new FormData(e.currentTarget),body=Object.fromEntries(f);const err=$('.form-error',e.currentTarget);err.textContent='';try{const r=await api('/api/'+authMode,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});const next=r.next||window.APP.after_auth;next?location.assign(next):location.reload()}catch(x){err.textContent=x.message}});
$('#logout')?.addEventListener('click',async()=>{await api('/api/logout',{method:'POST'});location.reload()});
if(document.body.dataset.auth==='1'){
 let activeJob=null,pollTimer=null,polling=false,garmentCache=[],resultJobId=null,lastResult=null,shares={};
 let onboarding=window.APP.onboarding||null;
 // An analysis takes one to three minutes. Polling it at a flat 2.5s meant ~50 requests
 // per run, so back off towards 10s, stop while the tab is in the background, and give up
 // rather than poll a job that is never going to answer.
 const POLL_MIN=2000,POLL_MAX=10000,POLL_GROWTH=1.4,POLL_GIVE_UP=10*60*1000;
 let pollDelay=POLL_MIN,pollStartedAt=0;
 const switchView=view=>{$$('.view').forEach(v=>v.classList.toggle('active',v.id==='view-'+view));$$('[data-view]').forEach(b=>{const on=b.dataset.view===view;b.classList.toggle('active',on);on?b.setAttribute('aria-current','page'):b.removeAttribute('aria-current')});if(view==='outfits')loadJobs()};$$('[data-view]').forEach(b=>b.onclick=()=>switchView(b.dataset.view));
 // The size box fills itself from the last garment in this category, so nobody is asked
 // for "tallas habituales" before they own a single thing. A category never worn says so in
 // its placeholder, which is the one moment asking is worth anything.
 const garmentForm=$('#garment-form'),sizeInput=$('[name=size]',garmentForm),categorySelect=$('[name=category]',garmentForm);
 const rememberedSizes=window.APP.sizes||{};
 const suggestSize=()=>{
  const remembered=rememberedSizes[categorySelect.value];
  if(remembered&&!sizeInput.value)sizeInput.value=remembered;
  sizeInput.placeholder=remembered?remembered:'¿Qué talla usas en esta categoría?'
 };
 categorySelect.addEventListener('change',suggestSize);
 // Opening the form is one function because it has three callers -- the button, the
 // "escanear etiqueta" shortcut and the welcome panel's slots -- and two of them arrive with
 // something already decided: the category the slot was asking for, or the look to join.
 const openGarmentForm=(outfitId='',category='')=>{
  garmentForm.reset();clearPreview();resetLinkBox();
  $('[name=outfit_id]',garmentForm).value=outfitId;
  if(category)categorySelect.value=category;
  suggestSize();openModal('#garment-modal')
 };
 $('#add-garment').onclick=()=>openGarmentForm();
 $('#add-from-shop').onclick=()=>{openGarmentForm();linkInput.focus()};
 const imageInput=$('input[name=image]',garmentForm),preview=$('.upload-preview',garmentForm);let previewUrl='';
 const clearPreview=()=>{if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl='';preview.src='';preview.hidden=true;$('.dropzone b').textContent='＋ Añadir foto'};
 imageInput.addEventListener('change',()=>{if(previewUrl)URL.revokeObjectURL(previewUrl);const file=imageInput.files[0];previewUrl=file?URL.createObjectURL(file):'';preview.src=previewUrl;preview.hidden=!file;$('.dropzone b').textContent=file?'Cambiar foto':'＋ Añadir foto'});
 garmentForm.addEventListener('submit',async e=>{
  e.preventDefault();const err=$('.form-error',e.currentTarget);err.textContent='';
  try{
   const d=await api('/api/garments',{method:'POST',body:new FormData(e.currentTarget)});
   if(sizeInput.value.trim())rememberedSizes[categorySelect.value]=sizeInput.value.trim();
   e.currentTarget.reset();clearPreview();resetLinkBox();$('#garment-modal').hidden=true;
   toast('Prenda añadida a tu armario');await loadGarments();
   // The server did the joining, so the panel is repainted from what it answered rather than
   // from a second request that could disagree with it.
   if(d.onboarding)applyOnboarding(d)
  }catch(x){err.textContent=x.message}
 });
 const renderGarments=items=>{garmentCache=items;$('#garment-count').textContent=`${items.length} ${items.length===1?'prenda':'prendas'} en tu colección`;$('#garment-grid').innerHTML=items.length?items.map(g=>`<article class="garment"><button class="delete-item" data-delete="${g.id}" aria-label="Eliminar">×</button><div class="garment-image">${thumbOf(g)?`<img src="${safeAssetUrl(thumbOf(g))}" alt="${esc(g.name)}" loading="lazy" decoding="async">`:`<span class="garment-placeholder">${garmentIcon(g.category)}</span>`}</div><div class="garment-body"><span class="garment-brand">${esc(g.brand)}</span><h2>${esc(g.name)}</h2><div class="garment-meta">${esc([g.color,g.size,g.reference,g.price_note].filter(Boolean).join(' · '))}</div>${g.source_url?`<a class="product-source" href="${safeUrl(g.source_url)}" target="_blank" rel="noopener noreferrer">Ver producto real ↗</a>`:''}</div></article>`).join(''):'<div class="empty"><b>Tu armario está esperando</b><p>Añade la primera prenda con una foto o su referencia.</p></div>';$$('[data-delete]').forEach(b=>b.onclick=async()=>{if(confirm('¿Eliminar esta prenda?')){await api('/api/garments/'+b.dataset.delete,{method:'DELETE'});loadGarments()}})};
 const loadGarments=async()=>{const q=encodeURIComponent($('#search').value),cat=encodeURIComponent($('#category-filter').value);renderGarments((await api(`/api/garments?q=${q}&category=${cat}`)).items)};
 let searchTimer;$('#search').oninput=()=>{clearTimeout(searchTimer);searchTimer=setTimeout(loadGarments,250)};$('#category-filter').onchange=loadGarments;
 // The unfiltered wardrobe ships inside the page, so the first paint costs no request.
 Array.isArray(window.APP.garments)?renderGarments(window.APP.garments):loadGarments();
 $('#profile-form').addEventListener('submit',async e=>{e.preventDefault();await api('/api/profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.fromEntries(new FormData(e.currentTarget)))});toast('Preferencias guardadas')});
 // The budget is about this shopping trip, so it appears with the switch that starts one
 // and is gone the rest of the time.
 const shoppingToggle=$('#want-shopping'),budgetField=$('#budget-field');
 const toggleBudget=()=>{budgetField.hidden=!shoppingToggle.checked};
 shoppingToggle.addEventListener('change',toggleBudget);toggleBudget();
 const startAnalysis=async(force=false)=>{
  try{
   const d=await api('/api/analyze',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({shopping:$('#want-shopping')?.checked===true,budget:$('#shopping-budget')?.value||'',force})});
   // Nothing has changed since the last run, so the answer is already paid for.
   if(d.reused){
    switchView('outfits');
    const when=d.finished_at?new Date(d.finished_at).toLocaleDateString('es-ES',{day:'numeric',month:'long'}):'la última vez';
    if(confirm(`Tu armario y tu perfil no han cambiado desde el análisis del ${when}, así que el resultado sería el mismo.\n\n¿Quieres gastar un análisis nuevo de todas formas?`))return startAnalysis(true);
    toast('Te enseño el análisis anterior');return
   }
   activeJob=d.job_id;showMini(3);switchView('outfits');startPolling()
  }catch(x){toast(x.message)}
 };$('#analyze').onclick=()=>startAnalysis();$('#analyze-top').onclick=()=>startAnalysis();
 function showMini(p,phase){$('#job-mini').hidden=false;$('.progress i',$('#job-mini')).style.width=p+'%';if(phase)$('#job-mini small').textContent=phase}
 // One chain at a time. Every entry point used to call pollJob() directly, and pollJob
 // re-armed itself without clearing the previous timer, so opening Outfits twice left two
 // independent loops hitting the API every 2.5s for as long as the analysis ran.
 function startPolling(){if(polling)return;polling=true;pollDelay=POLL_MIN;pollStartedAt=Date.now();pollJob()}
 function stopPolling(){polling=false;clearTimeout(pollTimer);pollTimer=null}
 async function pollJob(){
  if(!activeJob){stopPolling();return}
  if(document.hidden){stopPolling();return}
  if(Date.now()-pollStartedAt>POLL_GIVE_UP){stopPolling();toast('El análisis sigue en marcha. Recarga la página para ver cómo va.');return}
  try{const {job}=await api('/api/jobs/'+activeJob);showMini(job.progress,job.phase);renderRunning(job);
   if(!['queued','processing'].includes(job.status)){activeJob=null;stopPolling();$('#job-mini').hidden=true;loadJobs();return}
  }catch(e){toast(e.message)}
  if(!polling)return;
  clearTimeout(pollTimer);pollTimer=setTimeout(pollJob,pollDelay);
  pollDelay=Math.min(Math.round(pollDelay*POLL_GROWTH),POLL_MAX)
 }
 // A backgrounded tab stops polling entirely and picks up where it left off on return.
 document.addEventListener('visibilitychange',()=>{if(!document.hidden&&activeJob)startPolling()});
 const JOB_TITLES={failed:'No se pudo completar',cancelled:'Análisis cancelado'};
 function renderRunning(j){
  const running=['queued','processing'].includes(j.status);
  $('#jobs').innerHTML=`<div class="job-running"><b>${JOB_TITLES[j.status]||'Tu estilista está trabajando'}</b><p>${esc(j.error||j.phase||'Puedes seguir usando la web; el análisis continúa en segundo plano.')}</p><div class="progress"><i style="width:${j.progress}%"></i></div><small>${j.progress}% completado</small>${running?'<button class="btn btn-soft" id="cancel-job">Cancelar análisis</button>':''}</div>`;
  const cancel=$('#cancel-job');
  if(cancel)cancel.onclick=async()=>{
   if(!confirm('¿Cancelar el análisis? Lo que ya haya pedido a la IA no se recupera.'))return;
   cancel.disabled=true;
   try{await api(`/api/jobs/${j.id}/cancel`,{method:'POST'});activeJob=null;stopPolling();$('#job-mini').hidden=true;toast('Análisis cancelado');loadJobs()}
   catch(x){cancel.disabled=false;toast(x.message)}
  }
 }
 function showQuota(left){
  const el=$('#quota');if(!el)return;
  // null means an exempt account; there is no number worth showing there.
  el.textContent=left===null||left===undefined?'':left>0?`${left} ${left===1?'análisis restante':'análisis restantes'} esta hora`:'Sin análisis disponibles hasta dentro de un rato';
 }
 async function loadJobs(){const d=await api('/api/jobs'),{jobs,result}=d;showQuota(d.analyses_left);const running=jobs.find(j=>['queued','processing'].includes(j.status));if(running){activeJob=running.id;renderRunning(running);startPolling();return}if(result){resultJobId=d.result_job_id;shares=d.shares||{};renderResult(result);return}const last=jobs[0];$('#jobs').innerHTML=last&&last.status==='failed'?`<div class="job-running"><b>Último análisis fallido</b><p>${esc(last.error)}</p></div>`:last&&last.status==='cancelled'?'<div class="job-running"><b>Análisis cancelado</b><p>Puedes lanzar uno nuevo cuando quieras.</p></div>':'<div class="empty"><b>Aún no hay looks</b><p>Analiza tu armario para crear tus primeras combinaciones.</p></div>'}
 function outfitVisual(o){if(o.image_url)return `<img src="${safeAssetUrl(o.image_url)}" alt="Visualización de ${esc(o.name)}" loading="lazy">`;const pieces=(o.garment_ids||[]).map(id=>garmentCache.find(g=>Number(g.id)===Number(id))).filter(g=>thumbOf(g)).slice(0,4);return pieces.length?`<div class="outfit-collage">${pieces.map(g=>`<img src="${safeAssetUrl(thumbOf(g))}" alt="${esc(g.name)}" loading="lazy" decoding="async">`).join('')}</div>`:'<div class="garment-image outfit-placeholder">✦</div>'}
 function renderResult(r){lastResult=r;$('#jobs').innerHTML=`<section class="analysis-summary"><h2>La lectura de tu armario</h2><p>${esc(r.summary)}</p><div class="chips">${(r.palette||[]).map(x=>`<span class="chip">${esc(x)}</span>`).join('')}</div></section><div class="outfit-grid">${(r.outfits||[]).map((o,i)=>`<article class="outfit">${outfitVisual(o)}<div class="outfit-body"><span class="occasion">${esc(o.occasion)}</span><h3>${esc(o.name)}</h3><p>${esc(o.reason)}</p>${o.missing_items?.length?`<p><b>Para completar:</b> ${esc(o.missing_items.join(', '))}</p>`:''}${o.shopping?.length?`<div class="shopping"><small>COMPRAR EN INDITEX</small>${o.shopping.map(s=>`<a href="${safeUrl(s.url)}" target="_blank" rel="noopener noreferrer sponsored"><span>${esc(s.brand)} · ${esc(s.name)}</span><b>↗</b></a>`).join('')}</div>`:''}${o.image_url?'':`<button class="btn btn-soft full draw-outfit" data-outfit="${i}">✦ Generar visual</button>`}<button class="btn btn-soft full share-outfit" data-outfit="${i}">${shares[i]?`↗ Compartido${shares[i].views?` · ${shares[i].views} ${shares[i].views===1?'visita':'visitas'}`:''}`:'↗ Compartir look'}</button></div></article>`).join('')}</div>`;$$('.draw-outfit').forEach(b=>b.onclick=()=>drawOutfit(b));$$('.share-outfit').forEach(b=>b.onclick=()=>openShare(Number(b.dataset.outfit)))}
 // Pictures are drawn one at a time, when someone asks. An analysis used to generate all
 // five up front, so four of them were usually paid for and never looked at.
 async function drawOutfit(button){
  if(resultJobId===null)return;
  const label=button.textContent;
  button.disabled=true;button.textContent='Dibujando…';
  try{
   const d=await api(`/api/jobs/${resultJobId}/outfits/${button.dataset.outfit}/image`,{method:'POST'});
   if(!d.ready)await waitForJob(d.job_id);
   loadJobs()
  }catch(x){button.disabled=false;button.textContent=label;toast(x.message)}
 }
 async function waitForJob(id){
  const deadline=Date.now()+5*60*1000;
  for(let wait=2000;Date.now()<deadline;wait=Math.min(Math.round(wait*1.4),10000)){
   await new Promise(resolve=>setTimeout(resolve,wait));
   const {job}=await api('/api/jobs/'+id);
   if(job.status==='completed')return;
   if(!['queued','processing'].includes(job.status))throw new Error(job.error||'No se pudo generar la imagen.')
  }
  throw new Error('La imagen está tardando demasiado. Recarga la página en un minuto.')
 }
 // Sharing is a modal rather than a straight navigator.share() on the click. The link has
 // to be created first, and an await before share() spends the transient user activation it
 // requires, so Safari and Firefox refuse it. The modal opens on the click, the link arrives
 // while it opens, and its own button is a fresh gesture. It is also the only place "dejar
 // de compartir" can live.
 let sharing=null;
 async function openShare(index){
  const outfit=lastResult?.outfits?.[index];if(!outfit||resultJobId===null)return;
  sharing=null;
  $('#share-title').textContent=outfit.name;
  $('#share-url').value='Creando el enlace…';$('#share-stats').textContent='';
  $('#share-native').hidden=!navigator.share;$('#share-revoke').hidden=true;
  $('.form-error',$('#share-modal')).textContent='';
  openModal('#share-modal');
  try{
   const d=await api('/api/outfits/share',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({job_id:resultJobId,index})});
   sharing={index,...d};shares[index]=d;
   $('#share-url').value=d.url;$('#share-revoke').hidden=false;
   $('#share-stats').textContent=d.views?`${d.views} ${d.views===1?'visita':'visitas'} hasta ahora`:'Nadie lo ha abierto todavía';
   renderResult(lastResult)
  }catch(x){$('#share-url').value='';$('.form-error',$('#share-modal')).textContent=x.message}
 }
 $('#share-copy')?.addEventListener('click',async()=>{
  if(!sharing)return;
  try{await navigator.clipboard.writeText(sharing.url);toast('Enlace copiado')}
  // No clipboard API outside a secure context: leave it selected so the copy is one keystroke.
  catch{$('#share-url').select();toast('Copia el enlace con Ctrl+C')}
 });
 $('#share-native')?.addEventListener('click',async()=>{
  if(!sharing)return;
  try{await navigator.share({title:$('#share-title').textContent,text:`Mira este look: ${$('#share-title').textContent}`,url:sharing.url})}
  catch(x){if(x.name!=='AbortError')toast('No se pudo abrir el menú de compartir')}
 });
 $('#share-revoke')?.addEventListener('click',async()=>{
  if(!sharing||!confirm('¿Dejar de compartir este look? Quien tenga el enlace dejará de verlo.'))return;
  try{await api('/api/outfits/unshare',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:sharing.token})});
   delete shares[sharing.index];sharing=null;$('#share-modal').hidden=true;toast('El enlace ya no funciona');renderResult(lastResult)}
  catch(x){$('.form-error',$('#share-modal')).textContent=x.message}
 });
 // ---- A link to the shop ------------------------------------------------------------
 // The product page already answers eight of the nine boxes below, so pasting its link is the
 // fast way to add a garment and the photo is the way that always works.
 //
 // This was going to be a scan of the tag's QR, and the tag turned out not to carry one yet:
 // what it has is an EAN barcode, the printed reference and the size as text, and the group's
 // own item-level tech is RFID, which no phone camera reads. Nothing here decodes anything
 // until that is settled -- app/ProductLink.php says what is known and where a reader would
 // plug in.
 const linkInput=$('#link-url'),linkLead=$('#link-lead');
 const LINK_LEAD=linkLead.textContent;
 const resetLinkBox=()=>{linkInput.value='';$('#link-sizes').innerHTML='';linkLead.textContent=LINK_LEAD};
 $('#link-fetch').onclick=()=>fillFromLink(linkInput.value);
 linkInput.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();fillFromLink(linkInput.value)}});
 async function fillFromLink(url){
  linkLead.textContent='Buscando esa prenda…';
  try{
   const {garment}=await api('/api/product-link',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({url})});
   for(const [name,value] of Object.entries(garment)){
    if(name==='sizes')continue;
    const field=$(`[name="${name}"]`,garmentForm);
    if(field&&value)field.value=value
   }
   // Every size the product is sold in, so the box offers them instead of asking: the tag
   // prints the size as text and no link carries it.
   $('#link-sizes').innerHTML=(garment.sizes||[]).map(size=>`<option value="${esc(size)}">`).join('');
   suggestSize();
   linkLead.textContent=garment.name
    ?`${garment.brand} · ${garment.name}. Compruébalo y guarda.`
    :`Ficha de ${garment.brand}. No tengo esta prenda en el catálogo, así que el nombre lo pones tú.`
  }catch(x){linkLead.textContent=x.message}
 }

 // ---- The welcome, inside the wardrobe -----------------------------------------------
 // Two complete outfits, and then the stylist reads them. It is painted from the state the
 // server sends -- which outfits exist, what each one is still missing, and how the reading is
 // going -- so what counts as a wearable look is decided in outfit_missing() and nowhere else.
 const SLOT_ASKS={'Partes de arriba':'una parte de arriba','Partes de abajo':'una parte de abajo','Calzado':'el calzado'};
 const READING_GIVE_UP=5*60*1000;
 let readingWatched=null,readingTimer=null;
 const applyOnboarding=answer=>{onboarding=answer.onboarding||null;renderOnboarding()};

 const slotOptions=(outfit,category)=>{
  const taken=new Set((outfit.garments||[]).map(g=>Number(g.id)));
  return (onboarding.wardrobe||[]).filter(g=>g.category===category&&!taken.has(Number(g.id)))
   .map(g=>`<option value="${g.id}">${esc(g.name)}</option>`).join('')
 };
 // One control per empty slot, offering what is already in the wardrobe before offering to add
 // something: the second outfit is usually made of clothes that are in there already.
 const outfitSlot=(outfit,category)=>`<li class="outfit-slot"><select data-slot="${outfit.id}" data-category="${esc(category)}"><option value="">Falta ${esc(SLOT_ASKS[category]||category.toLowerCase())}…</option>${slotOptions(outfit,category)}<option value="new">＋ Añadir una prenda nueva</option></select></li>`;
 const outfitCard=outfit=>`<article class="outfit-builder${outfit.complete?' is-complete':''}"><h3>${esc(outfit.name)}<em>${outfit.complete?'Completo ✓':`Faltan ${outfit.missing.length} ${outfit.missing.length===1?'prenda':'prendas'}`}</em></h3><ul>${(outfit.garments||[]).map(g=>`<li class="outfit-piece"><span>${esc(g.name)}</span><small>${esc(g.category)}</small><button type="button" class="delete-item" data-unlink="${outfit.id}:${g.id}" aria-label="Quitar de este outfit">×</button></li>`).join('')}${outfit.missing.map(category=>outfitSlot(outfit,category)).join('')}</ul><button type="button" class="text-btn" data-add-to="${outfit.id}">＋ Añadir otra prenda (abrigo, accesorio…)</button></article>`;

 function renderOnboarding(){
  const panel=$('#onboarding-panel');
  if(!panel)return;
  if(!onboarding){panel.hidden=true;return}
  panel.hidden=false;
  const reading=onboarding.reading;
  if(reading&&['queued','processing'].includes(reading.status)){
   panel.innerHTML=`<span class="eyebrow">PASO 2 DE 2</span><h2>Estoy mirando tus outfits.</h2><div class="progress"><i style="width:${Math.max(10,reading.progress)}%"></i></div><p class="onboarding-lead">${esc(reading.phase||'Preparando la lectura…')}</p>`;
   watchReading(reading.id);return
  }
  if(reading&&reading.status==='completed'){
   panel.innerHTML=`<span class="eyebrow">PASO 2 DE 2</span><h2>Ya tengo tu perfil de estilo.</h2><p class="onboarding-lead">${esc(reading.result?.summary||'Léelo, corrígelo si no te reconoces y guárdalo.')}</p><button class="btn btn-coral" id="reading-open">Ver y guardar mi perfil</button>`;
   $('#reading-open').onclick=()=>openReading(reading.result||{});
   return
  }
  const failed=reading&&['failed','cancelled'].includes(reading.status);
  panel.innerHTML=`<span class="eyebrow">PASO 1 DE 2</span><h2>Monta dos outfits que te pondrías.</h2><p class="onboarding-lead">Cada uno necesita parte de arriba, parte de abajo y calzado —o un vestido con su calzado—. Cuando los dos estén completos, el estilista los mira y escribe tu perfil de estilo por ti.</p>${failed?`<p class="form-error">${esc(reading.error||'No he podido leer esos outfits.')}</p>`:''}<div class="outfit-builders">${onboarding.outfits.map(outfitCard).join('')}</div>${onboarding.hint?`<p class="onboarding-hint">${esc(onboarding.hint)}</p>`:''}${onboarding.ready?`<button class="btn btn-coral" id="reading-start">${failed?'Volver a leer mi estilo':'Que el estilista lea mi estilo'}</button>`:''}`;
  $$('[data-unlink]',panel).forEach(button=>button.onclick=async()=>{
   const [outfitId,garmentId]=button.dataset.unlink.split(':');
   try{applyOnboarding(await api(`/api/outfits/${outfitId}/garments/${garmentId}`,{method:'DELETE'}))}catch(x){toast(x.message)}
  });
  $$('[data-slot]',panel).forEach(select=>select.onchange=async()=>{
   const chosen=select.value;
   if(chosen==='new'){select.value='';return openGarmentForm(select.dataset.slot,select.dataset.category)}
   if(!chosen)return;
   try{applyOnboarding(await api(`/api/outfits/${select.dataset.slot}/garments`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({garment_id:Number(chosen)})}))}
   catch(x){select.value='';toast(x.message)}
  });
  $$('[data-add-to]',panel).forEach(button=>button.onclick=()=>openGarmentForm(button.dataset.addTo));
  const start=$('#reading-start');
  if(start)start.onclick=()=>askForReading(start);
 }

 async function askForReading(button){
  const label=button.textContent;button.disabled=true;button.textContent='Pidiendo la lectura…';
  try{
   const d=await api('/api/style-reading',{method:'POST'});
   onboarding.reading={id:d.job_id,status:'queued',progress:5,phase:null,result:null,error:null};
   renderOnboarding()
  }catch(x){button.disabled=false;button.textContent=label;toast(x.message)}
 }

 // Same shape as the analysis poll: back off towards ten seconds, wait rather than give up
 // while the tab is in the background, and stop rather than poll a job that will never answer.
 // The bar and the phase are patched in place, because re-rendering the panel from here would
 // call watchReading() again on every tick.
 function watchReading(id){
  if(readingWatched===id)return;
  readingWatched=id;clearTimeout(readingTimer);
  pollReading(id,Date.now(),POLL_MIN)
 }
 async function pollReading(id,startedAt,delay){
  if(readingWatched!==id)return;
  if(document.hidden){readingTimer=setTimeout(()=>pollReading(id,startedAt,delay),POLL_MIN);return}
  if(Date.now()-startedAt>READING_GIVE_UP){readingWatched=null;toast('La lectura está tardando más de lo normal. Recarga la página para ver cómo va.');return}
  try{
   const {job}=await api('/api/jobs/'+id);
   if(['queued','processing'].includes(job.status)){
    const bar=$('#onboarding-panel .progress i'),lead=$('#onboarding-panel .onboarding-lead');
    if(bar)bar.style.width=Math.max(10,job.progress)+'%';
    if(lead&&job.phase)lead.textContent=job.phase;
    readingTimer=setTimeout(()=>pollReading(id,startedAt,Math.min(Math.round(delay*POLL_GROWTH),POLL_MAX)),delay);return
   }
   readingWatched=null;
   onboarding.reading={id:job.id,status:job.status,progress:job.progress,phase:job.phase,result:job.result,error:job.error};
   renderOnboarding();
   if(job.status==='completed')openReading(job.result||{})
  }catch(x){readingWatched=null;toast(x.message)}
 }

 // The verdict, in a form, for the person to correct before any of it is theirs. Whatever the
 // reading did not answer starts from what is already saved, so accepting this cannot wipe a
 // line somebody wrote themselves -- "evitar" above all, which no reading ever fills.
 function openReading(result){
  const form=$('#reading-form');
  $('#reading-summary').textContent=result.summary||'Ya tengo una idea de cómo vistes.';
  $$('input,textarea',form).forEach(field=>{field.value=$(`#profile-form [name="${field.name}"]`)?.value||''});
  for(const [name,value] of Object.entries(result)){
   if(name==='summary')continue;
   const field=$(`[name="${name}"]`,form);
   if(field)field.value=value
  }
  $('.form-error',form).textContent='';
  openModal('#reading-modal')
 }
 $('#reading-form')?.addEventListener('submit',async e=>{
  e.preventDefault();const err=$('.form-error',e.currentTarget);err.textContent='';
  try{
   // finish is what closes the welcome. Nothing else does, so a profile written by hand on day
   // one does not cost somebody the reading they were promised.
   await api('/api/profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...Object.fromEntries(new FormData(e.currentTarget)),finish:true})});
   location.reload()
  }catch(x){err.textContent=x.message}
 });
 // Redoing the reading from "Mi estilo", for an account whose welcome is long behind it. A
 // style changes, and there is no panel to watch in that case, so the button is the progress.
 //
 // It can also be pressed by somebody who has never grouped a look -- every account that
 // existed before outfits did. Refusing with a sentence about two outfits and no way to build
 // them would be a dead end, so the panel that builds them is brought back for the occasion.
 $('#reread-style')?.addEventListener('click',async()=>{
  const button=$('#reread-style'),label=button.textContent;
  button.disabled=true;button.textContent='Leyendo tus outfits…';
  try{
   const d=await api('/api/style-reading',{method:'POST'});
   await waitForJob(d.job_id);
   const {job}=await api('/api/jobs/'+d.job_id);
   openReading(job.result||{})
  }catch(x){
   toast(x.message);
   try{applyOnboarding(await api('/api/outfits'));switchView('wardrobe')}catch{}
  }
  finally{button.disabled=false;button.textContent=label}
 });
 renderOnboarding();
 loadJobs();
}
function esc(v=''){const d=document.createElement('div');d.textContent=String(v);return d.innerHTML}function safeUrl(u=''){try{const x=new URL(u);return x.protocol==='https:'?x.href:'#'}catch{return '#'}}function safeAssetUrl(u=''){try{const x=new URL(u,location.origin);return x.origin===location.origin?x.href:'#'}catch{return '#'}}function thumbOf(g){return g?.thumb_path||g?.image_path||''}function garmentIcon(category=''){return {'Partes de arriba':'♙','Partes de abajo':'♜','Vestidos':'♕','Abrigos':'♖','Calzado':'♢','Accesorios':'○'}[category]||'♧'}

