import concurrent.futures
import copy
import pathlib
import sqlite3
import tempfile
import unittest
from unittest.mock import patch

import catalog
import harvest


class HarvestTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = patch.object(harvest, 'ROOT', pathlib.Path(self.temp.name))
        self.root.start()
        self.db = harvest.connect()
        self.db.executescript(catalog.SCHEMA + harvest.EXTRA_SCHEMA)
        self.run = self.db.execute("INSERT INTO runs(started_at,status,config_json) VALUES('2026-09-24','running','{}')").lastrowid
        self.db.commit()

    def tearDown(self):
        self.db.close()
        self.root.stop()
        self.temp.cleanup()

    def product(self, identifier=1):
        return {'id': identifier, 'name': 'Pantalón', 'productUrl': 'pantalon.html',
                'detail': {'reference': str(identifier), 'colors': [
                    {'id': '800', 'name': 'Negro', 'sizes': [
                        {'sku': identifier * 10, 'name': 'M', 'price': 2599, 'isBuyable': True}]}]}}

    def test_resume_is_idempotent_and_new_month_keeps_history(self):
        p = self.product()
        self.assertTrue(harvest.save(self.db, self.run, 'bershka', copy.deepcopy(p), 'https://www.bershka.com/es/', 'fixture'))
        self.assertFalse(harvest.save(self.db, self.run, 'bershka', copy.deepcopy(p), 'https://www.bershka.com/es/', 'fixture'))
        run2 = self.db.execute("INSERT INTO runs(started_at,status,config_json) VALUES('2026-10-01','running','{}')").lastrowid
        self.db.commit()
        harvest.save(self.db, run2, 'bershka', copy.deepcopy(p), 'https://www.bershka.com/es/', 'fixture')
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM products').fetchone()[0], 1)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM snapshots').fetchone()[0], 2)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM variants').fetchone()[0], 2)

    def test_eight_brand_writers_and_external_media_writer(self):
        def writer(brand):
            db = harvest.connect()
            try:
                for i in range(25):
                    harvest.save(db, self.run, brand, self.product(i + 1), 'https://example.com/es/', 'fixture')
            finally:
                db.close()

        def external_writer():
            # Separate connection intentionally bypasses the in-process RLock,
            # as the media service does in its own process.
            db = sqlite3.connect(harvest.ROOT / 'catalog.sqlite3', timeout=10)
            try:
                for i in range(100):
                    db.execute('INSERT INTO media_cache(source_url) VALUES(?)', (str(i),))
                    db.commit()
            finally:
                db.close()

        with concurrent.futures.ThreadPoolExecutor(max_workers=9) as pool:
            jobs = [pool.submit(writer, b) for b in harvest.BRANDS]
            jobs.append(pool.submit(external_writer))
            for job in jobs:
                job.result(timeout=30)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM products').fetchone()[0], 200)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM media_cache').fetchone()[0], 100)
        self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0], 'ok')

    def test_write_error_releases_lock_for_other_brands(self):
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute('INSERT INTO products(brand) VALUES(NULL)')
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            def other_writer():
                db = harvest.connect()
                try:
                    return harvest.save(db, self.run, 'lefties', self.product(), 'https://www.lefties.com/es/', 'fixture')
                finally:
                    db.close()
            self.assertTrue(pool.submit(other_writer).result(timeout=5))

    def test_normalization_preserves_price_size_and_stock(self):
        normalized = harvest.normalize('bershka', self.product(), 'https://www.bershka.com/es/')
        self.assertEqual(normalized['price'], 25.99)
        self.assertEqual(normalized['sizes'], ['M'])
        self.assertEqual(normalized['colors'], ['Negro'])
        self.assertEqual(normalized['availability'], 'in_stock')

    def test_manual_request_creates_new_snapshot_then_restart_resumes_it(self):
        self.db.execute('UPDATE runs SET started_at=?,config_json=? WHERE id=?', (harvest.now(), '{"adapter":"public_api_v2"}', self.run))
        self.db.commit()
        self.assertEqual(harvest.begin_run(self.db), self.run)
        marker = harvest.ROOT / 'force-new-run'
        marker.touch()
        fresh = harvest.begin_run(self.db)
        self.assertNotEqual(fresh, self.run)
        self.assertFalse(marker.exists())
        self.assertIn('manual', self.db.execute('SELECT config_json FROM runs WHERE id=?', (fresh,)).fetchone()[0])
        self.assertEqual(harvest.begin_run(self.db), fresh)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM runs').fetchone()[0], 2)


if __name__ == '__main__':
    unittest.main()
