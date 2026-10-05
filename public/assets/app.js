const $=(s,c=document)=>c.querySelector(s), $$=(s,c=document)=>[...c.querySelectorAll(s)];
const api=async(url,options={})=>{options.headers={...(options.headers||{}),'X-CSRF-Token':window.APP.csrf};const r=await fetch(url,options);const d=await r.json().catch(()=>({error:'Respuesta no válida'}));if(!r.ok)throw new Error(d.error||'Ha ocurrido un error');return d};
const toast=m=>{const t=$('#toast');t.textContent=m;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2600)};
const openModal=id=>$(id).hidden=false;const closeModal=m=>m.closest('.modal').hidden=true;
$$('[data-close]').forEach(b=>b.onclick=()=>closeModal(b));$$('.modal').forEach(m=>m.onclick=e=>{if(e.target===m)m.hidden=true});
// The eye next to the password shows what you typed: it is the only way to catch a typo in a
// field of ten dots. It reverts to dots every time the form opens, so a revealed password is
// never what the next person to look at the screen finds.
const authPassword=$('#auth-password'),passwordToggle=$('#password-toggle');
const showPassword=show=>{if(!authPassword)return;authPassword.type=show?'text':'password';const label=show?'Ocultar contraseña':'Mostrar contraseña';passwordToggle.setAttribute('aria-pressed',show?'true':'false');passwordToggle.setAttribute('aria-label',label);passwordToggle.title=label};
passwordToggle?.addEventListener('click',()=>{showPassword(authPassword.type==='password');authPassword.focus()});
let authMode='login';function setAuth(mode){authMode=mode;showPassword(false);$('#auth-title').textContent=mode==='login'?'Entrar':'Crea tu armario';$('.register-only').style.display=mode==='register'?'grid':'none';$('#auth-switch').textContent=mode==='login'?'¿No tienes cuenta? Crear armario':'Ya tengo cuenta';openModal('#auth-modal')}
$$('[data-open-auth]').forEach(b=>b.onclick=()=>setAuth(b.dataset.openAuth));
// The shared look's own link, for whoever wants to pass it on without going back to the chat.
// On a phone that link is headed for another chat, so the button opens the share sheet;
// on a desktop people expect a copied link, and the desktop sheet is the odd one out.
$$('[data-copy-link]').forEach(b=>{const sheet=b.dataset.shareLabel&&navigator.share&&matchMedia('(pointer:coarse)').matches;if(sheet)b.textContent=b.dataset.shareLabel;b.onclick=async()=>{if(sheet){try{await navigator.share({title:document.title,url:b.dataset.copyLink})}catch{}return}try{await navigator.clipboard.writeText(b.dataset.copyLink);toast('Enlace copiado')}catch{toast('Copia el enlace de la barra de direcciones')}}});$('#auth-switch')?.addEventListener('click',()=>setAuth(authMode==='login'?'register':'login'));
$('#auth-form')?.addEventListener('submit',async e=>{e.preventDefault();const f=new FormData(e.currentTarget),body=Object.fromEntries(f);const err=$('.form-error',e.currentTarget);err.textContent='';try{const r=await api('/api/'+authMode,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});const next=r.next||window.APP.after_auth;next?location.assign(next):location.reload()}catch(x){err.textContent=x.message}});
// The avatar used to be the logout button itself, so opening your own account and leaving it
// were the same click. It opens a menu now, and only the last item in it ends the session.
const accountToggle=$('#account-toggle'),accountMenu=$('#account-menu');
if(accountToggle){
 const showAccount=open=>{accountMenu.hidden=!open;accountToggle.setAttribute('aria-expanded',open?'true':'false')};
 accountToggle.addEventListener('click',e=>{e.stopPropagation();showAccount(accountMenu.hidden)});
 // Anything chosen in the menu has done its own work by the time this runs, so the menu closes.
 accountMenu.addEventListener('click',()=>showAccount(false));
 document.addEventListener('click',e=>{if(!accountMenu.hidden&&!accountMenu.contains(e.target)&&e.target!==accountToggle)showAccount(false)});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!accountMenu.hidden){showAccount(false);accountToggle.focus()}});
}
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
 $('#add-from-shop').onclick=()=>{openGarmentForm();$('#label-photo').click()};
 const imageInput=$('input[name=image]',garmentForm),preview=$('.upload-preview',garmentForm);let previewUrl='';
 const clearPreview=()=>{if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl='';preview.src='';preview.hidden=true;$('.dropzone b').textContent='＋ Añadir foto'};
 imageInput.addEventListener('change',()=>{if(previewUrl)URL.revokeObjectURL(previewUrl);const file=imageInput.files[0];previewUrl=file?URL.createObjectURL(file):'';preview.src=previewUrl;preview.hidden=!file;$('.dropzone b').textContent=file?'Cambiar foto':'＋ Añadir foto'});
 garmentForm.addEventListener('submit',async e=>{
  e.preventDefault();const err=$('.form-error',e.currentTarget);err.textContent='';
  const saveButton=$('button.full',garmentForm);if(saveButton.disabled)return;saveButton.disabled=true;
  try{
   const d=await api('/api/garments',{method:'POST',body:new FormData(e.currentTarget)});
   if(sizeInput.value.trim())rememberedSizes[categorySelect.value]=sizeInput.value.trim();
   garmentForm.reset();clearPreview();resetLinkBox();$('#garment-modal').hidden=true;
   toast('Prenda añadida a tu armario');await loadGarments();
   // The server did the joining, so the panel is repainted from what it answered rather than
   // from a second request that could disagree with it.
   if(d.onboarding)applyOnboarding(d)
  }catch(x){err.textContent=x.message}
  finally{saveButton.disabled=false}
 });
 const renderGarments=items=>{garmentCache=items;$('#garment-count').textContent=`${items.length} ${items.length===1?'prenda':'prendas'} en tu colección`;$('#garment-grid').innerHTML=items.length?items.map(g=>`<article class="garment"><button class="delete-item" data-delete="${g.id}" aria-label="Eliminar">×</button><div class="garment-image">${thumbOf(g)?`<img src="${safeAssetUrl(thumbOf(g))}" alt="${esc(g.name)}" loading="lazy" decoding="async">`:`<span class="garment-placeholder">${garmentIcon(g.category)}</span>`}</div><div class="garment-body"><span class="garment-brand">${esc(g.brand)}</span><h2 title="${esc(displayName(g.name))}">${esc(displayName(g.name))}</h2><div class="garment-meta">${esc([g.color,g.size,g.reference,g.price_note].filter(Boolean).join(' · '))}</div>${g.source_url?`<a class="product-source" href="${safeUrl(g.source_url)}" target="_blank" rel="noopener noreferrer">Ver producto real ↗</a>`:''}</div></article>`).join(''):'<div class="empty"><b>Tu armario está esperando</b><p>Añade la primera prenda con una foto o su referencia.</p></div>';$$('[data-delete]').forEach(b=>b.onclick=async()=>{if(confirm('¿Eliminar esta prenda?')){await api('/api/garments/'+b.dataset.delete,{method:'DELETE'});loadGarments()}})};
 const loadGarments=async()=>{const q=encodeURIComponent($('#search').value),cat=encodeURIComponent($('#category-filter').value);renderGarments((await api(`/api/garments?q=${q}&category=${cat}`)).items)};
 let searchTimer;$('#search').oninput=()=>{clearTimeout(searchTimer);searchTimer=setTimeout(loadGarments,250)};$('#category-filter').onchange=loadGarments;
 // The unfiltered wardrobe ships inside the page, so the first paint costs no request.
 Array.isArray(window.APP.garments)?renderGarments(window.APP.garments):loadGarments();
 $('#profile-form').addEventListener('submit',async e=>{e.preventDefault();await api('/api/profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.fromEntries(new FormData(e.currentTarget)))});toast('Preferencias guardadas')});
 // ---- The brief: what the stylist is being asked for today -----------------------------
 // It is one object and not three loose controls because it is one question, and because it
 // is what the fingerprint, the history and the result header all have to agree about.
 const SOURCE_LABELS={wardrobe:'Solo mi armario',both:'Mi armario y tiendas',shops:'Looks de tienda'};
 const presets=window.APP.style_presets||{};
 let userStyles=window.APP.styles||[];
 // The choice as the chips express it: a preset slug, 'custom:<id>', or '' for "my day to day".
 let occasion='',source='wardrobe';
 // Resolved once from the last analysis this account ran, which is where the brief in force
 // is actually kept -- there is no new column for it. The occasion comes back as a label and
 // not as a row, so it is matched by name, and a style since deleted lands on the day to day.
 const briefModal=$('#brief-modal'),styleChips=$('#style-chips'),styleNew=$('#style-new'),styleLabel=$('#style-label'),styleError=$('#style-error'),budgetInput=$('#shopping-budget');
 // All three parts of it, and into the controls as well as into the variables: "Pedir un look"
 // repeats the brief in force without ever opening the sheet, and it reads the budget off the
 // box, so a box left empty would quietly drop the budget from the request it is repeating.
 (function restoreBrief(){
  const last=window.APP.brief;if(!last)return;
  source=SOURCE_LABELS[last.source]?last.source:'wardrobe';
  if(budgetInput&&last.budget)budgetInput.value=last.budget;
  if(last.occasion){
   const preset=Object.entries(window.APP.style_presets||{}).find(([,label])=>label===last.occasion);
   const mine=(window.APP.styles||[]).find(st=>st.label===last.occasion);
   occasion=preset?preset[0]:mine?'custom:'+mine.id:''
  }
 })();

 // The label of whatever is selected, which is the half of a brief a person recognises.
 const occasionLabel=()=>{
  if(presets[occasion])return presets[occasion];
  const mine=userStyles.find(st=>'custom:'+st.id===occasion);
  return mine?mine.label:''
 };
 const chipsHtml=brief=>!brief?'':[brief.occasion||'Mi día a día',SOURCE_LABELS[brief.source]||SOURCE_LABELS.wardrobe]
  .map(text=>`<span class="chip">${esc(text)}</span>`).join('')+(brief.budget?`<span class="chip">${esc(brief.budget)}</span>`:'');

 // The sidebar: the last request, so repeating it is one click and changing it is two.
 function renderBriefCard(){
  const label=occasionLabel();
  $('#brief-summary').textContent=label?`Tu última petición: ${label.toLowerCase()}.`:'Te propone looks con la ropa que ya tienes.';
  $('#brief-chips').innerHTML=chipsHtml(currentBrief());
 }
 // The brief as the controls have it right now, which is also exactly what gets posted.
 const currentBrief=()=>({occasion:occasionLabel(),source,budget:source==='wardrobe'?'':(budgetInput?.value.trim()||'')});

 // Radios and not buttons with aria-pressed: single choice is what a radiogroup is, and the
 // arrow keys, the roving focus and the announcement all come for free.
 const styleChip=(value,label,styleId)=>{
  const input=`<label><input type="radio" name="occasion" value="${esc(value)}"${value===occasion?' checked':''}><span>${esc(label)}</span></label>`;
  return styleId===undefined?`<span class="style-chip">${input}</span>`
   :`<span class="style-chip is-mine">${input}<button type="button" data-forget="${styleId}" aria-label="Borrar el estilo ${esc(label)}">×</button></span>`
 };
 function renderStyleChips(){
  styleChips.innerHTML=styleChip('','Mi día a día')
   +Object.entries(presets).map(([slug,label])=>styleChip(slug,label)).join('')
   +userStyles.map(st=>styleChip('custom:'+st.id,st.label,st.id)).join('');
  $$('input[name=occasion]',styleChips).forEach(input=>input.onchange=()=>{occasion=input.value;hideStyleNew()});
  $$('[data-forget]',styleChips).forEach(button=>button.onclick=()=>forgetStyle(Number(button.dataset.forget),button));
  // On a phone the row scrolls sideways, so a chip at the far end of twenty is selected and
  // off screen -- which is where a style somebody has just saved lands.
  $('input[name=occasion]:checked',styleChips)?.closest('.style-chip')?.scrollIntoView({block:'nearest',inline:'nearest'});
 }
 const styleAdd=$('#style-add');
 const showStyleNew=open=>{styleNew.hidden=!open;styleError.textContent='';styleAdd?.setAttribute('aria-expanded',open?'true':'false');if(open)styleLabel.focus();else styleLabel.value=''};
 const hideStyleNew=()=>showStyleNew(false);
 styleAdd?.addEventListener('click',()=>showStyleNew(styleNew.hidden));

 // Saving a style never fails for being a repeat or for naming something the app already has:
 // in both cases the answer says which chip to select, which is what the person was after.
 async function saveStyle(){
  const label=styleLabel.value.trim();
  if(!label){styleError.textContent='Escribe el nombre del estilo.';return}
  const button=$('#style-save');button.disabled=true;
  try{
   const d=await api('/api/styles',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({label})});
   if(d.preset){occasion=d.preset;hideStyleNew();renderStyleChips();toast(`«${presets[d.preset]}» ya estaba: te lo he marcado`);return}
   if(d.existing)toast('Ya tenías ese estilo guardado');
   // Appended rather than put first, because that is where user_styles() will have it on the
   // next load: a style saved and not yet used sorts after every style that has been.
   userStyles=[...userStyles.filter(st=>st.id!==d.style.id),d.style];
   occasion='custom:'+d.style.id;hideStyleNew();renderStyleChips()
  }catch(x){styleError.textContent=x.message}
  finally{button.disabled=false}
 }
 $('#style-save')?.addEventListener('click',saveStyle);
 // Enter in the box saves instead of submitting the brief, which would ask for a look with a
 // style that was never created.
 styleLabel?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();saveStyle()}if(e.key==='Escape'){e.preventDefault();hideStyleNew()}});

 async function forgetStyle(id,button){
  const style=userStyles.find(st=>st.id===id);
  if(!confirm(`¿Borrar el estilo «${style?style.label:''}»? Los looks que ya pediste con él no cambian.`))return;
  button.disabled=true;
  try{
   await api('/api/styles/'+id,{method:'DELETE'});
   userStyles=userStyles.filter(st=>st.id!==id);
   // The chip that was selected has just gone, so the choice falls back to the day to day.
   if(occasion==='custom:'+id)occasion='';
   renderStyleChips()
  }catch(x){button.disabled=false;toast(x.message)}
 }

 // The budget means nothing when nothing is being bought, so it is only there when it does.
 const sourceInputs=$$('input[name=source]');
 const applySource=()=>{source=sourceInputs.find(i=>i.checked)?.value||'wardrobe';$('#budget-step').hidden=source==='wardrobe'};
 sourceInputs.forEach(input=>input.addEventListener('change',applySource));

 // Reopening the sheet shows the brief that is actually in force, whether it came from the
 // last analysis or from the last time this sheet was used.
 function openBrief(){
  sourceInputs.forEach(i=>i.checked=i.value===source);
  applySource();hideStyleNew();renderStyleChips();
  $('#brief-error').textContent='';
  // Said before the brief is composed, not after a 429 has thrown it away.
  loadQuota();
  openModal('#brief-modal')
 }
 $('#brief-open')?.addEventListener('click',openBrief);
 $('#analyze-top').onclick=()=>openBrief();
 $('#brief-form')?.addEventListener('submit',e=>{e.preventDefault();briefModal.hidden=true;startAnalysis()});

 const startAnalysis=async(force=false)=>{
  try{
   const brief={occasion,source,budget:budgetInput?.value||''};
   const d=await api('/api/analyze',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({brief,force})});
   // Nothing has changed since the last run, so the answer is already paid for.
   if(d.reused){
    window.APP.brief=d.brief;renderBriefCard();switchView('outfits');
    const when=d.finished_at?new Date(d.finished_at).toLocaleDateString('es-ES',{day:'numeric',month:'long'}):'la última vez';
    if(confirm(`Ni tu armario, ni tu perfil, ni esta petición han cambiado desde el análisis del ${when}, así que el resultado sería el mismo.\n\n¿Quieres gastar un análisis nuevo de todas formas?`))return startAnalysis(true);
    toast('Te enseño el análisis anterior');return
   }
   window.APP.brief=currentBrief();
   renderBriefCard();activeJob=d.job_id;showMini(3);switchView('outfits');startPolling()
  }catch(x){toast(x.message);$('#brief-error').textContent=x.message}
 };
 // The sidebar button repeats the brief in force. Changing it is what the sheet is for.
 $('#analyze').onclick=()=>startAnalysis();
 renderBriefCard();
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
 let analysesLeft;
 function showQuota(left){
  analysesLeft=left;
  // null means an exempt account; there is no number worth showing there.
  const text=left===null||left===undefined?'':left>0?`${left} ${left===1?'análisis restante':'análisis restantes'} esta hora`:'Sin análisis disponibles hasta dentro de un rato';
  for(const el of [$('#quota'),$('#brief-quota')])if(el)el.textContent=text;
 }
 // Said inside the sheet before the brief is composed, so the limit is never a surprise
 // sprung on somebody who has just finished choosing. One request per session at most:
 // every loadJobs() refreshes the number afterwards.
 async function loadQuota(){
  if(analysesLeft!==undefined){showQuota(analysesLeft);return}
  try{showQuota((await api('/api/jobs')).analyses_left)}catch{}
 }
 const whenText=iso=>{const at=new Date(iso);return isNaN(at)?'':at.toLocaleString('es-ES',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})};
 // What the looks on screen were asked for. Three briefs in an afternoon give three different
 // pages of outfits, and without this the only difference between them is their contents.
 function showResultBrief(brief,when){
  const el=$('#result-brief');if(!el)return;
  el.innerHTML=brief?chipsHtml(brief)+(when?`<span class="brief-when">${esc(whenText(when))}</span>`:''):'';
 }
 // The requests behind the analyses that are no longer on screen. Their looks are not here:
 // /api/jobs carries one result_json on purpose, and ten would be the heaviest response in
 // the app. What it can say for nothing is what each one was for.
 function renderHistory(jobs){
  const panel=$('#job-history');if(!panel)return;
  const past=jobs.filter(j=>j.status==='completed').slice(1);
  if(!past.length){panel.hidden=true;panel.innerHTML='';return}
  panel.hidden=false;
  panel.innerHTML=`<h2>Tus peticiones anteriores</h2><ul>${past.map(j=>`<li>${chipsHtml(j.brief)}<small>${esc(whenText(j.finished_at||j.created_at))}</small></li>`).join('')}</ul>`;
 }
 async function loadJobs(){
  const d=await api('/api/jobs'),{jobs,result}=d;showQuota(d.analyses_left);renderHistory(jobs);
  const running=jobs.find(j=>['queued','processing'].includes(j.status));
  if(running){activeJob=running.id;showResultBrief(running.brief,null);renderRunning(running);startPolling();return}
  if(result){
   resultJobId=d.result_job_id;shares=d.shares||{};
   const shown=jobs.find(j=>Number(j.id)===Number(d.result_job_id));
   showResultBrief(shown?.brief,shown?.finished_at);renderResult(result);return
  }
  const last=jobs[0];showResultBrief(last?.brief,null);
  $('#jobs').innerHTML=last&&last.status==='failed'?`<div class="job-running"><b>Último análisis fallido</b><p>${esc(last.error)}</p></div>`:last&&last.status==='cancelled'?'<div class="job-running"><b>Análisis cancelado</b><p>Puedes lanzar uno nuevo cuando quieras.</p></div>':'<div class="empty"><b>Aún no hay looks</b><p>Dile al estilista para qué quieres vestirte y te monta tus primeras combinaciones.</p></div>'
 }
 const outfitPieces=o=>(o.garment_ids||[]).map(id=>garmentCache.find(g=>Number(g.id)===Number(id))).filter(g=>thumbOf(g)).slice(0,4);
 const shoppingLinks=o=>(o.shopping||[]).map(s=>`<a href="${safeUrl(s.url)}" target="_blank" rel="noopener noreferrer sponsored"><span>${esc(s.brand)} · ${esc(s.name)}</span><b>↗</b></a>`).join('');
 // A look the shops built has no photographs of its own, because none of it is in the
 // wardrobe yet. Its pieces are the links, so the links are the head of the card -- under the
 // ✦ placeholder they read as an afterthought to a picture that was never going to arrive.
 function outfitVisual(o,pieces){
  if(pieces.length)return `<div class="outfit-collage">${pieces.map(g=>`<img src="${safeAssetUrl(thumbOf(g))}" alt="${esc(g.name)}" loading="lazy" decoding="async">`).join('')}</div>`;
  if(o.shopping?.length)return `<div class="outfit-shoplist"><small>COMPRAR PARA ESTE LOOK</small>${shoppingLinks(o)}</div>`;
  return '<div class="garment-image outfit-placeholder">✦</div>'
 }
 function renderResult(r){
  lastResult=r;
  const cards=(r.outfits||[]).map((o,i)=>{
   const pieces=outfitPieces(o);
   // Already shown as the head of the card: printing them twice is the same list twice.
   const shopsAreTheLook=!pieces.length&&o.shopping?.length;
   return `<article class="outfit">${outfitVisual(o,pieces)}<div class="outfit-body"><span class="occasion">${esc(o.occasion)}</span><h3>${esc(o.name)}</h3><p>${esc(plainText(o.reason))}</p>${o.missing_items?.length?`<p><b>Para completar:</b> ${esc(o.missing_items.join(', '))}</p>`:''}${o.shopping?.length&&!shopsAreTheLook?`<div class="shopping"><small>COMPRAR EN INDITEX</small>${shoppingLinks(o)}</div>`:''}<button class="btn btn-soft full share-outfit" data-outfit="${i}">${shares[i]?`↗ Compartido${shares[i].views?` · ${shares[i].views} ${shares[i].views===1?'visita':'visitas'}`:''}`:'↗ Compartir look'}</button></div></article>`
  }).join('');
  $('#jobs').innerHTML=`<section class="analysis-summary"><h2>La lectura de tu armario</h2><p>${esc(r.summary)}</p><div class="chips">${(r.palette||[]).map(x=>`<span class="chip">${esc(x)}</span>`).join('')}</div></section><div class="outfit-grid">${cards}</div>`;
  $$('.share-outfit').forEach(b=>b.onclick=()=>openShare(Number(b.dataset.outfit)))
 }
 // Waiting on the one job the person is standing in front of: the style reading they asked
 // for from "Mi estilo".
 async function waitForJob(id){
  const deadline=Date.now()+5*60*1000;
  for(let wait=2000;Date.now()<deadline;wait=Math.min(Math.round(wait*1.4),10000)){
   await new Promise(resolve=>setTimeout(resolve,wait));
   const {job}=await api('/api/jobs/'+id);
   if(job.status==='completed')return;
   if(!['queued','processing'].includes(job.status))throw new Error(job.error||'No se pudo completar la lectura.')
  }
  throw new Error('La lectura está tardando demasiado. Recarga la página en un minuto.')
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
 // ---- Reference lookup and local label recognition ----------------------------------
 let labelRevision=0,labelRequest=null;
 const labelStatus=$('#label-status'),labelResults=$('#label-results');
 const labelBusy=busy=>{$('#label-search').disabled=busy;$('#label-photo').disabled=busy;$('#label-search').textContent=busy?'Procesando…':'Buscar referencia'};
 function clearLabelSelection(){
  $('[name=catalog_token]',garmentForm).value='';
  $('[name=use_catalog_image]',garmentForm).checked=false;
  $('#label-use-photo').hidden=true;
 }
 function cancelLabelRequest(){labelRevision++;labelRequest?.abort();labelRequest=null;labelBusy(false)}
 function resetLabelBox(){
  cancelLabelRequest();clearLabelSelection();labelResults.replaceChildren();labelStatus.textContent='';
  $('#label-photo').value='';$('#label-brand').value='';$('#label-reference').value='';$('#label-size').value='';$('#label-references').replaceChildren();
 }
 // Closing or reopening the form invalidates in-flight responses from the previous photo.
 new MutationObserver(()=>{if($('#garment-modal').hidden)cancelLabelRequest()}).observe($('#garment-modal'),{attributes:true,attributeFilter:['hidden']});
 for(const selector of ['#label-brand','#label-reference'])$(selector).addEventListener('input',()=>{
  cancelLabelRequest();clearLabelSelection();labelResults.replaceChildren();labelStatus.textContent='';
 });
 for(const name of ['brand','reference'])$(`[name="${name}"]`,garmentForm).addEventListener('input',()=>{
  if($('[name=catalog_token]',garmentForm).value){clearLabelSelection();$('[name=source_url]',garmentForm).value='';$('[name=price_note]',garmentForm).value='';labelStatus.textContent='Has cambiado la identificación. Busca de nuevo si quieres usar la foto oficial.'}
 });
 $('#label-photo').addEventListener('change',async()=>{
  const file=$('#label-photo').files[0];if(!file)return;
  cancelLabelRequest();clearLabelSelection();labelResults.replaceChildren();
  if(file.size>8*1024*1024){labelStatus.textContent='La foto supera 8 MB. Elige una más pequeña.';return}
  const revision=labelRevision;labelRequest=new AbortController();labelBusy(true);
  labelStatus.textContent='Leyendo la etiqueta…';
  try{
   const data=new FormData();data.append('label',file);
   const {label}=await api('/api/label-scan',{method:'POST',body:data,signal:labelRequest.signal});
   if(revision!==labelRevision)return;
   $('#label-brand').value=label.brand;$('#label-reference').value=label.reference;$('#label-size').value=label.size;
   $('#label-references').innerHTML=label.references.map(ref=>`<option value="${esc(ref)}">`).join('');
   labelStatus.textContent=label.reference?'Referencia leída. Revisa marca, referencia y talla; después pulsa Buscar referencia.':label.references.length?'He leído varias referencias. Elige una en el campo Referencia antes de buscar.':'No he podido distinguir una referencia. Acerca la cámara a los números o escríbelos aquí.';
   $('#label-reference').focus();
  }catch(x){if(x.name!=='AbortError'&&revision===labelRevision)labelStatus.textContent=x.message}
  finally{if(revision===labelRevision){labelBusy(false);$('#label-photo').value=''}}
 });
 $('#label-search').onclick=async()=>{
  cancelLabelRequest();clearLabelSelection();labelResults.replaceChildren();
  const revision=labelRevision;labelRequest=new AbortController();labelBusy(true);labelStatus.textContent='Buscando en el catálogo…';
  try{
   const result=await api('/api/label-lookup',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({brand:$('#label-brand').value,reference:$('#label-reference').value}),signal:labelRequest.signal});
   if(revision!==labelRevision)return;
   labelStatus.textContent=result.items.length?`Elige tu prenda y comprueba el color. Los datos corresponden a la captura del catálogo.${result.truncated?' Hay más coincidencias: indica marca y referencia completa para concretar.':''}`:'No aparece en el catálogo guardado. Comprueba la referencia, prueba con el enlace de la tienda o completa los datos manualmente.';
   labelResults.innerHTML=result.items.map((g,i)=>`<button type="button" class="label-match" data-match="${i}" aria-pressed="false">${g.image_url?`<img src="${esc(g.image_url)}" alt="${esc(g.name)}" loading="lazy">`:'<span class="label-no-photo">Sin foto</span>'}<span><b>${esc(g.brand)} · ${esc(g.name)}</b><small>${esc(g.reference)} · ${esc(g.color||'Color no informado')}</small><small>${esc(g.price_note)} · Captura ${esc(new Date(g.captured_at).toLocaleDateString('es-ES'))}</small></span></button>`).join('');
   $$('img',labelResults).forEach(img=>img.addEventListener('error',()=>{img.hidden=true}));
   $$('[data-match]',labelResults).forEach(button=>button.onclick=()=>{
    const g=result.items[Number(button.dataset.match)];
    for(const name of ['name','brand','reference','category','color','source_url','price_note','notes'])$(`[name="${name}"]`,garmentForm).value=g[name]||'';
    sizeInput.value=$('#label-size').value.trim();
    $('#link-sizes').innerHTML=g.sizes.map(size=>`<option value="${esc(size)}">`).join('');
    $('[name=catalog_token]',garmentForm).value=g.token;
    $('[name=use_catalog_image]',garmentForm).checked=!!g.image_url;
    $('#label-use-photo').hidden=!g.image_url;
    $$('[data-match]',labelResults).forEach(b=>b.setAttribute('aria-pressed',String(b===button)));
    labelStatus.textContent='Datos preparados. Revisa la talla y los demás campos antes de guardar. Puedes sustituir la foto oficial por una tuya.';
    $('[name=name]',garmentForm).focus();
   });
  }catch(x){if(x.name!=='AbortError'&&revision===labelRevision)labelStatus.textContent=x.message}
  finally{if(revision===labelRevision)labelBusy(false)}
 };
 $('#label-reference').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();if(!$('#label-search').disabled)$('#label-search').click()}});

 // ---- A link to the shop ------------------------------------------------------------
 const linkInput=$('#link-url'),linkLead=$('#link-lead');
 const LINK_LEAD=linkLead.textContent;
 const resetLinkBox=()=>{linkInput.value='';$('#link-sizes').innerHTML='';linkLead.textContent=LINK_LEAD;resetLabelBox()};
 $('#link-fetch').onclick=()=>fillFromLink(linkInput.value);
 linkInput.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();fillFromLink(linkInput.value)}});
 async function fillFromLink(url){
  cancelLabelRequest();clearLabelSelection();labelResults.replaceChildren();
  const revision=labelRevision;linkLead.textContent='Buscando esa prenda…';
  try{
   const {garment}=await api('/api/product-link',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({url})});
   if(revision!==labelRevision)return;
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
  }catch(x){if(revision===labelRevision)linkLead.textContent=x.message}
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
 const outfitCard=outfit=>`<article class="outfit-builder${outfit.complete?' is-complete':''}"><h3>${esc(outfit.name)}<em>${outfit.complete?'Completo ✓':`Faltan ${outfit.missing.length} ${outfit.missing.length===1?'prenda':'prendas'}`}</em></h3><ul>${(outfit.garments||[]).map(g=>`<li class="outfit-piece"><span>${esc(displayName(g.name))}</span><small>${esc(g.category)}</small><button type="button" class="delete-item" data-unlink="${outfit.id}:${g.id}" aria-label="Quitar de este outfit">×</button></li>`).join('')}${outfit.missing.map(category=>outfitSlot(outfit,category)).join('')}</ul><button type="button" class="text-btn" data-add-to="${outfit.id}">＋ Añadir otra prenda (abrigo, accesorio…)</button></article>`;

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
// Catalogue names arrive in the shop's own capitals ("DEPORTIVO TECNICO"): a name in capitals
// becomes a sentence, a word in capitals inside one becomes a word, short acronyms stay.
// Same rule as share_display_name() in app/Share.php.
function displayName(v=''){const name=String(v),letters=name.replace(/\P{L}/gu,'');if(letters.length>3&&letters===letters.toUpperCase()){const lower=name.toLowerCase();return lower.charAt(0).toUpperCase()+lower.slice(1)}return name.replace(/(?<!\p{L})\p{Lu}{4,}(?!\p{L})/gu,w=>w.charAt(0)+w.slice(1).toLowerCase())}
// The stylist cites its web sources as markdown links; a citation in brackets goes whole and any
// other link keeps its words. Same rule as share_plain_text() in app/Share.php.
function plainText(v=''){return String(v).replace(/\s*\(\[[^\]]*\]\([^)\s]*\)\)/g,'').replace(/\[([^\]]*)\]\([^)\s]*\)/g,'$1').replace(/ {2,}/g,' ').trim()}function esc(v=''){const d=document.createElement('div');d.textContent=String(v);return d.innerHTML}function safeUrl(u=''){try{const x=new URL(u);return x.protocol==='https:'?x.href:'#'}catch{return '#'}}function safeAssetUrl(u=''){try{const x=new URL(u,location.origin);return x.origin===location.origin?x.href:'#'}catch{return '#'}}function thumbOf(g){return g?.thumb_path||g?.image_path||''}function garmentIcon(category=''){return {'Partes de arriba':'♙','Partes de abajo':'♜','Vestidos':'♕','Abrigos':'♖','Calzado':'♢','Accesorios':'○'}[category]||'♧'}

