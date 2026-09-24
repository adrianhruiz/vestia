import concurrent.futures
import pathlib
import sqlite3
import tempfile
import unittest
from unittest.mock import patch
import catalog_control as control


class CatalogControlTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = pathlib.Path(self.tmp.name)
        self.patches = [patch.object(control, 'ROOT', root), patch.object(control, 'MARKER', root / 'force-new-run'), patch.object(control, 'LOCK', root / 'control.lock')]
        for p in self.patches: p.start()
        with sqlite3.connect(root / 'catalog.sqlite3') as db:
            db.execute('CREATE TABLE runs(id INTEGER PRIMARY KEY,started_at TEXT,finished_at TEXT,status TEXT)')
            db.execute("INSERT INTO runs VALUES(1,'2026-09-24T10:00:00+00:00','2026-09-24T12:00:00+00:00','complete')")
        self.busy = False
        self.starts = 0
        self.systemctl_patch = patch.object(control, 'systemctl', side_effect=self.systemctl)
        self.systemctl_patch.start()

    def tearDown(self):
        self.systemctl_patch.stop()
        for p in self.patches: p.stop()
        self.tmp.cleanup()

    def systemctl(self, *args):
        if args[0] == 'show': return 'ActiveState='+('activating' if self.busy else 'inactive')+'\nSubState='+('start' if self.busy else 'dead')+'\n'
        if args[0] == 'start':
            self.starts += 1
            self.busy = True
        return ''

    def test_status_reports_actual_run_dates(self):
        status = control.status()
        self.assertFalse(status['busy'])
        self.assertEqual(status['last_run']['started_at'], '2026-09-24T10:00:00+00:00')
        self.assertEqual(status['last_finished']['finished_at'], '2026-09-24T12:00:00+00:00')

    def test_concurrent_clicks_only_start_once(self):
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            results = list(pool.map(lambda _: control.start(), range(4)))
        self.assertEqual(sum(r['accepted'] for r in results), 1)
        self.assertEqual(self.starts, 1)
        self.assertTrue(control.MARKER.exists())

    def test_active_collection_does_not_queue_another_run(self):
        self.busy = True
        result = control.start()
        self.assertFalse(result['accepted'])
        self.assertEqual(self.starts, 0)
        self.assertFalse(control.MARKER.exists())

    def test_start_failure_removes_force_marker(self):
        original = self.systemctl
        def fail(*args):
            if args[0] == 'start': raise RuntimeError('service failure')
            return original(*args)
        with patch.object(control, 'systemctl', side_effect=fail):
            with self.assertRaises(RuntimeError): control.start()
        self.assertFalse(control.MARKER.exists())

    def test_stopped_run_not_reported_as_running(self):
        with sqlite3.connect(control.ROOT / 'catalog.sqlite3') as db:
            db.execute("UPDATE runs SET status='running',finished_at=NULL")
        self.assertEqual(control.status()['last_run']['status'], 'interrupted')


if __name__ == '__main__': unittest.main()
