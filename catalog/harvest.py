#!/usr/bin/env python3
"""Resumable monthly ES catalog collector using the stores' public catalog responses."""
import argparse, concurrent.futures as futures, datetime as dt, fcntl, gzip, hashlib, io, json, logging, os, pathlib, re, shutil, sqlite3, threading, time, urllib.parse
from curl_cffi import requests
from PIL import Image
import catalog
ROOT=pathlib.Path('/var/lib/inditex-catalog')
BRANDS={'zara':('www.zara.com',None),'pullandbear':('www.pullandbear.com',24009400),'massimodutti':('www.massimodutti.com',34009450),'bershka':('www.bershka.com',44009500),'stradivarius':('www.stradivarius.com',54009550),'oysho':('www.oysho.com',64009600),'zarahome':('www.zarahome.com',84009900),'lefties':('www.lefties.com',94009000)}
MEDIA_HOSTS={'static.zara.net','static.zara.com','static.pullandbear.net','static.bershka.net','static.massimodutti.net','static.e-stradivarius.net','static.oysho.net','static.zarahome.com','static.zarahome.net','static.lefties.com','static.inditex.com'}
EXTRA_SCHEMA="""
CREATE TABLE IF NOT EXISTS crawl_tasks(run_id INTEGER NOT NULL,brand TEXT NOT NULL,kind TEXT NOT NULL,key TEXT NOT NULL,payload TEXT,status TEXT NOT NULL DEFAULT 'pending',error TEXT,PRIMARY KEY(run_id,brand,kind,key));
CREATE INDEX IF NOT EXISTS crawl_pending ON crawl_tasks(run_id,brand,kind,status);
CREATE TABLE IF NOT EXISTS catalog_categories(run_id INTEGER,brand TEXT,category_id TEXT,name TEXT,raw_json TEXT,PRIMARY KEY(run_id,brand,category_id));
CREATE INDEX IF NOT EXISTS images_pending_download ON images(status,position,id);
CREATE INDEX IF NOT EXISTS images_source_status ON images(source_url,status);
CREATE INDEX IF NOT EXISTS images_snapshot_status ON images(snapshot_id,status,position);
CREATE TABLE IF NOT EXISTS media_cache(source_url TEXT PRIMARY KEY,local_path TEXT,sha256 TEXT,width INTEGER,height INTEGER);
"""
class Blocked(Exception):pass
class Missing(Exception):pass
class Limit(Exception):pass
def now():return dt.datetime.now(dt.timezone.utc).isoformat()
def js(v):return json.dumps(v,ensure_ascii=False,separators=(',',':'))
WRITE_LOCK=threading.RLock()
class StoreConnection(sqlite3.Connection):
 def execute(self,sql,parameters=()):
  mutating=sql.lstrip().split(None,1)[0].upper() in ('INSERT','UPDATE','DELETE','REPLACE','CREATE','ALTER','DROP','BEGIN')
  if mutating and not getattr(self,'owns_write_lock',False):
   WRITE_LOCK.acquire();self.owns_write_lock=True
  try:return super().execute(sql,parameters)
  except Exception:
   self.rollback();raise
 def commit(self):
  try:return super().commit()
  except Exception:
   super().rollback();raise
  finally:
   if getattr(self,'owns_write_lock',False):self.owns_write_lock=False;WRITE_LOCK.release()
 def rollback(self):
  try:return super().rollback()
  finally:
   if getattr(self,'owns_write_lock',False):self.owns_write_lock=False;WRITE_LOCK.release()
 def executescript(self,sql):
  self.commit()
  with WRITE_LOCK:return super().executescript(sql)
 def close(self):
  self.rollback();return super().close()
def connect():
 c=sqlite3.connect(ROOT/'catalog.sqlite3',timeout=120,factory=StoreConnection);c.row_factory=sqlite3.Row;c.execute('PRAGMA foreign_keys=ON');c.execute('PRAGMA busy_timeout=120000');return c
class Client:
 def __init__(self):self.session=requests.Session(impersonate='chrome');self.last={};self.session.headers.update({'Accept-Language':'es-ES,es;q=0.9'})
 def get(self,url):
  host=urllib.parse.urlsplit(url).hostname
  for attempt in range(3):
   time.sleep(max(0,1.5-(time.monotonic()-self.last.get(host,0))));self.last[host]=time.monotonic()
   try:r=self.session.get(url,timeout=45)
   except Exception:
    if attempt==2:raise
    time.sleep(2**attempt);continue
   if r.status_code in (401,403,429):raise Blocked('HTTP '+str(r.status_code)+' '+url)
   if r.status_code in (404,410):raise Missing('HTTP '+str(r.status_code)+' '+url)
   if r.status_code>=500:
    if attempt<2:time.sleep(3*(attempt+1));continue
    r.raise_for_status()
   r.raise_for_status()
   if len(r.content)>40_000_000:raise Limit('Response exceeded 40 MB')
   if b'triggerInterstitialChallenge' in r.content[:10000] or b'bm-verify' in r.content[:4000]:raise Blocked('Verification page: '+url)
   return r
 def json(self,url):
  r=self.get(url)
  if 'json' not in r.headers.get('Content-Type',''):raise ValueError('Expected JSON: '+url)
  return r.json()
def queue(c,run,b,kind,key,payload):
 c.execute('INSERT OR IGNORE INTO crawl_tasks(run_id,brand,kind,key,payload) VALUES(?,?,?,?,?)',(run,b,kind,str(key),js(payload)))
def event(c,run,b,url,kind,msg):
 c.execute('INSERT INTO events(run_id,brand,created_at,url,kind,message) VALUES(?,?,?,?,?,?)',(run,b,now(),url,kind,str(msg)[:2000]));c.commit()
def walk_categories(items):
 for x in items:
  if not isinstance(x,dict):continue
  if x.get('id') is not None:yield x
  yield from walk_categories(x.get('subcategories') or [])
def record_categories(c,run,b,items):
 ids=set()
 for x in walk_categories(items):
  key=str(x['id'])
  if key in ids:continue
  ids.add(key);c.execute('INSERT OR REPLACE INTO catalog_categories VALUES(?,?,?,?,?)',(run,b,key,x.get('name',''),js(x)))
  if b=='zara' and x.get('layout')!='products-category-view':continue
  if b!='zara' and (x.get('name','').strip() in ('','-') or x.get('type')=='1'):continue
  queue(c,run,b,'category',key,{})
 c.commit()
def objects(p):
 yield p
 for child in p.get('bundleProductSummaries') or []:
  if isinstance(child,dict):yield from objects(child)
def normalize(b,p,home):
 obs=list(objects(p));details=[o.get('detail') or {} for o in obs]
 colors=[];sizes=[];variants=[];prices=[];media=[];media_seen=set();compositions=[]
 for o,d in zip(obs,details):
  if isinstance(o.get('price'),(int,float)):prices.append(o['price']/100)
  if d.get('composition') or d.get('care') or d.get('compositionDetail'):
   compositions.append({'reference':d.get('reference'),'composition':d.get('composition'),'by_zone':d.get('compositionByZone'),'detail':d.get('compositionDetail'),'care':d.get('care'),'country':d.get('country')})
  for color in d.get('colors') or []:
   if not isinstance(color,dict):continue
   if color.get('name') and color['name'] not in colors:colors.append(color['name'])
   if isinstance(color.get('price'),(int,float)):prices.append(color['price']/100)
   for size in color.get('sizes') or []:
    if not isinstance(size,dict):continue
    label=size.get('name')
    if label and label not in sizes:sizes.append(label)
    amount=size.get('price')
    if amount is not None:
     try:prices.append(float(amount)/100)
     except (TypeError,ValueError):pass
    variants.append({'color':color['name'],'color_id':color.get('id'),'size':label,'sku':size.get('sku') or size.get('id'),'price_minor':amount,'currency':'EUR','available':size.get('isBuyable',size.get('availability')),'raw':size})
  def collect(v):
   if isinstance(v,dict):
    extra=v.get('extraInfo') or {}
    u=extra.get('deliveryUrl') or extra.get('oDeliveryUrl') or v.get('url')
    if isinstance(u,str) and (v.get('type')=='image' or extra.get('deliveryUrl') or extra.get('oDeliveryUrl')):
     u=u.replace('{width}','1600')
     parts=urllib.parse.urlsplit(u);host=parts.hostname or ''
     if host in MEDIA_HOSTS and re.search(r'\.(jpg|jpeg|png|webp|avif)(?:\?|$)',u,re.I):
      q=dict(urllib.parse.parse_qsl(parts.query));q['w']='1600';u=urllib.parse.urlunsplit((parts.scheme,parts.netloc,parts.path,urllib.parse.urlencode(q),''))
      if u not in media_seen:media_seen.add(u);media.append({'url':u,'kind':v.get('kind') or extra.get('productionType'),'name':extra.get('originalName') or v.get('name')})
    for key,x in v.items():
     if key not in ('extraInfo','relatedProducts','relatedElements','relations'):collect(x)
   elif isinstance(v,list):
    for x in v:collect(x)
  collect(d.get('xmedia') or [])
  for color in d.get('colors') or []:
   collect(color.get('xmedia') or []);collect(color.get('pdpMedia') or {})
  collect(o.get('xmedia') or [])
 for co in p.get('availableColors') or []:
  if co.get('colorName') and co['colorName'] not in colors:colors.append(co['colorName'])
 detail=details[0];ref=detail.get('reference') or p.get('reference') or str(p['id'])
 if b=='zara':
  seo=p.get('seo') or {};url=home+(seo.get('keyword','producto'))+'-p'+str(seo.get('seoProductId') or ref.split('-')[0])+'.html'
 else:
  slug=p.get('productUrl') or next((o.get('productUrl') for o in obs if o.get('productUrl')),None)
  url=urllib.parse.urljoin(home,slug) if slug else home
 buyable=[v['available'] for v in variants if v['available'] is not None]
 availability=p.get('availability') or ('in_stock' if any(v is True or v=='in_stock' for v in buyable) else 'out_of_stock' if buyable else '')
 desc=next((d.get('longDescription') or d.get('description') for d in details if d.get('longDescription') or d.get('description')),p.get('description') or '')
 tags=p.get('tagTypes') or p.get('tags') or p.get('productTag') or []
 tags=[t.get('displayName') or t.get('name') or t.get('value') or t.get('type') if isinstance(t,dict) else t for t in tags]
 media.sort(key=lambda m:0 if re.search(r'-f1(?:/|\.)',m['url'],re.I) else 1 if m.get('kind')=='full' else 2)
 norm={'url':url,'reference':ref,'description':desc,'category':p.get('familyName') or detail.get('familyInfo',{}).get('familyName') or '', 'colors':colors,'sizes':sizes,'tags':[t for t in tags if t],'price':min(prices) if prices else None,'currency':'EUR','availability':availability,'composition':compositions,'variants':variants,'media':media}
 p['_normalized']=norm;return norm
def save(c,run,b,p,home,source):
 if not p.get('name'):
  p['name']=next((o.get('name') for o in objects(p) if o.get('name')),None)
 if not p.get('id') or not p.get('name'):return False
 norm=normalize(b,p,home);key=str(p.get('reference') or p['id']) if b=='zara' else str(p['id']);stamp=now()
 c.execute('INSERT INTO products(brand,market,source_key,url,first_seen,last_seen) VALUES(?,?,?,?,?,?) ON CONFLICT(brand,market,source_key) DO UPDATE SET last_seen=excluded.last_seen,url=excluded.url',(b,'ES',key,norm['url'],stamp,stamp))
 pid=c.execute('SELECT id FROM products WHERE brand=? AND market=? AND source_key=?',(b,'ES',key)).fetchone()[0]
 row=c.execute('INSERT INTO snapshots(product_id,run_id,captured_at,name,description,sku,category_json,labels_json,colors_json,sizes_json,offers_json,composition_json,raw_json,source_html) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(product_id,run_id) DO NOTHING RETURNING id',(pid,run,stamp,p['name'],norm['description'],norm['reference'],js(norm['category']),js(norm['tags']),js(norm['colors']),js(norm['sizes']),js({'price':norm['price'],'currency':'EUR','availability':norm['availability']}),js(norm['composition']),js(p),source)).fetchone()
 if row:
  sid=row[0]
  for v in norm['variants']:c.execute('INSERT INTO variants(snapshot_id,source_key,color_json,size_json,offers_json,raw_json) VALUES(?,?,?,?,?,?)',(sid,str(v['sku']),js(v['color']),js(v['size']),js({k:v[k] for k in ('price_minor','currency','available')}),js(v['raw'])))
  for i,m in enumerate(norm['media']):
   cached=c.execute('SELECT * FROM media_cache WHERE source_url=?',(m['url'],)).fetchone()
   if cached and (ROOT/cached['local_path']).is_file():
    c.execute('INSERT OR IGNORE INTO images(snapshot_id,source_url,position,local_path,sha256,width,height,status,detail) VALUES(?,?,?,?,?,?,?,?,?)',(sid,m['url'],i,cached['local_path'],cached['sha256'],cached['width'],cached['height'],'downloaded',js(m)))
   else:c.execute("INSERT OR IGNORE INTO images(snapshot_id,source_url,position,status,detail) VALUES(?,?,?,'pending',?)",(sid,m['url'],i,js(m)))
 c.commit();return bool(row)
def stats(c,run,b,status=None,detail=None):
 counts={r['status']:r['n'] for r in c.execute("SELECT status,COUNT(*) n FROM crawl_tasks WHERE run_id=? AND brand=? AND kind='product' GROUP BY status",(run,b))}
 n=c.execute('SELECT COUNT(*) FROM snapshots s JOIN products p ON p.id=s.product_id WHERE run_id=? AND brand=?',(run,b)).fetchone()[0]
 errors=c.execute("SELECT COUNT(*) FROM crawl_tasks WHERE run_id=? AND brand=? AND status='failed'",(run,b)).fetchone()[0]
 c.execute('UPDATE brand_runs SET discovered=?,processed=?,products=?,errors=? WHERE run_id=? AND brand=?',(sum(counts.values()),counts.get('done',0),n,errors,run,b))
 if status:c.execute('UPDATE brand_runs SET status=?,detail=? WHERE run_id=? AND brand=?',(status,detail,run,b))
 c.commit()
def collect_brand(run,b):
 c=connect();net=Client();host,store=BRANDS[b];home='https://'+host+('/es/es/' if b=='zara' else '/es/');status='partial'
 c.execute("INSERT INTO brand_runs(run_id,brand,market,status,detail) SELECT ?,?,'ES','running','Discovering public catalog' WHERE NOT EXISTS(SELECT 1 FROM brand_runs WHERE run_id=? AND brand=?)",(run,b,run,b));c.execute("UPDATE brand_runs SET status='running' WHERE run_id=? AND brand=?",(run,b));c.commit()
 try:
  catalog_id=None
  if b=='zara':caturl=home+'categories?ajax=true'
  else:
   config=net.json(f'https://{host}/itxrest/2/catalog/store/{store}?languageId=-5')
   catalog_id=next(x['id'] for x in config['catalogs'] if int(x.get('type',0))==1)
   caturl=f'https://{host}/itxrest/2/catalog/store/{store}/{catalog_id}/category?languageId=-5'
  if not c.execute("SELECT 1 FROM crawl_tasks WHERE run_id=? AND brand=? AND kind='category' LIMIT 1",(run,b)).fetchone():
   data=net.json(caturl);record_categories(c,run,b,data['categories'])
  logging.info('%s categories discovered',b)
  def process_products():
   while True:
    tasks=c.execute("SELECT * FROM crawl_tasks WHERE run_id=? AND brand=? AND kind='product' AND status='pending' LIMIT 10",(run,b)).fetchall()
    if not tasks:return
    if b=='zara':
     for task in tasks:
      p=json.loads(task['payload'])
      try:
       if p.get('id'):p['extra_detail']=net.json(home+f"product/{p['id']}/extra-detail?ajax=true")
      except Missing:pass
      save(c,run,b,p,home,caturl)
      c.execute("UPDATE crawl_tasks SET status='done' WHERE run_id=? AND brand=? AND kind='product' AND key=?",(run,b,task['key']));c.commit()
    else:
     keys=[x['key'] for x in tasks];u=f'https://{host}/itxrest/3/catalog/store/{store}/{catalog_id}/productsArray?languageId=-5&appId=1&productIds='+','.join(keys)
     try:data=net.json(u)
     except Missing:
      data={'products':[]}
     vals=data.get('products',[]);vals=list(vals.values()) if isinstance(vals,dict) else vals;got=set()
     for p in vals:
      if not isinstance(p,dict) or not p.get('id'):continue
      if not p.get('name'):p['name']=next((o.get('name') for o in objects(p) if o.get('name')),None)
      if not p.get('name'):continue
      save(c,run,b,p,home,u);got.add(str(p.get('id')))
     missing={keys[i] for i,v in enumerate(vals) if i<len(keys) and isinstance(v,dict) and v.get('key')=='_ERR_PRODUCT_NOT_FOUND'} if len(vals)==len(keys) else set()
     for key in keys:
      c.execute("UPDATE crawl_tasks SET status=?,error=? WHERE run_id=? AND brand=? AND kind='product' AND key=?",('done' if key in got else 'unavailable' if key in missing else 'failed',None if key in got else 'Store reports item not found' if key in missing else 'Requested ID absent from product response',run,b,key))
     c.commit()
    stats(c,run,b)
  process_products()
  for task in c.execute("SELECT * FROM crawl_tasks WHERE run_id=? AND brand=? AND kind='category' AND status='pending'",(run,b)).fetchall():
   cid=task['key']
   if shutil.disk_usage(ROOT).free<15*1024**3:raise Limit('Less than 15 GiB disk space available')
   try:
    u=home+f'category/{cid}/products?ajax=true' if b=='zara' else f'https://{host}/itxrest/3/catalog/store/{store}/{catalog_id}/category/{cid}/product?languageId=-5&appId=1'
    data=net.json(u)
    if b=='zara':
     def walk(v):
      if isinstance(v,dict):
       if v.get('type')=='Product' and v.get('id') and v.get('name'):queue(c,run,b,'product',v['id'],v);return
       for x in v.values():walk(x)
      elif isinstance(v,list):
       for x in v:walk(x)
     walk(data.get('productGroups',[]))
    else:
     ids=data.get('productIds') or data.get('sortedProductIds') or []
     for pid in ids:
      if isinstance(pid,(int,str)):queue(c,run,b,'product',pid,{})
    c.execute("UPDATE crawl_tasks SET status='done' WHERE run_id=? AND brand=? AND kind='category' AND key=?",(run,b,cid));c.commit();process_products()
   except Missing:
    c.execute("UPDATE crawl_tasks SET status='skipped',error='Category does not provide a product grid' WHERE run_id=? AND brand=? AND kind='category' AND key=?",(run,b,cid));c.commit()
   except (Blocked,Limit):raise
   except Exception as e:
    c.rollback()
    c.execute("UPDATE crawl_tasks SET status='failed',error=? WHERE run_id=? AND brand=? AND kind='category' AND key=?",(str(e)[:1500],run,b,cid));c.commit();event(c,run,b,u,'category_error',e)
  pending=c.execute("SELECT COUNT(*) FROM crawl_tasks WHERE run_id=? AND brand=? AND status IN ('pending','failed')",(run,b)).fetchone()[0]
  n=c.execute('SELECT COUNT(*) FROM snapshots s JOIN products p ON p.id=s.product_id WHERE run_id=? AND brand=?',(run,b)).fetchone()[0]
  status='complete' if not pending and n else 'partial'
  detail='All discovered category products processed; images download separately. Full storefront coverage remains unverified.' if status=='complete' else 'Some catalog items are missing; inspect crawl_tasks and events.'
 except Blocked as e:c.rollback();status='blocked';detail=str(e);event(c,run,b,home,'blocked',e)
 except Exception as e:c.rollback();status='failed';detail=str(e);event(c,run,b,home,'error',e)
 stats(c,run,b,status,detail);logging.info('%s %s: %s',b,status,detail);c.close();return status
def begin_run(c,resume=True):
 marker=ROOT/'force-new-run';forced=marker.exists();stamp=now();month=stamp[:7]
 last=c.execute('SELECT * FROM runs ORDER BY id DESC LIMIT 1').fetchone()
 if resume and not forced and last and last['started_at'].startswith(month) and 'public_api_v2' in last['config_json'] and last['status']!='complete':
  run=last['id'];c.execute("UPDATE runs SET status='running',finished_at=NULL WHERE id=?",(run,));c.execute("UPDATE crawl_tasks SET status='pending',error=NULL WHERE run_id=? AND status='failed'",(run,))
 else:run=c.execute("INSERT INTO runs(started_at,status,config_json) VALUES(?,'running',?)",(stamp,js({'adapter':'public_api_v2','market':'ES','brands':list(BRANDS),'trigger':'manual' if forced else 'scheduled'}))).lastrowid
 c.commit()
 if forced:marker.unlink()
 return run
def harvest(resume=True):
 ROOT.mkdir(exist_ok=True)
 with (ROOT/'run.lock').open('w') as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:raise SystemExit('Another catalog collector is running')
  c=connect();c.executescript(EXTRA_SCHEMA);run=begin_run(c,resume);logging.info('Starting run %s',run)
  with futures.ThreadPoolExecutor(max_workers=8) as pool:statuses=list(pool.map(lambda b:collect_brand(run,b),BRANDS))
  final='complete' if all(x=='complete' for x in statuses) else 'incomplete'
  c.execute('UPDATE runs SET status=?,finished_at=? WHERE id=?',(final,now(),run));c.commit()
  report=catalog.report(c);(ROOT/'latest-report.json').write_text(json.dumps(report,ensure_ascii=False,indent=2));print(js(report))
  return 0 if final=='complete' else 1
def media_worker():
 with (ROOT/'media.lock').open('w') as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:return 0
  c=connect();c.executescript(EXTRA_SCHEMA);local=threading.local()
  host_locks={};host_last={};guard=threading.Lock()
  def download(row):
   url=row['source_url'];host=urllib.parse.urlsplit(url).hostname
   try:
    if host not in MEDIA_HOSTS:raise ValueError('Unconfigured media host')
    with guard:l=host_locks.setdefault(host,threading.Lock())
    with l:
     time.sleep(max(0,0.5-(time.monotonic()-host_last.get(host,0))));host_last[host]=time.monotonic()
    if not hasattr(local,'net'):local.net=Client()
    r=local.net.get(url)
    if len(r.content)>20_000_000:raise Limit('Image exceeds 20 MB')
    with Image.open(io.BytesIO(r.content)) as im:
     w,h=im.size;fmt=im.format;im.verify()
    ext={'JPEG':'.jpg','PNG':'.png','WEBP':'.webp','AVIF':'.avif'}.get(fmt)
    if not ext:raise ValueError('Unsupported image format')
    digest=hashlib.sha256(r.content).hexdigest();rel=f'media/{digest[:2]}/{digest}{ext}';path=ROOT/rel;path.parent.mkdir(parents=True,exist_ok=True)
    if not path.exists():
     temp=path.with_name(path.name+'.'+str(threading.get_ident())+'.tmp');temp.write_bytes(r.content);temp.chmod(0o644);os.replace(temp,path)
    return row['id'],url,(rel,digest,w,h),None
   except Exception as e:return row['id'],url,None,str(e)[:1000]
  with futures.ThreadPoolExecutor(max_workers=4) as pool:
   while True:
    if shutil.disk_usage(ROOT).free<15*1024**3:
     logging.error('Media paused: less than 15 GiB disk space available');return 1
    rows=[]
    for brand in BRANDS:
     rows.extend(c.execute("SELECT i.id,i.source_url FROM images i JOIN snapshots s ON s.id=i.snapshot_id JOIN products p ON p.id=s.product_id WHERE i.status='pending' AND p.brand=? ORDER BY i.position,i.id LIMIT 5",(brand,)).fetchall())
    if not rows:return 0
    for iid,url,data,error in pool.map(download,rows):
     if data:
      rel,digest,w,h=data
      c.execute('INSERT OR REPLACE INTO media_cache VALUES(?,?,?,?,?)',(url,rel,digest,w,h))
      c.execute("UPDATE images SET status='downloaded',local_path=?,sha256=?,width=?,height=? WHERE source_url=? AND status='pending'",(rel,digest,w,h,url))
     else:c.execute("UPDATE images SET status='error',detail=? WHERE id=?",(error,iid))
     c.commit()
def main():
 logging.basicConfig(level=logging.INFO,format='%(asctime)s %(levelname)s %(message)s')
 parser=argparse.ArgumentParser();parser.add_argument('mode',choices=['harvest','media']);args=parser.parse_args()
 return harvest() if args.mode=='harvest' else media_worker()
if __name__=='__main__':raise SystemExit(main())
