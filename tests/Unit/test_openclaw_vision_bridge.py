import importlib.util
import io
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('vision_bridge', ROOT / 'bin/uploadiny-vision.py')
bridge = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(bridge)


class VisionBridgeTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.workspace = Path(self.directory.name)
        self.image = b'\xff\xd8\xffPRIVATE image bytes\xff\xd9'
        self.receipt = self.workspace / 'receipt.json'
        self.envelope = {
            'runId': 'synthetic-run', 'status': 'ok', 'summary': 'completed',
            'result': {
                'payloads': [{'text': '  A blue Save button.  '}],
                'meta': {
                    'agentMeta': {
                        'model': 'gpt-6-luna', 'provider': 'openai', 'agentHarnessId': 'codex',
                        'terminalReceipt': {'successfulToolNames': ['view_image'], 'rerouted': False},
                    },
                    'requestShaping': {'authMode': 'auth-profile', 'thinking': 'low'},
                },
            },
        }
        self.addCleanup(patch.stopall)
        patch.object(bridge, 'WORKSPACE', self.workspace).start()

    def cli(self, envelope=None, delay=False, exit_code=0):
        stub = self.workspace / 'cli.py'
        stub.write_text(
            'import json, os, pathlib, sys, time\n'
            'args = sys.argv[1:]\n'
            'prompt = pathlib.Path(args[args.index("--message-file") + 1])\n'
            'picture = prompt.parent / "screenshot.jpg"\n'
            f'pathlib.Path({str(self.receipt)!r}).write_text(json.dumps({{"args": args, '
            '"prompt": prompt.read_text(), "image": picture.read_bytes().hex(), "api_key_in_environment": "OPENAI_API_KEY" in os.environ, '
            '"config": os.environ.get("OPENCLAW_CONFIG_PATH")}))\n'
            + ('time.sleep(10)\n' if delay else '')
            + f'print("[state/agent-db] clean-close receipt")\nprint({json.dumps(envelope if envelope is not None else self.envelope)!r})\n'
            + f'sys.exit({exit_code})\n'
        )
        patch.object(bridge, 'OPENCLAW', [sys.executable, str(stub)]).start()

    def assert_clean(self):
        self.assertEqual([], list(self.workspace.glob('image-*')))

    def test_only_prepared_bytes_reach_a_fresh_uploadiny_turn_on_the_shared_gateway(self):
        self.cli()
        self.assertFalse(self.receipt.exists())

        self.assertEqual({'description': 'A blue Save button.', 'model': 'gpt-6-luna'}, bridge.describe(self.image))
        first = json.loads(self.receipt.read_text())
        self.assertEqual(self.image.hex(), first['image'])
        args = first['args']
        self.assertEqual('uploadiny', args[args.index('--agent') + 1])
        self.assertNotIn('--local', args)
        self.assertNotIn('--model', args)
        self.assertEqual('60', args[args.index('--timeout') + 1])
        self.assertIn('--json', args)
        self.assertEqual('/home/olloma-agent/.openclaw/gateway.json', first['config'])
        self.assertNotIn('PRIVATE', first['prompt'])
        self.assertNotIn('--deliver', args)
        self.assertFalse(first['api_key_in_environment'])
        self.assert_clean()

        bridge.describe(self.image)
        second = json.loads(self.receipt.read_text())['args']
        self.assertNotEqual(args[args.index('--session-id') + 1], second[second.index('--session-id') + 1])
        self.assert_clean()

    def test_uninspected_images_wrong_models_and_empty_answers_are_refused(self):
        outcomes = ['no-image', 'wrong-model', 'wrong-provider', 'wrong-runtime', 'rerouted', 'empty',
                    'wrong-thinking', 'no-thinking', 'local-envelope', 'no-result']
        for outcome in outcomes:
            with self.subTest(outcome=outcome):
                envelope = json.loads(json.dumps(self.envelope))
                result = envelope['result']
                meta = result['meta']['agentMeta']
                if outcome == 'no-image': meta['terminalReceipt']['successfulToolNames'] = []
                if outcome == 'wrong-model': meta['model'] = 'gpt-6-astra'
                if outcome == 'wrong-provider': meta['provider'] = 'anthropic'
                if outcome == 'wrong-runtime': meta['agentHarnessId'] = 'openclaw'
                if outcome == 'rerouted': meta['terminalReceipt']['rerouted'] = True
                if outcome == 'empty': result['payloads'] = [{'text': '   '}]
                if outcome == 'wrong-thinking': result['meta']['requestShaping']['thinking'] = 'xhigh'
                if outcome == 'no-thinking': del result['meta']['requestShaping']
                if outcome == 'local-envelope': envelope = result
                if outcome == 'no-result': del envelope['result']
                self.cli(envelope)
                with self.assertRaises(bridge.DescriptionUnavailable): bridge.describe(self.image)
                self.assert_clean()

    def test_failure_and_timeout_remove_private_previews(self):
        self.cli(exit_code=1)
        with self.assertRaises(bridge.DescriptionUnavailable): bridge.describe(self.image)
        self.assert_clean()
        self.cli(delay=True)
        with patch.object(bridge, 'PROCESS_TIMEOUT', 0.15):
            with self.assertRaises(subprocess.TimeoutExpired): bridge.describe(self.image)
        self.assert_clean()

    def test_empty_unsupported_and_oversized_input_never_starts_openclaw(self):
        with patch.object(bridge.subprocess, 'Popen') as start:
            for image in [b'', b'not a JPEG', b'\xff\xd8\xff' + b'x' * bridge.MAX_BYTES]:
                with self.assertRaises(bridge.DescriptionUnavailable): bridge.describe(image)
            start.assert_not_called()
        self.assert_clean()

    def test_timeout_stops_descendants_after_the_launcher_has_already_exited(self):
        ready = self.workspace / 'child-ready'
        stopped = self.workspace / 'child-stopped'
        child = self.workspace / 'child.py'
        child.write_text(
            'import pathlib, signal, sys, time\n'
            f'def stop(*_):\n pathlib.Path({str(stopped)!r}).write_text("stopped"); sys.exit(0)\n'
            'signal.signal(signal.SIGTERM, stop)\n'
            f'pathlib.Path({str(ready)!r}).write_text("ready")\n'
            'time.sleep(30)\n'
        )
        launcher = self.workspace / 'launcher.py'
        launcher.write_text(
            'import pathlib, subprocess, sys, time\n'
            f'p = subprocess.Popen([sys.executable, "-S", {str(child)!r}])\n'
            f'pathlib.Path({str(self.receipt)!r}).write_text(str(p.pid))\n'
            f'while not pathlib.Path({str(ready)!r}).exists(): time.sleep(0.01)\n'
        )
        self.assertFalse(stopped.exists())
        start = subprocess.Popen

        def exited_launcher(*args, **kwargs):
            process = start(*args, **kwargs)
            deadline = time.monotonic() + 20
            while process.poll() is None and time.monotonic() < deadline:
                time.sleep(0.01)
            self.assertIsNotNone(process.poll(), 'The launcher must exit before the timed wait.')
            self.assertTrue(ready.exists(), 'The descendant must be ready before timeout.')
            return process

        with patch.object(bridge, 'OPENCLAW', [sys.executable, '-S', str(launcher)]), \
             patch.object(bridge, 'PROCESS_TIMEOUT', 0.2), \
             patch.object(bridge.subprocess, 'Popen', side_effect=exited_launcher):
            try:
                with self.assertRaises(subprocess.TimeoutExpired): bridge.describe(self.image)
                self.assertTrue(ready.exists(), 'The descendant must be ready before timeout.')
                self.assertEqual('stopped', stopped.read_text() if stopped.exists() else None)
                self.assert_clean()
            finally:
                if self.receipt.exists() and not stopped.exists():
                    try: os.kill(int(self.receipt.read_text()), signal.SIGKILL)
                    except ProcessLookupError: pass

    def test_cli_failures_never_print_image_provider_or_filesystem_details(self):
        with patch.object(bridge.sys, 'argv', ['uploadiny-vision']), \
             patch.object(bridge.sys, 'stdin', type('Input', (), {'buffer': io.BytesIO(self.image)})()), \
             patch.object(bridge, 'describe', side_effect=OSError('PRIVATE path and credentials')), \
             patch.object(bridge.sys, 'stdout', new_callable=io.StringIO) as output, \
             patch.object(bridge.sys, 'stderr', new_callable=io.StringIO) as errors:
            self.assertEqual(1, bridge.main())
            self.assertEqual('', output.getvalue())
            self.assertEqual('Image description unavailable.\n', errors.getvalue())


if __name__ == '__main__':
    unittest.main()
