"""Exercise the shipped Nginx config with real HTTPS, FastCGI and sync proxies in Docker."""
import http.client
import json
import os
from pathlib import Path
import socket
import socketserver
import ssl
import struct
import subprocess
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

DEPLOY = Path(__file__).resolve().parent
HOST = 'equi-app.staging.optimize-it.nl'
SITE = '/home/ploi/' + HOST


def port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


class FastCGI(socketserver.StreamRequestHandler):
    def handle(self):
        params = b''
        body = b''
        request_id = 1
        while True:
            header = self.rfile.read(8)
            if len(header) != 8:
                return
            _, kind, request_id, length, padding, _ = struct.unpack('!BBHHBB', header)
            data = self.rfile.read(length)
            self.rfile.read(padding)
            if kind == 4:
                params += data
            if kind == 5:
                body += data
                if not length:
                    break
        fields = {}
        cursor = 0
        def size():
            nonlocal cursor
            result = params[cursor]
            if result & 128:
                result = struct.unpack('!I', params[cursor:cursor+4])[0] & 0x7fffffff
                cursor += 4
            else:
                cursor += 1
            return result
        while cursor < len(params):
            key_size, value_size = size(), size()
            key = params[cursor:cursor+key_size].decode()
            cursor += key_size
            fields[key] = params[cursor:cursor+value_size].decode()
            cursor += value_size
        output = ('Content-Type: application/json\r\n\r\n' + json.dumps({
            'uri': fields.get('REQUEST_URI'), 'https': fields.get('HTTPS'),
            'script': fields.get('SCRIPT_FILENAME'), 'method': fields.get('REQUEST_METHOD'),
            'body': body.decode(),
        })).encode()
        def record(kind, content):
            self.wfile.write(struct.pack('!BBHHBB', 1, kind, request_id, len(content), 0, 0) + content)
        record(6, output)
        record(6, b'')
        record(3, b'\0' * 8)


class Sync(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.headers.get('Upgrade') == 'websocket':
            self.send_response(101)
            self.send_header('Upgrade', 'websocket')
            self.send_header('Connection', 'Upgrade')
        else:
            self.send_response(200)
        self.end_headers()
        if self.headers.get('Upgrade') != 'websocket':
            self.wfile.write(('sync:' + self.path).encode())
    def log_message(self, *args):
        pass


class NginxTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='equi-nginx-')
        cls.root = Path(cls.temp.name)
        cls.root.chmod(0o755)
        cls.http, cls.https = port(), port()
        cls.fcgi = socketserver.ThreadingTCPServer(('127.0.0.1', 0), FastCGI)
        cls.sync = ThreadingHTTPServer(('127.0.0.1', 0), Sync)
        for server in [cls.fcgi, cls.sync]:
            threading.Thread(target=server.serve_forever, daemon=True).start()
        site = cls.root / 'site/current'
        for directory in ['public/build', 'public/storage', 'public/.well-known/acme-challenge',
                          'web-dist/powersync/worker', 'web-dist/_expo/static/js/web']:
            (site / directory).mkdir(parents=True, exist_ok=True)
        for name, content in {
            'public/index.php': '<?php backend;', 'public/build/admin.js': 'admin asset',
            'public/storage/image.jpg': 'media', 'public/storage/attack.php': 'private code',
            'public/.env': 'SECRET', 'public/.well-known/acme-challenge/token': 'challenge',
            'web-dist/index.html': '<html>customer SPA</html>',
            'web-dist/_expo/static/js/web/app.js': 'customer asset',
            'web-dist/powersync/worker/worker.js': 'sync worker',
            'web-dist/powersync/sqlite.wasm': 'wasm',
        }.items():
            (site / name).write_text(content)
        snippets = cls.root / 'ploi'
        for name in ['before', 'server', 'after']:
            (snippets / name).mkdir(parents=True)
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                        '-subj', f'/CN={HOST}', '-keyout', str(cls.root/'key.pem'),
                        '-out', str(cls.root/'cert.pem')], check=True, capture_output=True)
        (cls.root/'tls.conf').write_text(f'listen {cls.https} ssl;\nlisten [::]:{cls.https} ssl;\n'
            f'ssl_certificate {cls.root}/cert.pem;\nssl_certificate_key {cls.root}/key.pem;\n')
        # Include Ploi's real redirect shape to catch duplicate-server/precedence errors.
        (snippets/'before/ssl-redirect.conf').write_text(f'server {{ listen {cls.http}; server_name .{HOST}; return 301 https://$host$request_uri; }}')
        (snippets/'server/acme.conf').write_text('location /.well-known/acme-challenge/ { allow all; auth_basic off; default_type text/plain; }')
        config = (DEPLOY/'nginx-staging.conf').read_text()
        config = config.replace(f'/etc/nginx/ploi/{HOST}', str(snippets)).replace(f'/etc/nginx/ssl/{HOST}', str(cls.root/'tls.conf'))
        config = config.replace(SITE, str(cls.root/'site')).replace('listen 80;', f'listen {cls.http};').replace('listen [::]:80;', f'listen [::]:{cls.http};')
        config = config.replace('unix:/run/php/php8.5-fpm.sock', f'127.0.0.1:{cls.fcgi.server_address[1]}')
        config = config.replace('127.0.0.1:8080', f'127.0.0.1:{cls.sync.server_address[1]}')
        config = config.replace(f'/var/log/nginx/{HOST}-error.log', '/dev/stderr')
        (cls.root/'nginx.conf').write_text('events {}\nhttp { include /etc/nginx/mime.types;\n' + config + '\n}\n')
        command = ['docker', 'run', '--rm', '--network', 'host', '-v', f'{cls.root}:{cls.root}:ro', 'nginx:1.27-alpine', 'nginx', '-c', str(cls.root/'nginx.conf')]
        test = subprocess.run(command + ['-t'], capture_output=True, text=True)
        if test.returncode or 'conflicting server name' in test.stderr:
            raise RuntimeError(test.stdout + test.stderr)
        cls.container = subprocess.check_output(command[:2] + ['-d'] + command[2:] + ['-g', 'daemon off;'], text=True).strip()
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1', cls.https), timeout=.2):
                    break
            except OSError:
                time.sleep(.1)

    @classmethod
    def tearDownClass(cls):
        if hasattr(cls, 'container'):
            subprocess.run(['docker', 'stop', cls.container], capture_output=True)
        cls.fcgi.shutdown()
        cls.sync.shutdown()
        cls.temp.cleanup()

    def request(self, path, secure=True, method='GET', body=None, headers=None):
        conn = http.client.HTTPSConnection('127.0.0.1', self.https, context=ssl._create_unverified_context(), timeout=5) if secure else http.client.HTTPConnection('127.0.0.1', self.http, timeout=5)
        conn.request(method, path, body=body, headers={'Host': HOST, **(headers or {})})
        response = conn.getresponse()
        result = response.status, dict(response.getheaders()), response.read().decode()
        conn.close()
        return result

    def test_http_redirect_preserves_path_and_query_for_both_apps(self):
        for path in ['/', '/admin/login?next=orders', '/protocol?tab=kalender']:
            status, headers, _ = self.request(path, secure=False)
            self.assertEqual(status, 308)
            self.assertEqual(headers['Location'], 'https://' + HOST + path)

    def test_acme_is_available_over_http(self):
        self.assertEqual(self.request('/.well-known/acme-challenge/token', secure=False)[::2], (200, 'challenge'))

    def test_web_entry_and_deep_links(self):
        for path in ['/', '/protocol', '/library/selection/hay-analysis', '/onboarding/welcome']:
            status, _, body = self.request(path)
            self.assertEqual((status, body), (200, '<html>customer SPA</html>'))

    def test_laravel_routes_keep_uri_method_body_and_https(self):
        for path in ['/admin/login?next=orders', '/api/horses/test/dashboard', '/.well-known/jwks.json', '/up', '/web-session/token']:
            status, _, body = self.request(path, method='POST', body='hello=world')
            self.assertEqual(status, 200)
            data = json.loads(body)
            self.assertEqual(data['uri'], path)
            self.assertEqual(data['method'], 'POST')
            self.assertEqual(data['body'], 'hello=world')
            self.assertEqual(data['https'], 'on')
            self.assertTrue(data['script'].endswith('/public/index.php'))

    def test_assets_media_and_wasm_are_not_spa_fallbacks(self):
        for path, body in [('/build/admin.js', 'admin asset'), ('/storage/image.jpg', 'media'),
                           ('/_expo/static/js/web/app.js', 'customer asset'), ('/powersync/worker/worker.js', 'sync worker')]:
            self.assertEqual(self.request(path)[::2], (200, body))
        status, headers, body = self.request('/powersync/sqlite.wasm')
        self.assertEqual((status, body), (200, 'wasm'))
        self.assertEqual(headers['Content-Type'], 'application/wasm')
        for path in ['/missing.js', '/build/missing.js', '/powersync/worker/missing.js']:
            self.assertEqual(self.request(path)[0], 404)

    def test_php_and_private_paths_are_not_served_as_static_content(self):
        for path in ['/.env', '/storage/attack.php', '/storage/attack.php/extra', '/storage/.env']:
            status, _, body = self.request(path)
            self.assertIn(status, (403, 404))
            self.assertNotIn('SECRET', body)
            self.assertNotIn('private code', body)

    def test_sync_strips_prefix_and_supports_websocket_upgrade(self):
        self.assertEqual(self.request('/powersync/checkpoint?client=web')[::2], (200, 'sync:/checkpoint?client=web'))
        status, headers, _ = self.request('/powersync/sync/stream', headers={'Upgrade': 'websocket', 'Connection': 'Upgrade'})
        self.assertEqual(status, 101)
        self.assertEqual(headers['Upgrade'], 'websocket')

if __name__ == '__main__':
    unittest.main()
