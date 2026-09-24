import io, json, pathlib, tempfile, unittest
from unittest.mock import patch
import catalog
from PIL import Image

class ArchiveTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(); self.old=catalog.ROOT; catalog.ROOT=pathlib.Path(self.temp.name)
        self.db=catalog.connect()
        self.run=self.db.execute("INSERT INTO runs(started_at,status,config_json) VALUES('now','running','{}')").lastrowid; self.db.commit()
        self.brand={'id':'fixture','home':'https://example.com/es/','sitemap_hosts':['example.com']}
        self.cfg={'request_delay_seconds':0,'min_free_gb':0,'image_hosts':['example.com']}
    def tearDown(self): self.db.close(); catalog.ROOT=self.old; self.temp.cleanup()
    def test_history_variants_and_image_deduplication(self):
        buf=io.BytesIO(); Image.new('RGB',(240,480),'red').save(buf,format='PNG')
        class FakeNet:
            def get(self,url): return buf.getvalue(),'image/png',url
        product={'@type':'ProductGroup','productGroupID':'123','name':'Vestido','material':'Algodón','image':['https://example.com/a.png','https://example.com/b.png'],'hasVariant':[{'@type':'Product','sku':'123-S','color':'Rojo','size':'S','offers':{'price':'19.99','priceCurrency':'EUR','availability':'https://schema.org/InStock'}}]}
        self.assertTrue(catalog.save_product(self.db,FakeNet(),self.run,self.brand,product,'https://example.com/es/p','raw/test',self.cfg))
        self.assertFalse(catalog.save_product(self.db,FakeNet(),self.run,self.brand,product,'https://example.com/es/p','raw/test',self.cfg))
        run2=self.db.execute("INSERT INTO runs(started_at,status,config_json) VALUES('later','running','{}')").lastrowid
        catalog.save_product(self.db,FakeNet(),run2,self.brand,product,'https://example.com/es/p','raw/test',self.cfg)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM products').fetchone()[0],1)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM snapshots').fetchone()[0],2)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM variants').fetchone()[0],2)
        self.assertEqual(self.db.execute('SELECT COUNT(DISTINCT local_path) FROM images').fetchone()[0],1)
        row=self.db.execute('SELECT width,height,selection_status FROM images LIMIT 1').fetchone()
        self.assertEqual(row,(240,480,'unreviewed'))
    def test_blocked_is_not_success_or_empty_catalog(self):
        with patch.object(catalog.Network,'get',side_effect=catalog.Blocked('HTTP 403')):
            catalog.crawl_brand(self.db,self.run,self.brand,self.cfg)
        row=self.db.execute('SELECT status,products,errors,coverage_verified FROM brand_runs').fetchone()
        self.assertEqual(row,('blocked',0,1,0))
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM events').fetchone()[0],1)
    def test_product_group_keeps_variants_and_ignores_malformed_json(self):
        html='<script type="application/ld+json">broken</script><script type="application/ld+json">'+json.dumps({'@graph':[{'@type':'ProductGroup','hasVariant':[{'@type':'Product','sku':'x'}]}]})+'</script>'
        result=catalog.extract(html)
        self.assertEqual(len(result),1); self.assertEqual(result[0]['hasVariant'][0]['sku'],'x')
    def test_market_filter(self):
        self.assertTrue(catalog.in_market('https://example.com/es/producto',self.brand))
        for url in ['https://example.com/fr/producto','https://other.com/es/producto','https://example.com/esp/producto']:
            self.assertFalse(catalog.in_market(url,self.brand))
    def test_successful_http_without_products_is_unsupported(self):
        cfg={**self.cfg,'max_sitemaps':10,'max_pages_per_brand':10}
        def fake(net,url,*args):
            if url.endswith('robots.txt'): data=b'User-agent: *\nAllow: /\nSitemap: https://example.com/sitemap.xml'
            elif url.endswith('sitemap.xml'): data=b'<urlset><url><loc>https://example.com/es/empty</loc></url></urlset>'
            else:data=b'<html><body>JavaScript app</body></html>'
            return data,'text/html',url
        with patch.object(catalog.Network,'get',fake): catalog.crawl_brand(self.db,self.run,self.brand,cfg)
        self.assertEqual(self.db.execute('SELECT status FROM brand_runs').fetchone()[0],'unsupported')
if __name__=='__main__': unittest.main()
