#!/usr/bin/env python3
"""Publish a read-only, validated catalog index for the authenticated Vestia viewer."""
import datetime, fcntl, hashlib, json, os, pathlib, re, shutil, sqlite3
from PIL import Image, ImageOps
ROOT=pathlib.Path('/var/lib/inditex-catalog'); OUT=ROOT/'viewer'
BRANDS={'zara':'Zara','pullandbear':'Pull&Bear','massimodutti':'Massimo Dutti','bershka':'Bershka','stradivarius':'Stradivarius','oysho':'Oysho','zarahome':'Zara Home','lefties':'Lefties'}
def dumps(v):return json.dumps(v,ensure_ascii=False)
def arr(v):return v if isinstance(v,list) else ([] if v is None else [v])
def text(v):
 if isinstance(v,str):return re.sub('<[^>]+>',' ',v).strip()
 return ''
def build():
 OUT.mkdir(exist_ok=True); (OUT/'thumbs').mkdir(exist_ok=True)
 needed=(OUT/'catalog.sqlite3').stat().st_size if (OUT/'catalog.sqlite3').exists() else 0
 if shutil.disk_usage(ROOT).free<15*1024**3+needed:raise RuntimeError('Insufficient disk reserve to refresh viewer safely')
 with (OUT/'build.lock').open('w') as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:return
  source=sqlite3.connect(f'file:{ROOT}/catalog.sqlite3?mode=ro',uri=True);source.row_factory=sqlite3.Row
  dest=OUT/'catalog.next.sqlite3'
  if dest.exists():dest.unlink()
  db=sqlite3.connect(dest)
  db.executescript("""CREATE TABLE products(id INTEGER PRIMARY KEY,brand TEXT,name TEXT,reference TEXT,url TEXT,description TEXT,category TEXT,color_text TEXT,colors_json TEXT,sizes_json TEXT,tags_json TEXT,composition_json TEXT,price REAL,currency TEXT,availability TEXT,captured_at TEXT,raw_json TEXT,main_image INTEGER,images_json TEXT);
CREATE TABLE images(id INTEGER PRIMARY KEY,path TEXT,thumb TEXT,mime TEXT,width INTEGER,height INTEGER);
CREATE TABLE metadata(key TEXT PRIMARY KEY,value TEXT);
CREATE INDEX products_brand ON products(brand);
CREATE INDEX products_category ON products(category);""")
  count=0
  previous=None
  if (OUT/'catalog.sqlite3').exists():
   previous=sqlite3.connect(f"file:{OUT}/catalog.sqlite3?mode=ro",uri=True);previous.row_factory=sqlite3.Row
  snapshots=source.execute("""SELECT p.id product_id,p.brand,p.source_key,p.url,s.* FROM products p JOIN snapshots s ON s.id=(SELECT s2.id FROM snapshots s2 WHERE s2.product_id=p.id ORDER BY s2.captured_at DESC,s2.id DESC LIMIT 1)""")
  invalid=0
  for s in snapshots:
   count+=1
   p=json.loads(s['raw_json']); norm=p.get('_normalized',{}); detail=p.get('detail') or {}; seo=p.get('seo') or {}
   colors=norm.get('colors') or p.get('availableColors') or detail.get('colors') or p.get('color') or []
   labels=[x.get('colorName') or x.get('name') or x.get('color') or '' if isinstance(x,dict) else str(x) for x in arr(colors)]
   labels=list(dict.fromkeys(x for x in labels if x))
   sizes=norm.get('sizes') or p.get('size') or []
   if not sizes:
    sizes=list(dict.fromkeys(str(v.get('name') or v.get('size') or '') for c in detail.get('colors',[]) for v in c.get('sizes',[]) if isinstance(v,dict)))
   url=norm.get('url') or s['url']
   if s['brand']=='zara' and seo.get('keyword') and seo.get('seoProductId'):
    url='https://www.zara.com/es/es/'+seo['keyword']+'-p'+str(seo['seoProductId'])+'.html'
   tags=norm.get('tags') or [v.get('displayName') or v.get('type') for v in p.get('tagTypes',[]) if isinstance(v,dict)] or p.get('productTag') or []
   price=norm.get('price')
   if price is None and s['brand']=='zara' and isinstance(p.get('price'),(int,float)):price=p['price']/100
   imgs=[]; seen=set()
   for img in source.execute("SELECT * FROM images WHERE snapshot_id=? AND status='downloaded' ORDER BY position,id",(s['id'],)):
    if not img['local_path'] or img['sha256'] in seen:continue
    path=(ROOT/img['local_path']).resolve()
    if not path.is_relative_to((ROOT/'media').resolve()) or not path.is_file():continue
    try:
     cached=previous.execute('SELECT * FROM images WHERE id=?',(img['id'],)).fetchone() if previous else None
     if cached and cached['path']==img['local_path'] and (ROOT/cached['thumb']).is_file():
      db.execute('INSERT OR IGNORE INTO images VALUES(?,?,?,?,?,?)',tuple(cached))
      rank=0 if re.search(r'-f1(?:/|\.)',img['source_url'],re.I) else 1 if re.search(r'-p(?:/|\.)',img['source_url'],re.I) else 2
      imgs.append({'id':img['id'],'width':cached['width'],'height':cached['height'],'rank':rank});seen.add(img['sha256']);continue
     with Image.open(path) as im:
      if im.format not in ('JPEG','PNG','WEBP','AVIF'):continue
      w,h=im.size
      if w<100 or h<100:continue
      im.verify()
     thumb=OUT/'thumbs'/(img['sha256']+'.webp')
     if not thumb.exists():
      with Image.open(path) as im:
       im=ImageOps.exif_transpose(im);im.thumbnail((480,640));im.convert('RGB').save(thumb,format='WEBP',quality=82)
      thumb.chmod(0o640)
     mime='image/'+({'jpg':'jpeg'}.get(path.suffix[1:].lower(),path.suffix[1:].lower()))
     with Image.open(path) as im:mime=Image.MIME.get(im.format,'application/octet-stream')
     db.execute('INSERT OR IGNORE INTO images VALUES(?,?,?,?,?,?)',(img['id'],img['local_path'],'viewer/thumbs/'+thumb.name,mime,w,h))
     rank=0 if re.search(r'-f1(?:/|\.)',img['source_url'],re.I) else 1 if re.search(r'-p(?:/|\.)',img['source_url'],re.I) else 2
     imgs.append({'id':img['id'],'width':w,'height':h,'rank':rank})
     seen.add(img['sha256'])
    except Exception:invalid+=1
   imgs.sort(key=lambda x:x['rank'])
   vals=(s['product_id'],s['brand'],s['name'],detail.get('displayReference') or s['sku'] or s['source_key'],url,text(norm.get('description') or p.get('description') or s['description']),norm.get('category') or p.get('familyName') or '',', '.join(labels),dumps(labels),dumps(sizes),dumps(tags),dumps(norm.get('composition') or p.get('composition') or p.get('extra_detail') or None),price,norm.get('currency','EUR'),norm.get('availability') or p.get('availability') or '',s['captured_at'],s['raw_json'],imgs[0]['id'] if imgs else None,dumps(imgs))
   db.execute('INSERT INTO products VALUES('+','.join(['?']*len(vals))+')',vals)
  status=[dict(r) for r in source.execute('SELECT * FROM brand_runs WHERE run_id=(SELECT MAX(id) FROM runs)')]
  metadata={'generated_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'brands':BRANDS,'source_status':status,'invalid_images':invalid}
  for k,v in metadata.items():db.execute('INSERT INTO metadata VALUES(?,?)',(k,dumps(v)))
  db.commit();db.close();dest.chmod(0o640);os.replace(dest,OUT/'catalog.sqlite3')
  print(dumps({'products':count,'invalid_images':invalid,'generated_at':metadata['generated_at']}))
if __name__=='__main__':build()
