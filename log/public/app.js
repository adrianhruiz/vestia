'use strict';
if(document.body.dataset.auth==='yes'){
 const $=s=>document.querySelector(s), number=v=>v==null?'—':Number(v).toLocaleString('es-ES');
 const types={wardrobe_analysis:'Análisis del armario',style_reading:'Lectura de estilo',outfit_image:'Imagen de conjunto'};
 const states={queued:'En cola',processing:'En curso',completed:'Completado',failed:'Con error',cancelled:'Cancelado',incomplete:'Incompleto'};
 let jobs=[],detail=null,tab='summary',busy=false,detailSequence=0;
 const date=v=>v?new Date(v).toLocaleString('es-ES',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}):'—';
 const text=(tag,value,className)=>{const el=document.createElement(tag);el.textContent=value;if(className)el.className=className;return el};
 async function api(url){const r=await fetch(url,{cache:'no-store'});if(r.status===401){location.href='/';throw new Error('Sesión caducada');}const d=await r.json();if(!r.ok)throw new Error(d.error||'No se pudieron cargar los registros.');return d;}
 function render(){
  const search=$('#search').value.trim().toLowerCase(),op=$('#operation').value,status=$('#status').value;
  const selected=jobs.filter(j=>(!op||j.type===op)&&(!status||j.status===status)&&(!search||`${j.id} ${types[j.type]||j.type} ${j.trace?.model||''}`.toLowerCase().includes(search)));
  $('#rows').replaceChildren();$('#result-count').textContent=selected.length;
  for(const j of selected){
   const row=document.createElement('tr'),first=document.createElement('td');
   first.append(text('strong',types[j.type]||j.type),text('small',`#${j.id} · ${j.trace?.model||'Modelo no registrado'}`));row.append(first);
   const state=document.createElement('td');state.append(text('span',states[j.status]||j.status,`badge ${Object.hasOwn(states,j.status)?j.status:''}`));row.append(state);
   const trace=document.createElement('td');trace.append(text('span',j.trace?`${j.trace.calls} llamada${Number(j.trace.calls)===1?'':'s'}`:'Histórico parcial',`trace${j.trace?'':' partial'}`));row.append(trace);
   row.append(text('td',number(j.trace?.tokens)),text('td',date(j.created_at)));
   const last=document.createElement('td'),button=text('button','Ver detalle →','row-open');button.setAttribute('aria-label',`Ver detalle del trabajo ${j.id}`);button.onclick=()=>open(j.id);last.append(button);row.append(last);[...row.children].forEach((cell,i)=>cell.dataset.label=['Operación','Estado','Registro','Tokens','Fecha',''][i]);$('#rows').append(row);
  }
  $('#empty').hidden=selected.length>0;
  if(jobs.length){$('#empty h3').textContent='No hay resultados con estos filtros';$('#empty p').textContent='Prueba otra operación, estado o término de búsqueda.';}else{$('#empty h3').textContent='Todo empieza con una petición';$('#empty p').textContent='Cuando uses la IA de Vestia con esta cuenta, verás aquí su recorrido.';}
  $('#metric-jobs').textContent=number(jobs.length);$('#metric-calls').textContent=number(jobs.reduce((n,j)=>n+Number(j.trace?.calls||0),0));
  const measured=jobs.some(j=>j.trace?.tokens!=null);$('#metric-tokens').textContent=measured?number(jobs.reduce((n,j)=>n+Number(j.trace?.tokens||0),0)):'—';
  $('#metric-errors').textContent=number(jobs.filter(j=>j.status==='failed').length);
 }
 async function refresh(){if(busy)return;busy=true;$('#refresh').disabled=true;try{const data=await api('/api.php');jobs=data.jobs;render();$('#list-error').hidden=true;$('#connection').replaceChildren(text('span','','mini-dot'),document.createTextNode(`Actualizado ${new Date().toLocaleTimeString('es-ES',{hour:'2-digit',minute:'2-digit'})}`));}catch(e){$('#list-error').textContent=e.message;$('#list-error').hidden=false;$('#connection').textContent='Sin conexión';}finally{busy=false;$('#refresh').disabled=false;}}
 function renderDetail(){
  if(!detail)return;const j=detail.job,c=detail.calls[Number($('#call-select').value)||0];
  $('#detail-summary').replaceChildren();const grid=text('div','','detail-grid');
  const facts=[['Estado del trabajo',states[j.status]||j.status],['Estado de la llamada',c?(states[c.status]||c.status):'No registrado'],['Duración API',c?.duration_ms!=null?`${(c.duration_ms/1000).toFixed(2)} s`:'No registrada'],['Tokens entrada / salida',c?`${number(c.input_tokens)} / ${number(c.output_tokens)}`:'No registrados'],['Modelo',c?.model||'No registrado'],['Huella de configuración',c?.prompt_hash||'No registrada']];
  for(const [label,value]of facts){const card=text('div','');card.append(text('small',label),text('b',value));grid.append(card);}$('#detail-summary').append(grid);
  let value,note;
  if(tab==='request'){value=c?.request??j.input;note=c?'Petición capturada al enviarse. Las imágenes se sustituyen por sus metadatos y las claves se ocultan.':'Histórico parcial: esta es la entrada del trabajo. No permite reconstruir el prompt ni las prendas exactas que se enviaron.';}
  else if(tab==='response'){value=c?.response??null;note=c?'Respuesta de la API antes de que Vestia la procese. El contenido binario de las imágenes se omite.':'Este trabajo no conserva la respuesta original de la API. Puedes consultar el resultado en Guardado.';}
  else if(tab==='saved'){value=j.result;note='Resultado que conserva Vestia después del procesamiento. Puede diferir de la respuesta original de la API.';}
  else{
   const payload=c?.request?.payload;
   let context=null;if(payload){try{context=typeof payload.input==='string'?JSON.parse(payload.input):payload.input;}catch{context=payload.input;}}
   value={operacion:types[j.type]||j.type,trabajo:j.id,registro:c?'Llamada capturada':'Histórico parcial',estado:states[j.status]||j.status,entrada:context??j.input,instrucciones:payload?.instructions??'No registradas',resumen_guardado:j.result?.summary??null,error:j.error||c?.error||null,request_id:c?.request_id||null};
   note='Resumen construido con los datos registrados, sin otra llamada a la IA. Las pestañas permiten inspeccionar cada etapa.';
  }
  $('#tab-note').textContent=note;$('#detail-content').textContent=value==null?'No hay contenido disponible.':JSON.stringify(value,null,2);
  document.querySelectorAll('[data-tab]').forEach(b=>b.setAttribute('aria-selected',String(b.dataset.tab===tab)));
 }
 async function open(id){const seq=++detailSequence;detail=null;$('#detail-title').textContent='Cargando…';$('#detail-summary').replaceChildren();$('#detail-content').textContent='';$('#tab-note').textContent='';$('#call-select').replaceChildren();$('#copy').disabled=true;$('#detail').showModal();try{const d=await api(`/api.php?job=${encodeURIComponent(id)}`);if(seq!==detailSequence)return;detail=d;tab='summary';$('#detail-title').textContent=types[d.job.type]||d.job.type;$('#detail-meta').textContent=`TRABAJO #${d.job.id} · ${date(d.job.created_at)}`;$('#call-select').replaceChildren();d.calls.forEach((c,i)=>{const o=text('option',`Llamada ${i+1} · ${c.model} · ${states[c.status]||c.status}`);o.value=i;$('#call-select').append(o)});$('#call-select').hidden=$('#call-label').hidden=d.calls.length<2;renderDetail();$('#copy').disabled=false;}catch(e){$('#detail-title').textContent='No se pudo abrir el registro';$('#detail-content').textContent=e.message;}}
 $('#close-detail').onclick=()=>{$('#detail').close();detailSequence++;};$('#detail').addEventListener('cancel',()=>detailSequence++);
 $('#call-select').onchange=renderDetail;
 document.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>{tab=b.dataset.tab;renderDetail()});
 $('#copy').onclick=async()=>{try{await navigator.clipboard.writeText($('#detail-content').textContent);$('#copy').textContent='Copiado';}catch{$('#copy').textContent='Selecciona el texto para copiarlo';}setTimeout(()=>$('#copy').textContent='Copiar contenido',1800)};
 ['search','operation','status'].forEach(id=>$('#'+id).addEventListener('input',render));$('#refresh').onclick=refresh;refresh();setInterval(()=>{if(!document.hidden)refresh()},10000);
}
