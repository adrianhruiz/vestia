import io
import json
import pathlib
import sqlite3
import tempfile
import unittest
from unittest.mock import patch
from PIL import Image
import image_gateway as gateway


class ImageGatewayTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        root = pathlib.Path(self.temp.name)
        self.patches = [patch.object(gateway, 'ROOT', root), patch.object(gateway, 'CACHE', root),
                        patch.object(gateway, 'VIEWER', root / 'viewer.sqlite3')]
        for p in self.patches: p.start()
        gateway.sources.cache_clear()

    def tearDown(self):
        gateway.sources.cache_clear()
        for p in self.patches: p.stop()
        self.temp.cleanup()

    def test_only_known_https_cdn_sources(self):
        self.assertTrue(gateway.allowed_url('https://static.zarahome.net/assets/image.jpg'))
        for url in ['http://static.zara.net/a.jpg', 'https://static.zara.net.evil.test/a.jpg',
                    'https://localhost/a.jpg', 'file:///etc/passwd', 'https://static.zara.net:8080/a.jpg',
                    'https://user@static.zara.net/a.jpg']:
            self.assertFalse(gateway.allowed_url(url), url)

    def test_old_zarahome_capture_recovers_original_media(self):
        url = 'https://static.zarahome.net/assets/image.jpg'
        raw = {'id': 1, 'name': 'Cojín', '_normalized': {'media': []}, 'detail': {
            'xmedia': [{'xmediaItems': [{'medias': [{'extraInfo': {'deliveryUrl': url}}]}]}]}}
        with sqlite3.connect(gateway.VIEWER) as db:
            db.execute('CREATE TABLE products(id INTEGER,brand TEXT,raw_json TEXT)')
            db.execute('INSERT INTO products VALUES(1,?,?)', ('zarahome', json.dumps(raw)))
        self.assertEqual(gateway.sources(1, 1), (url + '?w=1600',))
        with self.assertRaises(FileNotFoundError): gateway.sources(999, 1)

    def test_download_validated_cached_and_thumbnail_not_cropped(self):
        out = io.BytesIO()
        Image.new('RGB', (1000, 1500), 'red').save(out, 'JPEG')
        url = 'https://static.zara.net/assets/test.jpg'
        with patch.object(gateway, 'archived_image', return_value=None), patch.object(gateway, 'download', return_value=out.getvalue()) as download:
            data, mime = gateway.photo(url)
            self.assertEqual(mime, 'image/webp')
            self.assertEqual(Image.open(io.BytesIO(data)).size, (427, 640))
            self.assertEqual(gateway.photo(url), (data, mime))
            self.assertEqual(gateway.photo(url, full=True), (out.getvalue(), 'image/jpeg'))
            self.assertEqual(download.call_count, 1)

    def test_html_response_is_not_cached_as_image(self):
        with patch.object(gateway, 'archived_image', return_value=None), patch.object(gateway, 'download', return_value=b'<html>Not an image</html>'):
            with self.assertRaises(Exception): gateway.photo('https://static.zara.net/assets/bad.jpg')
        self.assertEqual(list(gateway.CACHE.glob('*.image')), [])

    def test_color_swatch_does_not_hide_real_cover(self):
        with patch.object(gateway, 'photo', side_effect=[ValueError('40px swatch'), (b'photo', 'image/webp')]) as photo:
            self.assertEqual(gateway.product_photo(('swatch', 'garment'), 0), (b'photo', 'image/webp'))
            self.assertEqual(photo.call_count, 2)
        with self.assertRaises(FileNotFoundError): gateway.product_photo((), 0)


if __name__ == '__main__': unittest.main()
