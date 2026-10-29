#!/usr/bin/env python3
"""Exercise real Autodiscover HTTP, Rust authentication and persistent startup.

Uses dummy credentials and an isolated loopback JMAP server. Never contacts a
real account or changes the host proxy, firewall or existing containers.
"""
import argparse
import base64
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import socket
import subprocess
import threading
import time
import urllib.error
import urllib.request
import uuid
import xml.etree.ElementTree as ET


class JmapFixture(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        expected = 'Basic ' + base64.b64encode(b'dummy@example.test:test-only').decode()
        if self.headers.get('Authorization') != expected:
            self.send_response(401)
            self.end_headers()
            return
        base = f'http://127.0.0.1:{self.server.server_port}'
        body = json.dumps({'apiUrl': base + '/jmap', 'uploadUrl': base + '/upload/{accountId}',
                           'downloadUrl': base + '/download/{accountId}/{blobId}/{name}',
                           'primaryAccounts': {'urn:ietf:params:jmap:mail': 'a'}}).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def run(*args, **kwargs):
    return subprocess.run(args, check=True, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=45, **kwargs).stdout


def check(condition, description):
    if not condition:
        raise AssertionError(description)
    print('PASS: ' + description, flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--image', required=True)
    image = parser.parse_args().image
    root = Path(__file__).resolve().parents[1]
    fixture = ThreadingHTTPServer(('127.0.0.1', 0), JmapFixture)
    thread = threading.Thread(target=fixture.serve_forever, daemon=True)
    thread.start()
    name = 'zpush-autodiscover-' + uuid.uuid4().hex[:12]
    volume = name + '-config'
    run('docker', 'volume', 'create', volume)

    def stop():
        subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL, timeout=30)

    def start(public_host):
        stop()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        run('docker', 'run', '-d', '--name', name, '--network', 'host',
            '-v', volume + ':/data/config',
            '-e', 'STALWART_URL=http://127.0.0.1:' + str(fixture.server_port),
            '-e', 'ZPUSH_BIND_ADDRESS=127.0.0.1', '-e', 'ZPUSH_HTTP_PORT=' + str(port),
            '-e', 'ZPUSH_HOST=' + public_host, image)
        for _ in range(100):
            try:
                with urllib.request.urlopen(f'http://127.0.0.1:{port}/healthz', timeout=1):
                    return port
            except (OSError, urllib.error.URLError):
                time.sleep(0.1)
        raise RuntimeError('Autodiscover test container did not start: ' +
                           run('docker', 'logs', name).decode(errors='replace')[-2000:])

    def post(port, password='test-only', authenticated=True):
        data = (b'<?xml version="1.0"?><Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/mobilesync/requestschema/2006">'
                b'<Request><EMailAddress>dummy@example.test</EMailAddress>'
                b'<AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006</AcceptableResponseSchema>'
                b'</Request></Autodiscover>')
        headers = {'Content-Type': 'text/xml', 'Host': 'discovery.example.test'}
        if authenticated:
            headers['Authorization'] = 'Basic ' + base64.b64encode(('dummy@example.test:' + password).encode()).decode()
        request = urllib.request.Request(f'http://127.0.0.1:{port}/Autodiscover/Autodiscover.xml',
                                         data=data, headers=headers)
        try:
            with urllib.request.urlopen(request, timeout=10) as response:
                return response.status, response.read()
        except urllib.error.HTTPError as error:
            return error.code, error.read()

    def endpoint(port, expected):
        status, body = post(port)
        check(status == 200, 'Authenticated XML POST succeeds through real PHP and Rust')
        urls = [node.text for node in ET.fromstring(body).iter() if node.tag.endswith('}Url')]
        check(urls == ['https://' + expected + '/Microsoft-Server-ActiveSync'],
              'Autodiscover advertises the expected HTTPS endpoint: ' + expected)

    try:
        port = start('sync.example.test')
        endpoint(port, 'sync.example.test')
        check(post(port, authenticated=False)[0] == 401, 'Unauthenticated XML POST is rejected')
        check(post(port, password='wrong-password')[0] == 401, 'Incorrect credentials are rejected')
        check(run('docker', 'exec', name, 'readlink', '/var/www/zpush/autodiscover/config.php').decode().strip()
              == '/data/config/autodiscover-config.php', 'Autodiscover uses the persistent configuration symlink')
        run('docker', 'exec', name, 'php', '-r',
            "file_put_contents('/data/config/autodiscover-config.php', \"\n// persistent-autodiscover-test\n\", FILE_APPEND);")
        port = start('sync.example.test:8443')
        endpoint(port, 'sync.example.test:8443')
        check(b'persistent-autodiscover-test' in run('docker', 'exec', name, 'cat', '/data/config/autodiscover-config.php'),
              'Container recreation preserves existing configuration and restores its symlink')
        port = start('')
        endpoint(port, 'discovery.example.test')
        check(run('docker', 'exec', name, 'php', '-r',
                  "chdir('/var/www/zpush'); require 'vendor/autoload.php'; require '/data/config/autodiscover-config.php'; echo defined('ZPUSH_HOST') ? 'set' : 'unset';") == b'unset',
              'Removing the environment override clears a stale persisted hostname')
        for public_host in ('127.0.0.1:8443', '[::1]:8443'):
            endpoint(start(public_host), public_host)
        stop()
        digest = run('docker', 'run', '--rm', '--network', 'none', '--entrypoint', 'sha256sum',
                     '-v', volume + ':/data/config', image, '/data/config/autodiscover-config.php')
        invalid = ["sync.example.test');phpinfo();//", 'sync.example.test#injection',
                   'sync.example.test&other', '$(id).example.test', 'sync.example.test\nother',
                   'sync.example.test\r\nother', 'https://sync.example.test', 'sync.example.test/path',
                   'user@sync.example.test', 'sync.example.test:0', 'sync.example.test:65536',
                   '-bad.example.test', 'bad-.example.test', 'bad_name.example.test',
                   'a' * 64 + '.example.test', '999.999.999.999', '[:::1]', 'sync.example.test other']
        for value in invalid:
            result = subprocess.run(['docker', 'run', '--rm', '--network', 'none',
                                     '-v', volume + ':/data/config', '-e', 'ZPUSH_HOST=' + value, image],
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20)
            check(result.returncode != 0 and b'Invalid ZPUSH_HOST' in result.stderr,
                  'Invalid hostname is rejected before startup: ' + repr(value))
        check(digest == run('docker', 'run', '--rm', '--network', 'none', '--entrypoint', 'sha256sum',
                            '-v', volume + ':/data/config', image, '/data/config/autodiscover-config.php'),
              'Rejected hostnames leave persistent configuration untouched')
        env = dict(os.environ, ZPUSH_HOST='sync.example.test:8443')
        compose = json.loads(run('docker', 'compose', '--env-file', '/dev/null', '-f',
                                 str(root / 'nginx/docker-compose.yml'), 'config', '--format', 'json', env=env))
        check(compose['services']['stalwart-sync']['environment']['ZPUSH_HOST'] == env['ZPUSH_HOST'],
              'Compose forwards the public hostname setting')
    finally:
        stop()
        run('docker', 'volume', 'rm', volume)
        fixture.shutdown()
        fixture.server_close()


if __name__ == '__main__':
    main()
