#!/usr/bin/env python3
"""Exercise actual Ping/Sync/SyncKey HTTP state with isolated dummy JMAP mail."""
import argparse
import base64
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
import json
from pathlib import Path
import socket
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('eas_trace', ROOT / 'tools/eas_trace.py')
trace = importlib.util.module_from_spec(spec)
import sys
sys.modules[spec.name] = trace
spec.loader.exec_module(trace)


def encode(tree):
    reverse = {trace.PAGES[page] + ':' + name: (page, code)
               for page, names in trace.NAMES.items() for code, name in names.items()}
    output, page = bytearray(b'\x03\x01\x6a\x00'), 0

    def emit(node):
        nonlocal page
        wanted, token = reverse[node[0]]
        if wanted != page:
            output.extend((0, wanted))
            page = wanted
        content = node[1]
        if content is None or content == []:
            output.append(token)
            return
        output.append(token | 64)
        if isinstance(content, list):
            for child in content:
                emit(child)
        else:
            output.extend(b'\x03' + str(content).encode() + b'\0')
        output.append(1)
    emit(tree)
    return bytes(output)


def check(condition, message):
    if not condition:
        raise AssertionError(message)
    print('PASS: ' + message, flush=True)


class Fixture(BaseHTTPRequestHandler):
    objects = {}
    state = 1
    changes = []
    waiting = threading.Event()
    missed = False
    expire = False
    fail_query = False
    errors = []

    def log_message(self, *args):
        pass

    @classmethod
    def reset(cls):
        cls.state += 1
        cls.changes = []
        cls.missed = cls.expire = cls.fail_query = False
        cls.waiting.clear()
        cls.objects = {ident: {'id': ident, 'blobId': ident, 'subject': 'Dummy ' + ident,
                              'receivedAt': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
                              'size': 100, 'keywords': {}, 'mailboxIds': {'inbox': True},
                              'from': [{'email': 'sender@example.test'}],
                              'to': [{'email': 'dummy@example.test'}],
                              'textBody': [{'partId': '1', 'type': 'text/plain'}],
                              'bodyValues': {'1': {'value': 'Fixture body'}},
                              'hasAttachment': False}
                       for ident in ('mail-keep', 'mail-delete')}

    @classmethod
    def move(cls, ident, folder, read_other=False):
        cls.objects[ident]['mailboxIds'] = {folder: True}
        updated = [ident]
        if read_other:
            cls.objects['mail-keep']['keywords'] = {'$seen': True}
            updated.append('mail-keep')
        cls.state += 1
        cls.changes.append((cls.state, updated))

    def respond(self, data, status=200, content_type='application/json'):
        data = data if isinstance(data, bytes) else json.dumps(data).encode()
        self.send_response(status)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        try:
            self.wfile.write(data)
        except (BrokenPipeError, ConnectionResetError):
            # A bounded SSE wait can be closed by Rust before this response.
            pass

    def do_GET(self):
        if self.path.startswith('/download/'):
            self.respond(b'From: sender@example.test\r\nTo: dummy@example.test\r\nSubject: Fixture\r\n\r\nFixture MIME body\r\n',
                         content_type='message/rfc822')
        elif self.path.startswith('/events'):
            before = self.state
            self.waiting.set()
            deadline = time.monotonic() + 1
            while before == self.state and time.monotonic() < deadline:
                time.sleep(0.01)
            if self.missed:
                data = b''
            else:
                data = b'event: state\ndata: ' + json.dumps({'@type': 'StateChange',
                        'changed': {'a': {'Email': str(self.state)}}}).encode() + b'\n\n'
            self.respond(data, content_type='text/event-stream')
        else:
            base = f'http://127.0.0.1:{self.server.server_port}'
            self.respond({'apiUrl': base + '/jmap', 'uploadUrl': base + '/upload/{accountId}',
                          'downloadUrl': base + '/download/{accountId}/{blobId}/{name}',
                          'eventSourceUrl': base + '/events?types={types}&closeafter={closeafter}&ping={ping}',
                          'primaryAccounts': {'urn:ietf:params:jmap:mail': 'a'}})

    def do_POST(self):
        request = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        responses = []
        for method, args, call in request['methodCalls']:
            result = {'accountId': 'a', 'state': str(self.state), 'list': [], 'notFound': []}
            if method == 'Mailbox/get':
                result['list'] = [{'id': role, 'name': role.title(), 'role': role, 'parentId': None,
                                   'totalEmails': sum(bool(o['mailboxIds'].get(role)) for o in self.objects.values()),
                                   'unreadEmails': 0} for role in ('inbox', 'trash', 'sent')]
            elif method in ('AddressBook/get', 'Calendar/get'):
                pass
            elif method == 'Email/query':
                if self.fail_query:
                    self.respond({'error': 'Simulated query outage'}, status=503)
                    return
                ids = [ident for ident, obj in self.objects.items()
                       if obj['mailboxIds'].get(args['filter']['inMailbox'])]
                result = {'ids': ids[args.get('position', 0):args.get('position', 0) + args.get('limit', 100)],
                          'total': len(ids), 'position': args.get('position', 0), 'queryState': str(self.state)}
            elif method == 'Email/get':
                ids = args.get('ids', list(self.objects))
                for ident in ids:
                    if ident not in self.objects:
                        result['notFound'].append(ident)
                    else:
                        result['list'].append({k: v for k, v in self.objects[ident].items()
                                               if k in args.get('properties', self.objects[ident])})
            elif method == 'Email/changes':
                if self.expire:
                    type(self).expire = False
                    responses.append(['error', {'type': 'cannotCalculateChanges'}, call])
                    continue
                ids = sorted({ident for state, changed in self.changes
                              if state > int(args['sinceState']) for ident in changed})
                result = {'oldState': args['sinceState'], 'newState': str(self.state),
                          'hasMoreChanges': False, 'created': [], 'updated': ids, 'destroyed': []}
            else:
                self.errors.append(method)
                responses.append(['error', {'type': 'unknownMethod'}, call])
                continue
            responses.append([method, result, call])
        self.respond({'methodResponses': responses, 'sessionState': 'fixture'})


class Client:
    def __init__(self, port):
        self.port, self.key, self.records = port, '0', []
        self.device = uuid.uuid4().hex
        self.body_type = '1'

    def post(self, command, tree=None):
        body = encode(tree) if tree else b''
        query = urllib.parse.urlencode({'Cmd': command, 'User': 'dummy@example.test',
                                        'DeviceId': self.device, 'DeviceType': 'iPhone'})
        request = urllib.request.Request(f'http://127.0.0.1:{self.port}/Microsoft-Server-ActiveSync?{query}',
                    data=body, headers={'Authorization': 'Basic ' + base64.b64encode(b'dummy@example.test:test-only').decode(),
                    'MS-ASProtocolVersion': '14.1', 'X-MS-PolicyKey': self.key,
                    'Content-Type': 'application/vnd.ms-sync.wbxml', 'User-Agent': 'Apple-iPhone/fixture'})
        try:
            with urllib.request.urlopen(request, timeout=12) as response:
                status, data = response.status, response.read()
        except urllib.error.HTTPError as error:
            status, data = error.code, error.read()
        self.records.append({'command': command, 'http_status': status,
                             'request_body_base64': base64.b64encode(body).decode(),
                             'response_body_base64': base64.b64encode(data).decode()})
        check(status == 200, command + ' completes over HTTP')
        return trace.decode(data)

    def provision(self):
        policy = [('Provision:PolicyType', 'MS-EAS-Provisioning-WBXML')]
        def request(items):
            return ('Provision:Provision', [('Provision:Policies', [('Provision:Policy', items)])])
        first = self.post('Provision', request(policy))
        temp = first.all('Provision:Policies')[0].all('Provision:Policy')[0].value('Provision:PolicyKey')
        second = self.post('Provision', request(policy + [('Provision:PolicyKey', temp), ('Provision:Status', '1')]))
        self.key = second.all('Provision:Policies')[0].all('Provision:Policy')[0].value('Provision:PolicyKey')
        folders = self.post('FolderSync', ('FolderHierarchy:FolderSync', [('FolderHierarchy:SyncKey', '0')]))
        check(folders.value('FolderHierarchy:Status') == '1', 'FolderSync initializes real persisted device hierarchy')

    def sync(self, keys, window=100):
        folders = []
        for folder, key in keys.items():
            options = [('AirSync:FilterType', '0'), ('AirSync:MIMESupport', '2'),
                       ('AirSyncBase:BodyPreference', [('AirSyncBase:Type', self.body_type)])]
            fields = [('AirSync:SyncKey', key), ('AirSync:CollectionId', folder),
                      ('AirSync:GetChanges', '0' if key == '0' else '1'),
                      ('AirSync:WindowSize', '0' if key == '0' else str(window)),
                      ('AirSync:Options', options)]
            folders.append(('AirSync:Collection', fields))
        root = self.post('Sync', ('AirSync:Sync', [('AirSync:Collections', folders)]))
        return {folder.value('AirSync:CollectionId'): folder for folder in trace.collections(root)}

    def ping(self):
        return self.post('Ping', ('Ping:Ping', [('Ping:HeartbeatInterval', '1'),
                    ('Ping:Folders', [('Ping:Folder', [('Ping:Id', 'inbox'), ('Ping:Class', 'Email')])])]))


def commands(folder, kind):
    return [c.value('AirSync:ServerId') for group in folder.all('AirSync:Commands')
            for c in group.children if c.tag == 'AirSync:' + kind]


def initialize(port, window=100, mime=False):
    Fixture.reset()
    client = Client(port)
    client.body_type = '4' if mime else '1'
    client.provision()
    keys = {'inbox': '0', 'trash': '0'}
    result = client.sync(keys)
    keys = {folder: result[folder].value('AirSync:SyncKey') for folder in keys}
    result = client.sync(keys, window=window)
    added = set(commands(result['inbox'], 'Add'))
    keys = {folder: result[folder].value('AirSync:SyncKey') for folder in keys}
    while result['inbox'].all('AirSync:MoreAvailable'):
        result = client.sync({'inbox': keys['inbox']}, window=window)
        added.update(commands(result['inbox'], 'Add'))
        keys['inbox'] = result['inbox'].value('AirSync:SyncKey')
    check(added == {'mail-delete', 'mail-keep'}, 'Initial Sync Add establishes exact device ServerIds')
    client.sync(keys)  # Confirm initial Add response before monitoring.
    return client, keys


def scenario(port, mode):
    client, keys = initialize(port, mime=mode == 'mime-body')
    Fixture.waiting.clear()
    with ThreadPoolExecutor(max_workers=1) as pool:
        future = pool.submit(client.ping)
        reached = Fixture.waiting.wait(5)
        if not reached and future.done():
            print('Early Ping response:', future.result())
            print(json.dumps(trace.summarize(client.records), indent=2))
        check(reached, mode + ': Ping reaches the actual JMAP SSE wait')
        Fixture.missed = mode == 'missed-push'
        Fixture.expire = mode == 'expired-state'
        Fixture.move('mail-delete', 'trash', read_other=mode == 'mixed-read-delete')
        ping = future.result(timeout=10)
    check(ping.value('Ping:Status') == '2', mode + ': external move wakes Ping with changes status')
    check([n.text for n in ping.all('Ping:Folders')[0].all('Ping:Folder')] == ['inbox'],
          mode + ': Ping identifies the correct client collection')
    check(client.ping().value('Ping:Status') == '2', mode + ': Ping does not consume the pending deletion')
    result = client.sync(keys)
    check(commands(result['inbox'], 'Delete') == ['mail-delete'] and not commands(result['inbox'], 'Add'),
          mode + ': Sync deletes exactly the previously added ServerId from Inbox')
    check(commands(result['trash'], 'Add') == ['mail-delete'], mode + ': the same native item is added only to Trash')
    if mode == 'mixed-read-delete':
        check(commands(result['inbox'], 'Change') == ['mail-keep'], 'Read change and deletion retain distinct identities')
    delivered_key = result['inbox'].value('AirSync:SyncKey')
    check(delivered_key != keys['inbox'], mode + ': deletion advances the SyncKey')
    # Deliberately disregard that reply, then retry the old key as a client
    # would after a lost HTTP response. No device state is injected or mocked.
    retry = client.sync({'inbox': keys['inbox']})
    check(commands(retry['inbox'], 'Delete') == ['mail-delete'], mode + ': retrying an old key resends the deletion')
    acknowledged = retry['inbox'].value('AirSync:SyncKey')
    result = client.sync({'inbox': acknowledged})
    check(not result or (result['inbox'].value('AirSync:Status') == '1' and
                        not commands(result['inbox'], 'Delete') and not commands(result['inbox'], 'Add')),
          mode + ': explicit SyncKey acknowledgment settles without resurrecting deleted mail')
    check(client.ping().value('Ping:Status') == '1', mode + ': idle Ping settles after acknowledgment')
    summaries = trace.summarize(client.records)
    check(any(r.get('requests') and r['requests'][0]['acknowledged_delete_ids'] for r in summaries),
          mode + ': diagnostic trace distinguishes delivered deletion from later key reuse')
    return client


def edge_cases(port, container):
    clients = []
    client, keys = initialize(port)
    clients.append(client)
    Fixture.move('mail-delete', 'trash')
    Fixture.fail_query = True
    failed = client.sync({'inbox': keys['inbox']})['inbox']
    check(failed.value('AirSync:Status') != '1' and not commands(failed, 'Delete'),
          'Mailbox listing failure returns an error without mass deletion')
    Fixture.fail_query = False
    recovered = client.sync({'inbox': keys['inbox']})['inbox']
    check(commands(recovered, 'Delete') == ['mail-delete'], 'Retry after a listing outage retains the pending deletion')

    client, keys = initialize(port, window=1)
    clients.append(client)
    Fixture.move('mail-delete', 'trash')
    Fixture.move('mail-keep', 'trash')
    first = client.sync({'inbox': keys['inbox']}, window=1)['inbox']
    first_ids = commands(first, 'Delete')
    check(len(first_ids) == 1 and bool(first.all('AirSync:MoreAvailable')), 'Small window pages deletions with MoreAvailable')
    second = client.sync({'inbox': first.value('AirSync:SyncKey')}, window=1)['inbox']
    check(set(first_ids + commands(second, 'Delete')) == {'mail-delete', 'mail-keep'},
          'Next SyncKey page delivers the remaining deletion without duplication')
    settled = client.sync({'inbox': second.value('AirSync:SyncKey')})
    check(not settled or not commands(settled['inbox'], 'Delete'), 'Acknowledging all deletion pages settles the collection')

    client, keys = initialize(port)
    clients.append(client)
    Fixture.move('mail-delete', 'trash')
    sent = client.sync({'inbox': keys['inbox']})['inbox']
    check(commands(sent, 'Delete') == ['mail-delete'], 'Deletion is sent before the simulated service restart')
    subprocess.run(['docker', 'restart', container], stdout=subprocess.DEVNULL, check=True, timeout=30)
    wait_health(port)
    replayed = client.sync({'inbox': keys['inbox']})['inbox']
    check(commands(replayed, 'Delete') == ['mail-delete'], 'Unacknowledged deletion survives restart and replays with the old key')
    key = replayed.value('AirSync:SyncKey')
    settled = client.sync({'inbox': key})
    check(not settled or not commands(settled['inbox'], 'Delete'), 'Acknowledgment after restart settles without resetting device state')
    Fixture.move('mail-delete', 'inbox')
    restored = client.sync({'inbox': key})['inbox']
    check(commands(restored, 'Add') == ['mail-delete'], 'An intentional restore re-adds the same native item only after the move back')
    return [record for client in clients for record in client.records]


def wait_health(port):
    for _ in range(100):
        try:
            urllib.request.urlopen(f'http://127.0.0.1:{port}/healthz', timeout=1).close()
            return
        except (OSError, urllib.error.URLError):
            time.sleep(0.1)
    raise RuntimeError('Lifecycle container failed startup')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--image', required=True)
    parser.add_argument('--trace-output', type=Path)
    args = parser.parse_args()
    fixture = ThreadingHTTPServer(('127.0.0.1', 0), Fixture)
    threading.Thread(target=fixture.serve_forever, daemon=True).start()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    name = 'zpush-eas-lifecycle-' + uuid.uuid4().hex[:12]
    try:
        subprocess.run(['docker', 'run', '-d', '--name', name, '--network', 'host',
                        '-e', f'STALWART_URL=http://127.0.0.1:{fixture.server_port}',
                        '-e', 'ZPUSH_BIND_ADDRESS=127.0.0.1', '-e', f'ZPUSH_HTTP_PORT={port}', args.image],
                       stdout=subprocess.DEVNULL, check=True)
        wait_health(port)
        records = []
        for mode in ('unread-move', 'mixed-read-delete', 'missed-push', 'expired-state', 'mime-body'):
            client = scenario(port, mode)
            records.extend(client.records)
        records.extend(edge_cases(port, name))
        check(not Fixture.errors, 'Lifecycle fixture saw only expected read-only JMAP methods')
        if args.trace_output:
            args.trace_output.write_text(json.dumps(records, indent=2) + '\n')
    except Exception:
        result = subprocess.run(['docker', 'exec', name, 'tail', '-n', '50', '/data/log/z-push.log'],
                                stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        print(result.stdout)
        raise
    finally:
        subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        fixture.shutdown()
        fixture.server_close()


if __name__ == '__main__':
    main()
