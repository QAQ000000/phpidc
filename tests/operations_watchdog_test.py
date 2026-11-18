"""Offline tests for the independent check timeout and stale-heartbeat boundary."""

import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location('operations_watchdog', pathlib.Path(__file__).resolve().parents[1] / 'scripts/operations-watchdog.py')
watchdog = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(watchdog)


class OperationsWatchdogTest(unittest.TestCase):
    def test_stopped_check_is_detected_even_without_application_workers(self):
        with tempfile.TemporaryDirectory() as folder:
            path = pathlib.Path(folder) / 'check.json'
            self.assertEqual(watchdog.inspect_heartbeat(path)['status'], 'critical')
            watchdog.write_heartbeat(path, {'finished_at': 1000, 'exit_code': 0, 'observation': {'status': 'ok'}})
            self.assertEqual(watchdog.inspect_heartbeat(path, now=1089)['status'], 'ok')
            self.assertEqual(watchdog.inspect_heartbeat(path, now=1091)['code'], 'check_heartbeat_stale')
            self.assertEqual(path.stat().st_mode & 0o777, 0o640)

    def test_explicit_failed_check_is_detected_without_using_outbox(self):
        with tempfile.TemporaryDirectory() as folder:
            path = pathlib.Path(folder) / 'check.json'
            watchdog.write_heartbeat(path, {'finished_at': 1000, 'exit_code': 2, 'observation': {'status': 'critical'}})
            self.assertEqual(watchdog.inspect_heartbeat(path, now=1001)['code'], 'check_reported_failure')

    def test_warning_is_preserved_until_staleness_requires_escalation(self):
        with tempfile.TemporaryDirectory() as folder:
            path = pathlib.Path(folder) / 'check.json'
            watchdog.write_heartbeat(path, {'finished_at': 1000, 'exit_code': 1, 'observation': {'status': 'warning'}})
            self.assertEqual(watchdog.inspect_heartbeat(path, now=1001)['status'], 'warning')
            self.assertEqual(watchdog.inspect_heartbeat(path, now=1091)['status'], 'critical')

    def test_hung_check_is_bounded_and_becomes_an_independent_failure(self):
        result = watchdog.run_check([sys.executable, '-c', 'import time; time.sleep(1)'], timeout=0.03)
        self.assertTrue(result['timed_out'])
        self.assertEqual(result['exit_code'], 124)
        self.assertEqual(result['observation']['code'], 'check_timeout')

    def test_raw_errors_are_not_forwarded_to_the_monitor(self):
        result = watchdog.run_check([sys.executable, '-c', 'import sys; print("PRIVATE-SECRET"); sys.exit(3)'])
        self.assertEqual(result['observation']['code'], 'invalid_check_output')
        self.assertNotIn('PRIVATE-', json.dumps(result))

    def test_check_cannot_start_and_malformed_heartbeat_are_visible(self):
        result = watchdog.run_check(['/nonexistent/offline-check'])
        self.assertEqual(result['observation']['code'], 'check_cannot_start')
        with tempfile.TemporaryDirectory() as folder:
            path = pathlib.Path(folder) / 'check.json'
            path.write_text('not-json')
            self.assertEqual(watchdog.inspect_heartbeat(path)['code'], 'check_heartbeat_missing_or_invalid')


if __name__ == '__main__':
    unittest.main()
