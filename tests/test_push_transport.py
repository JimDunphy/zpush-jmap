#!/usr/bin/env python3
"""Exercise the compiled PHP/Rust push path against a loopback JMAP/SSE fixture.

python3 tests/test_push_transport.py --image local/zpush:your-tag
Requires Docker and Python 3. Uses only dummy credentials and a local HTTP server.
"""
import argparse
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import subprocess
import threading
from urllib.parse import parse_qs, urlsplit


class JmapFixture(BaseHTTPRequestHandler):
    state = 1
    events = 0
    errors = []

    def log_message(self, *args):
        pass

    def respond(self, data, content_type="application/json", status=200):
        data = data.encode() if isinstance(data, str) else json.dumps(data).encode()
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        route = urlsplit(self.path)
        if route.path == "/.well-known/jmap":
            base = f"http://127.0.0.1:{self.server.server_port}"
            self.respond({
                "apiUrl": base + "/jmap",
                "downloadUrl": base + "/download/{accountId}/{blobId}/{name}?type={type}",
                "uploadUrl": base + "/upload/{accountId}",
                "eventSourceUrl": base + "/events?types={types}&closeafter={closeafter}&ping={ping}",
                "primaryAccounts": {"urn:ietf:params:jmap:mail": "c"},
            })
            return
        if route.path != "/events":
            self.respond({}, status=404)
            return
        query = parse_qs(route.query)
        if "Email" not in query.get("types", [""])[0] or query.get("closeafter") != ["state"]:
            type(self).errors.append("Event source template was not expanded correctly")
        type(self).events += 1
        type(self).state += 1
        if self.events == 4:
            # The connection closes without delivering its notification.
            self.respond("", "text/event-stream")
        elif self.events == 5:
            self.respond({}, status=503)
        else:
            # Ignore keepalives and another account, then consume our state event.
            payload = {"@type": "StateChange", "changed": {"c": {"Email": str(self.state)}}}
            self.respond(
                'event: ping\r\ndata: {"interval":30}\r\n\r\n'
                'event: state\ndata: {"@type":"StateChange","changed":{"other":{"Email":"9"}}}\n\n'
                'event: state\r\ndata: ' + json.dumps(payload) + '\r\n\r\n',
                "text/event-stream",
            )

    def do_POST(self):
        request = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        responses = []
        for method, args, call_id in request["methodCalls"]:
            if method == "Email/get":
                result = {"accountId": "c", "state": str(self.state), "list": [], "notFound": []}
            elif method == "Email/changes":
                changed = args["sinceState"] != str(self.state)
                kind = {1: "created", 2: "updated"}.get(self.events, "destroyed")
                result = {
                    "accountId": "c", "oldState": args["sinceState"], "newState": str(self.state),
                    "hasMoreChanges": False, "created": [], "updated": [], "destroyed": [],
                }
                if changed:
                    result[kind] = ["mail-1"]
            else:
                type(self).errors.append("Unexpected method: " + method)
                self.respond({}, status=400)
                return
            responses.append([method, result, call_id])
        self.respond({"methodResponses": responses, "sessionState": "session-1"})


PHP = r'''<?php
chdir('/var/www/zpush');
class ZLog {
    public static function Write($level, $message, $truncate = true) {
        if (strpos($message, 'ChangesSink(): source=') !== false) echo $message, PHP_EOL;
    }
}
require 'vendor/autoload.php';
require '/workspace/backend/stalwart/stalwart.php';
class TransportBackend extends BackendStalwart {
    public function __construct($client) {
        $this->_jmapClient = $client;
        foreach (['a', 'b'] as $id) {
            $this->_idToIndex[$id] = count($this->_folders);
            $this->_folders[] = (object) ['id' => $id, 'devid' => $id, 'view' => 'message'];
        }
    }
}
$client = jmap_connect(getenv('PUSH_TEST_URL'), 'dummy@example.test', 'test-only');
if (!method_exists($client, 'waitForPushChange') || !$client->supportsPushNotifications()) {
    throw new RuntimeException('Compiled extension does not expose push');
}
$backend = new TransportBackend($client);
if (!$backend->HasChangesSink()) throw new RuntimeException('Push sink is not advertised');
$backend->ChangesSinkInitialize('a');
$backend->ChangesSinkInitialize('b');
foreach (['arrival', 'move', 'deletion', 'missed event', 'HTTP error'] as $scenario) {
    if ($backend->ChangesSink(2) !== ['a', 'b']) throw new RuntimeException('Missed ' . $scenario);
    echo 'PASS: Rust HTTP/SSE -> backend notification: ', $scenario, PHP_EOL;
}
'''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--image", required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    server = ThreadingHTTPServer(("127.0.0.1", 0), JmapFixture)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        result = subprocess.run([
            "docker", "run", "--rm", "-i", "--network", "host", "--entrypoint", "php",
            "-v", f"{root}:/workspace:ro", "-e", f"PUSH_TEST_URL=http://127.0.0.1:{server.server_port}",
            args.image,
        ], input=PHP, text=True, timeout=30)
        if result.returncode or JmapFixture.errors or JmapFixture.events != 5:
            raise SystemExit(f"Transport tests failed: exit={result.returncode}, events={JmapFixture.events}, errors={JmapFixture.errors}")
    finally:
        server.shutdown()
        server.server_close()
        thread.join()


if __name__ == "__main__":
    main()
