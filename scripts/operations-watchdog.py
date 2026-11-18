"""Independent read-only application check and stale-check watchdog for systemd.

This process does not use queues or the application's notification transports.
It emits bounded structured status to journald and a private runtime heartbeat;
an external monitoring receiver must observe exit failures and watchdog absence.
"""

import argparse
import json
import os
import pathlib
import subprocess
import tempfile
import time


def run_check(command, timeout=10):
    started = time.time()
    try:
        completed = subprocess.run(command, cwd=pathlib.Path(__file__).resolve().parents[1],
                                   capture_output=True, text=True, timeout=timeout, check=False)
        try:
            observation = json.loads(completed.stdout)
            if observation.get('status') not in ['ok', 'warning', 'critical']:
                raise ValueError('Invalid observation')
        except (ValueError, AttributeError):
            observation = {'status': 'critical', 'code': 'invalid_check_output'}
        return {'finished_at': time.time(), 'started_at': started, 'exit_code': completed.returncode,
                'timed_out': False, 'observation': observation}
    except subprocess.TimeoutExpired:
        return {'finished_at': time.time(), 'started_at': started, 'exit_code': 124,
                'timed_out': True, 'observation': {'status': 'critical', 'code': 'check_timeout'}}
    except OSError:
        return {'finished_at': time.time(), 'started_at': started, 'exit_code': 127,
                'timed_out': False, 'observation': {'status': 'critical', 'code': 'check_cannot_start'}}


def inspect_heartbeat(path, now=None):
    now = time.time() if now is None else now
    try:
        value = json.loads(path.read_text())
        age = now - float(value['finished_at'])
        if age > 90 or age < -30:
            return {'status': 'critical', 'code': 'check_heartbeat_stale', 'age_seconds': int(age)}
        if value['observation']['status'] == 'warning' and value['exit_code'] == 1:
            return {'status': 'warning', 'code': 'check_reported_warning', 'age_seconds': int(age)}
        if value['exit_code'] != 0 or value['observation']['status'] != 'ok':
            return {'status': 'critical', 'code': 'check_reported_failure', 'age_seconds': int(age)}
        return {'status': 'ok', 'age_seconds': int(age)}
    except (OSError, ValueError, KeyError, TypeError):
        return {'status': 'critical', 'code': 'check_heartbeat_missing_or_invalid'}


def write_heartbeat(path, value):
    descriptor, temporary = tempfile.mkstemp(prefix='.check-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'w') as handle:
            json.dump(value, handle)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, 0o640)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['check', 'watch'])
    parser.add_argument('--state', type=pathlib.Path, default=pathlib.Path('/run/cloudcms-operations/check.json'))
    args = parser.parse_args()
    if args.mode == 'check':
        repo = pathlib.Path(__file__).resolve().parents[1]
        result = run_check(['/www/server/php/84/bin/php', str(repo / 'artisan'), 'app:operations-check', '--json'])
        try:
            write_heartbeat(args.state, result)
        except OSError:
            print(json.dumps({'status': 'critical', 'code': 'check_heartbeat_write_failed'}), flush=True)
            raise SystemExit(2)
        print(json.dumps(result), flush=True)
        if result['exit_code'] == 0 and result['observation']['status'] == 'ok':
            raise SystemExit(0)
        raise SystemExit(1 if result['exit_code'] == 1 and result['observation']['status'] == 'warning' else 2)
    result = inspect_heartbeat(args.state)
    print(json.dumps(result), flush=True)
    raise SystemExit({'ok': 0, 'warning': 1, 'critical': 2}[result['status']])


if __name__ == '__main__':
    main()
