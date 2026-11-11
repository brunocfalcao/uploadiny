import http.server
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('correction_bridge', ROOT / 'bin/uploadiny-correct.py')
bridge = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(bridge)

TOKEN = 'b' * 64


class FakeGateway(http.server.BaseHTTPRequestHandler):
    """Records each request and answers with the reply the test configured."""

    def do_POST(self):
        length = int(self.headers['content-length'])
        self.server.requests.append({'path': self.path, 'headers': dict(self.headers), 'body': json.loads(self.rfile.read(length))})
        status, reply = self.server.reply
        payload = json.dumps(reply).encode()
        self.send_response(status)
        self.send_header('content-type', 'application/json')
        self.send_header('content-length', str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, *_):
        pass


class CorrectionBridgeTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), FakeGateway)
        self.server.daemon_threads = True
        self.server.requests = []
        self.server.reply = (200, self.completion("  Canton doesn't appear.  "))
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        self.config_path = Path(self.directory.name) / 'gateway.json'
        self.config = {
            'gateway': {
                'port': self.server.server_address[1],
                'auth': {'mode': 'token', 'token': TOKEN},
                'http': {'endpoints': {'chatCompletions': {'enabled': True}}},
            },
            'agents': {'entries': {'uploadiny': {'model': 'openai/gpt-6-luna', 'thinkingDefault': 'low'}}},
        }
        self.config_path.write_text(json.dumps(self.config))
        self.addCleanup(patch.stopall)
        patch.object(bridge, 'GATEWAY_CONFIG', str(self.config_path)).start()

    @staticmethod
    def completion(content, model='openclaw/uploadiny', finish='stop', choices=1):
        choice = {'index': 0, 'message': {'role': 'assistant', 'content': content}, 'finish_reason': finish}
        return {'id': 'chatcmpl-synthetic', 'object': 'chat.completion', 'model': model, 'choices': [choice] * choices}

    def run_bridge(self, request, argv=('uploadiny-correct.py',)):
        stdin = io.TextIOWrapper(io.BytesIO(json.dumps(request).encode()))
        stdout, stderr = io.StringIO(), io.StringIO()
        with patch.object(bridge.sys, 'argv', list(argv)), patch.object(bridge.sys, 'stdin', stdin), \
                patch.object(bridge.sys, 'stdout', stdout), patch.object(bridge.sys, 'stderr', stderr):
            status = bridge.main()
        return status, stdout.getvalue(), stderr.getvalue()

    def test_text_reaches_a_fresh_uploadiny_session_and_the_trimmed_correction_returns(self):
        request = {'feedback': 'Cantonese doesnt appear.', 'project': {'name': 'Taxiny', 'description': 'Tax deductions'}}
        self.assertEqual(self.run_bridge(request)[0], 0)
        self.assertEqual(self.run_bridge(request)[0], 0)

        status, output, _ = self.run_bridge(request)
        self.assertEqual(status, 0)
        self.assertEqual(json.loads(output), {'text': "Canton doesn't appear.", 'model': 'gpt-6-luna'})
        sessions = {r['headers']['x-openclaw-session-key'] for r in self.server.requests}
        self.assertEqual(len(sessions), 3)
        sent = self.server.requests[0]
        self.assertEqual(sent['path'], '/v1/chat/completions')
        self.assertEqual(sent['headers']['authorization'], f'Bearer {TOKEN}')
        self.assertEqual(sent['body']['model'], 'openclaw/uploadiny')
        prompt = sent['body']['messages'][0]['content']
        self.assertIn('"feedback": "Cantonese doesnt appear."', prompt)
        self.assertIn('"name": "Taxiny"', prompt)

    def test_unverified_route_and_foreign_or_incomplete_answers_fail_closed_without_private_output(self):
        request = {'feedback': 'PRIVATE dictated note', 'project': {}}
        cases = [
            (200, self.completion('ok', model='openclaw/other')),
            (200, self.completion('ok', finish='length')),
            (200, self.completion('ok', choices=2)),
            (200, self.completion('   ')),
            (500, {'error': 'PRIVATE upstream'}),
        ]
        for reply in cases:
            self.server.reply = reply
            status, output, error = self.run_bridge(request)
            self.assertEqual((status, output), (1, ''))
            self.assertEqual(error.strip(), 'Comment correction unavailable.')

        self.config['agents']['entries']['uploadiny']['thinkingDefault'] = 'high'
        self.config_path.write_text(json.dumps(self.config))
        sent = len(self.server.requests)
        self.assertEqual(self.run_bridge(request)[0], 1)
        self.assertEqual(len(self.server.requests), sent)

    def test_arguments_and_invalid_requests_are_refused_before_any_gateway_call(self):
        self.assertEqual(self.run_bridge({'feedback': 'x'}, argv=('uploadiny-correct.py', '--x'))[0], 1)
        self.assertEqual(self.run_bridge({'feedback': '   '})[0], 1)
        self.assertEqual(self.run_bridge({'project': {}})[0], 1)
        self.assertEqual(self.server.requests, [])

    def test_unavailable_gateway_fails_closed(self):
        self.server.shutdown()
        self.server.server_close()
        self.assertEqual(self.run_bridge({'feedback': 'Menu doesnt load.'})[0], 1)


if __name__ == '__main__':
    unittest.main()
