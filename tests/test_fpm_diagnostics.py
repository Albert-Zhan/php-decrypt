"""Check failure diagnostics without starting PHP or an FPM pool."""
import importlib.util
from pathlib import Path
import unittest


spec = importlib.util.spec_from_file_location('decrypt_fpm', Path(__file__).resolve().parents[1] / 'tools/test-fpm.py')
fpm = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fpm)


class FpmDiagnostics(unittest.TestCase):
    def test_valid_response(self):
        self.assertEqual(fpm.response_json(('Content-Type: application/json', '{"pid":123}', '')), {'pid': 123})

    def test_concurrent_warning_preserves_response(self):
        response = ('Content-Type: application/json', '{"pid":123}', 'PHP Warning: capture failed')
        with self.assertRaises(RuntimeError) as error:
            fpm.response_json(response)
        self.assertIn(repr(response), str(error.exception))

    def test_expected_warning_can_be_allowed(self):
        response = ('Content-Type: application/json', '{"pid":123}', 'PHP Warning: expected fixture failure')
        self.assertEqual(fpm.response_json(response, allow_warning=True), {'pid': 123})

    def test_http_failure_is_never_allowed(self):
        response = ('Status: 500 Internal Server Error', '{"pid":123}', '')
        with self.assertRaises(RuntimeError) as error:
            fpm.response_json(response, allow_warning=True)
        self.assertIn(repr(response), str(error.exception))

    def test_invalid_json_preserves_response(self):
        response = ('Content-Type: text/html', 'unexpected application output', '')
        with self.assertRaises(RuntimeError) as error:
            fpm.response_json(response)
        self.assertIn(repr(response), str(error.exception))


if __name__ == '__main__':
    unittest.main()
