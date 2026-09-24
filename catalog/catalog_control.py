#!/usr/bin/python3 -I
"""Fixed status/start commands for the authenticated Vestia catalog UI."""
import fcntl
import json
import os
import pathlib
import sqlite3
import subprocess
import sys

ROOT = pathlib.Path('/var/lib/inditex-catalog')
MARKER = ROOT / 'force-new-run'
LOCK = pathlib.Path('/run/vestia-catalog-control.lock')
UNIT = 'inditex-catalog.service'


def systemctl(*args):
    return subprocess.run(['/usr/bin/systemctl', *args], check=True, capture_output=True, text=True, timeout=10).stdout


def status():
    values = dict(line.split('=', 1) for line in systemctl('show', UNIT, '-p', 'ActiveState', '-p', 'SubState').splitlines() if '=' in line)
    busy = values.get('ActiveState') in ('active', 'activating', 'reloading', 'deactivating')
    with sqlite3.connect(f'file:{ROOT}/catalog.sqlite3?mode=ro', uri=True, timeout=5) as db:
        db.row_factory = sqlite3.Row
        last = db.execute('SELECT id,started_at,finished_at,status FROM runs ORDER BY id DESC LIMIT 1').fetchone()
        finished = db.execute('SELECT id,started_at,finished_at,status FROM runs WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 1').fetchone()
    last = dict(last) if last else None
    if last and last['status'] == 'running' and not busy:
        last['status'] = 'interrupted'
    return {'busy': busy, 'starting': busy and MARKER.exists(), 'retrying': values.get('SubState') == 'auto-restart',
            'last_run': last, 'last_finished': dict(finished) if finished else None}


def start():
    with LOCK.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        current = status()
        if current['busy']:
            return {**current, 'accepted': False, 'message': 'Ya hay una recopilación en curso.'}
        # The collector consumes this marker under its existing process lock.
        # A manual request starts a fresh snapshot, even within the same month.
        MARKER.unlink(missing_ok=True)
        fd = os.open(MARKER, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
        os.close(fd)
        try:
            systemctl('reset-failed', UNIT)
            systemctl('start', '--no-block', UNIT)
        except Exception:
            MARKER.unlink(missing_ok=True)
            raise
        current = status()
        return {**current, 'accepted': True, 'message': 'Recopilación iniciada. Puedes seguir usando el catálogo.'}


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ('status', 'start'):
        raise SystemExit(2)
    try:
        print(json.dumps(start() if sys.argv[1] == 'start' else status()))
    except Exception:
        print(json.dumps({'error': 'No se ha podido consultar o iniciar la recopilación.'}))
        raise SystemExit(1)


if __name__ == '__main__':
    main()
