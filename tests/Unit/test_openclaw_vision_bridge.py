import base64
import http.server
import importlib.util
import io
import json
from pathlib import Path
import re
import tempfile
import threading
import time
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('vision_bridge', ROOT / 'bin/uploadiny-vision.py')
bridge = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(bridge)

TOKEN = 'a' * 64


class FakeGateway(http.server.BaseHTTPRequestHandler):
    """Records each request and answers with the reply the test configured."""

    def do_POST(self):
        length = int(self.headers['content-length'])
        self.server.requests.append({
            'path': self.path, 'headers': dict(self.headers), 'body': json.loads(self.rfile.read(length)),
        })
        if self.server.delay:
            time.sleep(self.server.delay)
        status, reply = self.server.reply
        payload = reply if isinstance(reply, bytes) else json.dumps(reply).encode()
        self.send_response(status)
        self.send_header('content-type', 'application/json')
        self.send_header('content-length', str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, *_):
        pass


class VisionBridgeTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.image = b'\xff\xd8\xffPRIVATE image bytes\xff\xd9'

        self.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), FakeGateway)
        self.server.daemon_threads = True
        self.server.requests, self.server.delay = [], 0
        self.server.reply = (200, self.completion('  A blue Save button.  '))
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
        self.write_config()
        self.addCleanup(patch.stopall)
        patch.object(bridge, 'GATEWAY_CONFIG', str(self.config_path)).start()

    def write_config(self):
        self.config_path.write_text(json.dumps(self.config))

    @staticmethod
    def completion(content, model='openclaw/uploadiny', finish='stop', choices=1):
        choice = {'index': 0, 'message': {'role': 'assistant', 'content': content}, 'finish_reason': finish}
        return {'id': 'chatcmpl-synthetic', 'object': 'chat.completion', 'model': model, 'choices': [choice] * choices}

    def test_only_the_prepared_bytes_reach_a_fresh_uploadiny_session_as_an_attached_image(self):
        self.assertEqual({'description': 'A blue Save button.', 'model': 'gpt-6-luna'}, bridge.describe(self.image))
        bridge.describe(self.image)

        first, second = self.server.requests
        self.assertEqual('/v1/chat/completions', first['path'])
        self.assertEqual(f'Bearer {TOKEN}', first['headers']['authorization'])
        body = first['body']
        self.assertEqual('openclaw/uploadiny', body['model'])
        self.assertIs(False, body['stream'])
        [message] = body['messages']
        self.assertEqual('user', message['role'])
        text, attached = message['content']
        self.assertEqual('text', text['type'])
        self.assertNotIn('PRIVATE', text['text'])
        self.assertEqual('image_url', attached['type'])
        self.assertEqual('data:image/jpeg;base64,' + base64.b64encode(self.image).decode(), attached['image_url']['url'])
        self.assertNotIn('x-openclaw-model', {key.lower() for key in first['headers']})

        pattern = re.compile(r'^agent:uploadiny:uploadiny-[0-9a-f-]{36}$')
        keys = [request['headers']['x-openclaw-session-key'] for request in (first, second)]
        self.assertTrue(all(pattern.match(key) for key in keys), keys)
        self.assertNotEqual(*keys)

    def test_a_route_that_is_not_luna_at_low_effort_is_refused_before_any_call(self):
        changes = {
            'old-model': lambda c: c['agents']['entries']['uploadiny'].update(model='openai/gpt-6.1-sol'),
            'other-effort': lambda c: c['agents']['entries']['uploadiny'].update(thinkingDefault='high'),
            'no-agent': lambda c: c['agents']['entries'].pop('uploadiny'),
            'no-token': lambda c: c['gateway']['auth'].pop('token'),
            'short-token': lambda c: c['gateway']['auth'].update(token='short'),
            'other-auth': lambda c: c['gateway']['auth'].update(mode='none'),
            'endpoint-off': lambda c: c['gateway']['http']['endpoints']['chatCompletions'].update(enabled=False),
            'bad-port': lambda c: c['gateway'].update(port='18789'),
        }
        original = json.loads(json.dumps(self.config))
        for name, change in changes.items():
            with self.subTest(name):
                self.config = json.loads(json.dumps(original))
                change(self.config)
                self.write_config()
                with self.assertRaises(bridge.DescriptionUnavailable): bridge.describe(self.image)
        self.assertEqual([], self.server.requests)

    def test_failed_foreign_incomplete_and_empty_replies_are_refused(self):
        replies = {
            'server-error': (500, {'error': 'failed'}),
            'unauthorized': (401, {'error': 'unauthorized'}),
            'other-agent': (200, self.completion('A blue Save button.', model='openclaw/main')),
            'cut-off': (200, self.completion('A blue Save', finish='length')),
            'two-choices': (200, self.completion('A blue Save button.', choices=2)),
            'blank': (200, self.completion('   ')),
            'no-content': (200, self.completion(None)),
        }
        for name, reply in replies.items():
            with self.subTest(name):
                self.server.reply = reply
                with self.assertRaises(bridge.DescriptionUnavailable): bridge.describe(self.image)
        self.server.reply = (200, b'not json')
        with self.assertRaises(ValueError): bridge.describe(self.image)

    def test_an_unanswered_turn_times_out(self):
        self.server.delay = 1
        with patch.object(bridge, 'TURN_TIMEOUT', 0.2):
            # socket.timeout before Python 3.10, TimeoutError after; both are OSError, which main() reports.
            with self.assertRaises(OSError): bridge.describe(self.image)

    def test_an_unavailable_gateway_fails_closed(self):
        self.server.shutdown()
        self.server.server_close()
        with self.assertRaises(OSError): bridge.describe(self.image)

    def test_empty_unsupported_and_oversized_input_never_contacts_the_gateway(self):
        for image in [b'', b'not a JPEG', b'\xff\xd8\xff' + b'x' * bridge.MAX_BYTES]:
            with self.assertRaises(bridge.DescriptionUnavailable): bridge.describe(image)
        self.assertEqual([], self.server.requests)

    def test_cli_failures_never_print_image_provider_or_filesystem_details(self):
        with patch.object(bridge.sys, 'argv', ['uploadiny-vision']), \
             patch.object(bridge.sys, 'stdin', type('Input', (), {'buffer': io.BytesIO(self.image)})()), \
             patch.object(bridge, 'describe', side_effect=OSError('PRIVATE path and credentials')), \
             patch.object(bridge.sys, 'stdout', new_callable=io.StringIO) as output, \
             patch.object(bridge.sys, 'stderr', new_callable=io.StringIO) as errors:
            self.assertEqual(1, bridge.main())
            self.assertEqual('', output.getvalue())
            self.assertEqual('Image description unavailable.\n', errors.getvalue())

    def test_cli_prints_one_json_line_for_a_described_image(self):
        with patch.object(bridge.sys, 'argv', ['uploadiny-vision']), \
             patch.object(bridge.sys, 'stdin', type('Input', (), {'buffer': io.BytesIO(self.image)})()), \
             patch.object(bridge.sys, 'stdout', new_callable=io.StringIO) as output:
            self.assertEqual(0, bridge.main())
        self.assertEqual({'description': 'A blue Save button.', 'model': 'gpt-6-luna'}, json.loads(output.getvalue()))


if __name__ == '__main__':
    unittest.main()
