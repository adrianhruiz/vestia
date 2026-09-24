#!/usr/bin/env python3
"""Monthly public catalog archive. Coverage requires a validated brand adapter."""
import argparse, concurrent.futures, datetime as dt, fcntl, gzip, hashlib, io, json, logging
import os, pathlib, shutil, sqlite3, sys, time, urllib.error, urllib.parse, urllib.request
import urllib.robotparser, xml.etree.ElementTree as ET
from bs4 import BeautifulSoup
from PIL import Image

ROOT = pathlib.Path(os.environ.get('CATALOG_DATA', '/var/lib/inditex-catalog'))
CONFIG = pathlib.Path(__file__).with_name('config.json')
UA = 'CatalogArchive/1.0'
SCHEMA = '''
PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;
CREATE TABLE IF NOT EXISTS runs(id INTEGER PRIMARY KEY, started_at TEXT NOT NULL, finished_at TEXT, status TEXT NOT NULL, config_json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS brand_runs(id INTEGER PRIMARY KEY, run_id INTEGER NOT NULL REFERENCES runs(id), brand TEXT NOT NULL, market TEXT NOT NULL, status TEXT NOT NULL, discovered INTEGER DEFAULT 0, processed INTEGER DEFAULT 0, products INTEGER DEFAULT 0, errors INTEGER DEFAULT 0, detail TEXT, coverage_verified INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS products(id INTEGER PRIMARY KEY, brand TEXT NOT NULL, market TEXT NOT NULL, source_key TEXT NOT NULL, url TEXT NOT NULL, first_seen TEXT NOT NULL, last_seen TEXT NOT NULL, UNIQUE(brand,market,source_key));
CREATE TABLE IF NOT EXISTS snapshots(id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL REFERENCES products(id), run_id INTEGER NOT NULL REFERENCES runs(id), captured_at TEXT NOT NULL, name TEXT, description TEXT, sku TEXT, category_json TEXT, labels_json TEXT, colors_json TEXT, sizes_json TEXT, offers_json TEXT, composition_json TEXT, raw_json TEXT NOT NULL, source_html TEXT, UNIQUE(product_id,run_id));
CREATE TABLE IF NOT EXISTS variants(id INTEGER PRIMARY KEY, snapshot_id INTEGER NOT NULL REFERENCES snapshots(id), source_key TEXT, color_json TEXT, size_json TEXT, offers_json TEXT, raw_json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS images(id INTEGER PRIMARY KEY, snapshot_id INTEGER NOT NULL REFERENCES snapshots(id), source_url TEXT NOT NULL, position INTEGER NOT NULL, local_path TEXT, sha256 TEXT, width INTEGER, height INTEGER, status TEXT NOT NULL, detail TEXT, selection_status TEXT NOT NULL DEFAULT 'unreviewed', UNIQUE(snapshot_id,source_url));
CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY, run_id INTEGER REFERENCES runs(id), brand TEXT, created_at TEXT NOT NULL, url TEXT, kind TEXT NOT NULL, message TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS snapshots_run ON snapshots(run_id);
CREATE INDEX IF NOT EXISTS events_run ON events(run_id);
'''
def now(): return dt.datetime.now(dt.timezone.utc).isoformat()
def js(x): return json.dumps(x, ensure_ascii=False, sort_keys=True)
def array(x): return x if isinstance(x,list) else ([] if x is None else [x])
class Blocked(RuntimeError): pass
class Limited(RuntimeError): pass
class Network:
    def __init__(self, delay=2): self.delay=delay; self.last=0
    def get(self,url,limit=20_000_000):
        if urllib.parse.urlsplit(url).scheme != 'https': raise ValueError('Only HTTPS is supported')
        for attempt in range(3):
            time.sleep(max(0,self.delay-(time.monotonic()-self.last)))
            self.last=time.monotonic()
            try:
                req=urllib.request.Request(url,headers={'User-Agent':UA,'Accept-Language':'es-ES,es;q=0.9','Accept-Encoding':'identity'})
                with urllib.request.urlopen(req,timeout=35) as r:
                    data=r.read(limit+1)
                    if len(data)>limit: raise Limited('Response size limit exceeded')
                    return data, r.headers.get('Content-Type',''), r.geturl()
            except urllib.error.HTTPError as e:
                if e.code in (401,403,429): raise Blocked(f'HTTP {e.code}: {url}') from e
                if e.code < 500 or attempt==2: raise
            except (TimeoutError,urllib.error.URLError):
                if attempt==2: raise
            time.sleep(2**attempt)

def extract(html):
    soup=BeautifulSoup(html,'html.parser'); found=[]
    def walk(x):
        if isinstance(x,dict):
            types=array(x.get('@type'))
            if 'Product' in types or 'ProductGroup' in types: found.append(x); return
            for v in x.values(): walk(v)
        elif isinstance(x,list):
            for v in x: walk(v)
    for node in soup.find_all('script',type='application/ld+json'):
        try: walk(json.loads(node.string or node.get_text()))
        except (ValueError,TypeError): pass
    return found

def media_urls(p):
    result=[]
    for obj in [p]+[v for v in array(p.get('hasVariant')) if isinstance(v,dict)]:
        for x in array(obj.get('image')):
            url=x.get('contentUrl') or x.get('url') if isinstance(x,dict) else x
            if isinstance(url,str) and url not in result: result.append(url)
    return result

def in_market(url,b):
    u=urllib.parse.urlsplit(url); base=urllib.parse.urlsplit(b['home'])
    return u.scheme=='https' and u.netloc==base.netloc and u.path.startswith(base.path)

def archive(data,subdir,suffix):
    digest=hashlib.sha256(data).hexdigest(); relative=f'{subdir}/{digest[:2]}/{digest}{suffix}'
    path=ROOT/relative; path.parent.mkdir(parents=True,exist_ok=True)
    if not path.exists():
        tmp=path.with_suffix(path.suffix+'.tmp'); tmp.write_bytes(data); tmp.replace(path)
    return relative,digest

def event(db,run,brand,url,kind,message):
    db.execute('INSERT INTO events(run_id,brand,created_at,url,kind,message) VALUES(?,?,?,?,?,?)',(run,brand,now(),url,kind,str(message)))
    db.commit(); logging.warning('%s %s %s',brand,kind,message)

def save_product(db,net,run,b,p,url,source,cfg):
    stamp=now(); key=str(p.get('productGroupID') or p.get('sku') or p.get('mpn') or p.get('@id') or url)
    db.execute('INSERT INTO products(brand,market,source_key,url,first_seen,last_seen) VALUES(?,?,?,?,?,?) ON CONFLICT(brand,market,source_key) DO UPDATE SET url=excluded.url,last_seen=excluded.last_seen',(b['id'],'ES',key,url,stamp,stamp))
    pid=db.execute('SELECT id FROM products WHERE brand=? AND market=? AND source_key=?',(b['id'],'ES',key)).fetchone()[0]
    variants=[v for v in array(p.get('hasVariant')) if isinstance(v,dict)]
    colors=array(p.get('color')); sizes=array(p.get('size'))
    for v in variants:
        for c in array(v.get('color')):
            if c not in colors: colors.append(c)
        for s in array(v.get('size')):
            if s not in sizes: sizes.append(s)
    # Missing attributes remain unknown; never infer stock, fabric or tags.
    row=db.execute('INSERT INTO snapshots(product_id,run_id,captured_at,name,description,sku,category_json,labels_json,colors_json,sizes_json,offers_json,composition_json,raw_json,source_html) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(product_id,run_id) DO NOTHING RETURNING id',(pid,run,stamp,p.get('name'),p.get('description'),str(p.get('sku','')),js(p.get('category')),js(p.get('keywords')),js(colors),js(sizes),js(p.get('offers')),js(p.get('material')),js(p),source)).fetchone()
    if row is None: return False
    sid=row[0]
    for v in variants:
        db.execute('INSERT INTO variants(snapshot_id,source_key,color_json,size_json,offers_json,raw_json) VALUES(?,?,?,?,?,?)',(sid,str(v.get('sku') or v.get('@id') or ''),js(v.get('color')),js(v.get('size')),js(v.get('offers')),js(v)))
    db.commit()
    for i,img in enumerate(media_urls(p)):
        img=urllib.parse.urljoin(url,img); host=urllib.parse.urlsplit(img).hostname or ''
        vals=[sid,img,i,None,None,None,None,'error',None]
        try:
            if not any(host==h or host.endswith('.'+h) for h in cfg['image_hosts']): raise ValueError('Image host not configured: '+host)
            if shutil.disk_usage(ROOT).free < cfg['min_free_gb']*1024**3: raise Limited('Minimum free disk space reached')
            data,ct,_=net.get(img)
            with Image.open(io.BytesIO(data)) as im:
                w,h=im.size; fmt=im.format; im.verify()
            ext={'JPEG':'.jpg','PNG':'.png','WEBP':'.webp','AVIF':'.avif','GIF':'.gif'}.get(fmt,'.img')
            path,digest=archive(data,'media',ext)
            vals[3:9]=[path,digest,w,h,'downloaded',None]
        except Exception as e:
            vals[8]=str(e)
            db.execute('INSERT INTO images(snapshot_id,source_url,position,local_path,sha256,width,height,status,detail) VALUES(?,?,?,?,?,?,?,?,?)',vals)
            db.commit()
            if isinstance(e,(Blocked,Limited)): raise
            continue
        db.execute('INSERT INTO images(snapshot_id,source_url,position,local_path,sha256,width,height,status,detail) VALUES(?,?,?,?,?,?,?,?,?)',vals); db.commit()
    return True

def crawl_brand(db,run,b,cfg):
    bid=db.execute('INSERT INTO brand_runs(run_id,brand,market,status) VALUES(?,?,?,?)',(run,b['id'],'ES','running')).lastrowid; db.commit()
    net=Network(cfg['request_delay_seconds']); discovered=set(); processed=0; errors=0; count=0
    status='partial'; detail='Generic JSON-LD adapter; full catalog coverage is not yet validated.'
    try:
        robot_url=urllib.parse.urljoin(b['home'],'/robots.txt')
        data,_,_=net.get(robot_url,2_000_000)
        robot=urllib.robotparser.RobotFileParser(); robot.parse(data.decode(errors='replace').splitlines())
        net.delay=max(net.delay,robot.crawl_delay(UA) or 0)
        if not robot.can_fetch(UA,b['home']): raise Blocked('robots.txt disallows market home')
        # Preflight verifies storefront accessibility before potentially large sitemap traversal.
        home,_,final=net.get(b['home'])
        if not in_market(final,b): raise Blocked('Store redirected outside configured ES market: '+final)
        archive(gzip.compress(home,mtime=0),'raw','.html.gz')
        sitemaps=robot.site_maps() or b.get('sitemaps',[])
        queue=list(sitemaps); seen=set(); seeds=[]
        while queue:
            sm=queue.pop(0)
            if sm in seen: continue
            if len(seen)>=cfg['max_sitemaps']: raise Limited('Sitemap limit reached; coverage incomplete')
            # Crawl only official sitemap hosts configured for this brand.
            if urllib.parse.urlsplit(sm).hostname not in b['sitemap_hosts']: continue
            if not robot.can_fetch(UA,sm): continue
            seen.add(sm); data,_,_=net.get(sm,40_000_000)
            if data[:2]==b'\x1f\x8b':
                with gzip.GzipFile(fileobj=io.BytesIO(data)) as f: data=f.read(40_000_001)
                if len(data)>40_000_000: raise Limited('Decompressed sitemap too large')
            doc=ET.fromstring(data)
            urls=[x.text.strip() for x in doc.iter() if x.tag.split('}')[-1]=='loc' and x.text]
            if doc.tag.split('}')[-1]=='sitemapindex': queue.extend(urls)
            else:
                for u in urls:
                    if in_market(u,b) and u not in discovered:
                        discovered.add(u); seeds.append(u)
                        if len(seeds)>cfg['max_pages_per_brand']: raise Limited('Page limit reached; coverage incomplete')
        if not seeds: raise RuntimeError('No ES URLs discovered. Brand-specific discovery adapter required.')
        for url in seeds:
            if not robot.can_fetch(UA,url): errors+=1; continue
            if shutil.disk_usage(ROOT).free < cfg['min_free_gb']*1024**3: raise Limited('Minimum free disk space reached')
            try:
                data,ct,final=net.get(url)
                if not in_market(final,b): raise ValueError('Redirect outside ES market')
                if 'html' not in ct: continue
                source,_=archive(gzip.compress(data,mtime=0),'raw','.html.gz')
                for p in extract(data): count+=save_product(db,net,run,b,p,final,source,cfg)
                processed+=1
            except (Blocked,Limited): raise
            except Exception as e: errors+=1; event(db,run,b['id'],url,'page_error',e)
        if not count: status='unsupported'; detail='No product JSON-LD found. A validated brand-specific adapter is required.'
    except Blocked as e: status='blocked'; detail=str(e); errors+=1; event(db,run,b['id'],b['home'],'blocked',e)
    except Limited as e: status='partial'; detail=str(e); errors+=1; event(db,run,b['id'],b['home'],'limit',e)
    except Exception as e: status='failed'; detail=str(e); errors+=1; event(db,run,b['id'],b['home'],'error',e)
    db.execute('UPDATE brand_runs SET status=?,discovered=?,processed=?,products=?,errors=?,detail=? WHERE id=?',(status,len(discovered),processed,count,errors,detail,bid)); db.commit()
    logging.info('%s: %s; %s products; %s',b['id'],status,count,detail)

def connect():
    ROOT.mkdir(parents=True,exist_ok=True); db=sqlite3.connect(ROOT/'catalog.sqlite3'); db.executescript(SCHEMA); return db

def report(db):
    db.row_factory=sqlite3.Row
    run=db.execute('SELECT * FROM runs ORDER BY id DESC LIMIT 1').fetchone()
    if not run: return {'status':'no_runs'}
    result=dict(run); result.pop('config_json'); result['brands']=[dict(r) for r in db.execute('SELECT * FROM brand_runs WHERE run_id=?',(run['id'],))]
    result['total_products']=db.execute('SELECT COUNT(*) FROM products').fetchone()[0]
    result['downloaded_images']=db.execute("SELECT COUNT(*) FROM images WHERE status='downloaded'").fetchone()[0]
    return result

def main():
    parser=argparse.ArgumentParser(); parser.add_argument('command',choices=['run','status','init']); args=parser.parse_args()
    logging.basicConfig(level=logging.INFO,format='%(asctime)s %(levelname)s %(message)s')
    db=connect()
    if args.command=='init': return 0
    if args.command=='status': print(js(report(db))); return 0
    with (ROOT/'run.lock').open('w') as lock:
        try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: logging.error('Another catalog run is active'); return 2
        # Recover interrupted runs without hiding their failure or deleting observations.
        db.execute("UPDATE brand_runs SET status='interrupted',detail='Previous process stopped' WHERE status='running'")
        db.execute("UPDATE runs SET status='interrupted',finished_at=? WHERE status='running'",(now(),)); db.commit()
        cfg=json.loads(CONFIG.read_text()); rid=db.execute('INSERT INTO runs(started_at,status,config_json) VALUES(?,?,?)',(now(),'running',js(cfg))).lastrowid; db.commit()
        for b in cfg['brands']: crawl_brand(db,rid,b,cfg)
        statuses=[r[0] for r in db.execute('SELECT status FROM brand_runs WHERE run_id=?',(rid,))]
        status='blocked' if statuses and all(s=='blocked' for s in statuses) else 'incomplete'
        db.execute('UPDATE runs SET finished_at=?,status=? WHERE id=?',(now(),status,rid)); db.commit()
        result=report(db); tmp=ROOT/'latest-report.json.tmp'; tmp.write_text(json.dumps(result,ensure_ascii=False,indent=2)); tmp.replace(ROOT/'latest-report.json')
        print(js(result)); return 1 # Completion is never asserted without validated coverage.
if __name__=='__main__': sys.exit(main())
