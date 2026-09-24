"""Private, on-demand catalog photos; reachable only through a Unix socket."""
import concurrent.futures
import functools
import hashlib
import io
import json
import logging
import os
import pathlib
import shutil
import socketserver
import sqlite3
import tempfile
import threading
import urllib.parse
from http.server import BaseHTTPRequestHandler, HTTPServer

from curl_cffi import requests
from PIL import Image, ImageOps
import harvest

ROOT = pathlib.Path('/var/lib/inditex-catalog')
VIEWER = ROOT / 'viewer/catalog.sqlite3'
CACHE = ROOT / 'viewer/ondemand'
SOCKET = ROOT / 'viewer/image-gateway.sock'
Image.MAX_IMAGE_PIXELS = 40_000_000
LOCKS = [threading.Lock() for _ in range(64)]
DOWNLOADS = threading.BoundedSemaphore(4)
FORMATS = {'JPEG': 'image/jpeg', 'PNG': 'image/png', 'WEBP': 'image/webp', 'AVIF': 'image/avif'}


def allowed_url(url):
    p = urllib.parse.urlsplit(url)
    return (p.scheme == 'https' and p.hostname in harvest.MEDIA_HOSTS
            and p.port in (None, 443) and not p.username and not p.password)


@functools.lru_cache(maxsize=128)
def sources(product_id, generation):
    with sqlite3.connect(f'file:{VIEWER}?mode=ro', uri=True) as db:
        row = db.execute('SELECT brand,raw_json FROM products WHERE id=?', (product_id,)).fetchone()
    if not row:
        raise FileNotFoundError('Product not found')
    brand, raw = row
    product = json.loads(raw)
    host = harvest.BRANDS[brand][0]
    home = 'https://' + host + ('/es/es/' if brand == 'zara' else '/es/')
    # Re-read the original public media so older captures also benefit from
    # the corrected CDN host list (notably static.zarahome.net).
    normalized = harvest.normalize(brand, product, home)
    media = [m for m in normalized['media'] if allowed_url(m['url'])]
    # Several brands call the 40px color swatch 'r'/'r1'. It must not be
    # the catalog cover when actual product photographs are available.
    media.sort(key=lambda m: str(m.get('name', '')).lower() in ('r', 'r1', 'r2', 'swatch'))
    return tuple(m['url'] for m in media)


def atomic_write(path, data):
    fd, temporary = tempfile.mkstemp(dir=CACHE)
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(data)
        os.chmod(temporary, 0o640)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def archived_image(url):
    with sqlite3.connect(f'file:{ROOT}/catalog.sqlite3?mode=ro', uri=True, timeout=5) as db:
        row = db.execute("SELECT local_path FROM images WHERE source_url=? AND status='downloaded' LIMIT 1", (url,)).fetchone()
    if row and row[0]:
        path = (ROOT / row[0]).resolve()
        if path.is_relative_to((ROOT / 'media').resolve()) and path.is_file():
            return path.read_bytes()
    return None


def download(url):
    if not allowed_url(url):
        raise ValueError('Unconfigured image source')
    with DOWNLOADS:
        with requests.Session(impersonate='chrome') as session:
            response = session.get(url, timeout=20, allow_redirects=False, stream=True)
            try:
                if response.status_code != 200:
                    raise RuntimeError(f'Image source returned HTTP {response.status_code}')
                data = bytearray()
                for chunk in response.iter_content():
                    data.extend(chunk)
                    if len(data) > 20_000_000:
                        raise ValueError('Image exceeds 20 MB')
                return bytes(data)
            finally:
                response.close()


def photo(url, full=False):
    if not allowed_url(url):
        raise ValueError('Unconfigured image source')
    key = hashlib.sha256(url.encode()).hexdigest()
    original, thumb = CACHE / (key + '.image'), CACHE / (key + '.webp')
    with LOCKS[int(key[:2], 16) % len(LOCKS)]:
        if not full and thumb.is_file():
            return thumb.read_bytes(), 'image/webp'
        data = original.read_bytes() if original.is_file() else archived_image(url)
        if data is None:
            if shutil.disk_usage(ROOT).free < 15 * 1024**3:
                raise RuntimeError('Image cache disk reserve reached')
            data = download(url)
        with Image.open(io.BytesIO(data)) as im:
            mime = FORMATS.get(im.format)
            if not mime or min(im.size) < 100:
                raise ValueError('Invalid catalog image')
            im.verify()
        if not original.exists():
            atomic_write(original, data)
        if full:
            return data, mime
        with Image.open(io.BytesIO(data)) as im:
            im = ImageOps.exif_transpose(im)
            im.thumbnail((480, 640))
            out = io.BytesIO()
            im.convert('RGB').save(out, format='WEBP', quality=82)
        atomic_write(thumb, out.getvalue())
        return out.getvalue(), 'image/webp'


def product_photo(urls, position, full=False):
    if position < 0 or position >= len(urls):
        raise FileNotFoundError('Image not found')
    candidates = urls[:10] if position == 0 else urls[position:position + 1]
    last_error = None
    for url in candidates:
        try:
            return photo(url, full)
        except (ValueError, OSError) as exc:
            # A successful CDN response can still be a tiny placeholder.
            # Try the next real photo instead of displaying a broken cover.
            last_error = exc
    raise ValueError('No usable photograph in this capture') from last_error


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        logging.info(fmt, *args)

    def do_GET(self):
        try:
            parsed = urllib.parse.urlsplit(self.path)
            query = urllib.parse.parse_qs(parsed.query)
            if parsed.path not in ('/image', '/sources'):
                raise FileNotFoundError('Unknown route')
            product = int(query.get('product', ['0'])[0])
            urls = sources(product, VIEWER.stat().st_mtime_ns)
            if parsed.path == '/sources':
                data = json.dumps({'images': [{'source_index': i} for i in range(len(urls))]}).encode()
                mime = 'application/json'
            else:
                position = int(query.get('position', ['0'])[0])
                data, mime = product_photo(urls, position, query.get('size') == ['full'])
            self.send_response(200)
            self.send_header('Content-Type', mime)
            self.send_header('Content-Length', str(len(data)))
            self.send_header('Cache-Control', 'private, no-store')
            self.end_headers()
            self.wfile.write(data)
        except (FileNotFoundError, ValueError) as exc:
            self.send_error(404, str(exc))
        except (BrokenPipeError, ConnectionResetError):
            pass
        except Exception:
            logging.exception('Catalog image request failed')
            self.send_error(502, 'Image temporarily unavailable')


class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True
    request_queue_size = 64
    # A bounded pool prevents a large gallery from spawning unlimited decoders.
    def __init__(self, *args):
        self.pool = concurrent.futures.ThreadPoolExecutor(max_workers=8)
        super().__init__(*args)

    def process_request(self, request, address):
        self.pool.submit(self.process_request_thread, request, address)


def main():
    logging.basicConfig(level=logging.INFO)
    CACHE.mkdir(mode=0o750, exist_ok=True)
    SOCKET.unlink(missing_ok=True)
    with Server(str(SOCKET), Handler) as server:
        SOCKET.chmod(0o660)
        server.serve_forever()


if __name__ == '__main__':
    main()
