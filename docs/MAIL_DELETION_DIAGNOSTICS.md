# Investigating externally deleted mail on iOS

The September 15, 2026 rehearsal on one iPhone, reported as iOS 26.6.2, showed
incoming mail promptly while some messages moved to Trash in another client
remained visible in Inbox until pull-to-refresh. Turning off threading did not
resolve the observation. This is historical evidence, not a claim that every
iOS release or the latest application build reproduces it.

## What the retained evidence establishes

Small response captures show a Ping changes notification, a successful Inbox
Sync containing a Delete with a ServerId, and subsequent successful Sync traffic.
The contemporaneous server investigation recorded the client reusing the
returned SyncKey. Native mailbox metadata showed Trash membership for the
deleted message.

Reusing a returned SyncKey establishes receipt of that server response. It does
not prove the client applied every command or refreshed its visible list. The
retained packet extracts contain responses only; they cannot independently prove
the request-key reuse reported by the original server investigation. Original
full arrival bodies were not retained in that bounded capture, so compatibility
of the initial item representation cannot be fully checked retrospectively.

The protocol distinction is described in Microsoft's [SyncKey definition](https://learn.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-ascmd/bb649593-c793-415e-912e-e7da9b7319bf)
and [Ping behavior](https://learn.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-ascmd/54540f3b-ab15-4856-8e21-dcebd4a7b495).

## October 29 lifecycle investigation

`tests/test_eas_lifecycle.py` runs real HTTP requests through a disposable Nginx,
PHP-FPM, Z-Push and Rust container against a loopback JMAP fixture. It provisions
a separate dummy client, performs FolderSync and initial Sync, and exercises
actual persisted device state. No live account, phone state, host proxy or
firewall is changed.

The suite passed against both the retained September rehearsal image and the
0.2.6 candidate. Coverage includes:

- Plain-text and MIME arrival representations, recording the initial Add IDs.
- An external Inbox-to-Trash move waking an outstanding Ping through JMAP SSE.
- Correct folder-scoped Inbox Delete and Trash Add for the same native item.
- Read-flag changes alongside a deletion, without confusing their ServerIds.
- Missed push notifications and expired upstream change states.
- Repeated Ping before Sync, without consuming the pending deletion.
- A lost-response simulation: retrying the old SyncKey resends the deletion.
- Explicit reuse of the returned key settling the collection without resurrection.
- A temporary listing failure returning an error rather than mass deletions.
- WindowSize 1, MoreAvailable and deletion continuation across SyncKeys.
- An unacknowledged deletion surviving a container restart and replaying afterward.
- An intentional restore to Inbox producing a new Add only after that restore.

No server-side cause for the historical display symptom was established in these
scenarios. Accordingly, this investigation adds coverage and diagnostics rather
than changing production deletion behavior. It does not establish an iOS defect;
client caching, representation compatibility, historical profile state, and
uncovered request timing remain possible areas for a future evidence-led check.
Pull-to-refresh remains the observed workaround.

## Run the isolated regression

From the project root, select a built application image:

```bash
python3 tests/test_eas_lifecycle.py --image local/zpush:0.2.6
python3 tests/test_eas_trace.py
```

`nginx/manage.sh --verify-image` includes both suites. They require Python 3 and
Docker; the lifecycle test uses host networking with randomized loopback ports.
Its mail, account credentials, JMAP mutations and device profile are all dummy
data. Containers are removed after the run. An optional `--trace-output PATH`
writes the fixture's body-only exchange records for inspection.

## Read a bounded trace

`tools/eas_trace.py` accepts JSON or JSONL records with these optional fields:

```json
{"command":"Sync","utc":"2026-01-01T12:00:00Z","http_status":200,"request_body_base64":"...","response_body_base64":"..."}
```

Use chronological records from a single device session. Do not include HTTP
headers or credentials. Response-only historical extracts may use `body_base64`.

```bash
python3 tools/eas_trace.py /private/path/session.jsonl
```

The reader reports collection status, Ping folders, command kinds and whether an
explicit request key refers to an earlier deletion response in that trace. It
pseudonymizes collection IDs, ServerIds and SyncKeys and excludes message content
and arbitrary metadata from its output. It rejects oversized or malformed bodies.
Pseudonyms permit correlation; they are not a guarantee of anonymity. Keep raw
captures private and review summaries before sharing them.

An empty Sync request, Ping, repeated response key or empty HTTP response alone
does not establish acknowledgment. Missing requests are reported as missing
evidence, never inferred as a client acknowledgment or application of Delete.

For a future reproduction, the useful evidence is the matching initial Add ID,
Ping status/collection, Delete response and returned key, and a subsequent request
using that key. Capture only a bounded session using an approved local workflow;
the reader itself neither captures network traffic nor changes the server.
