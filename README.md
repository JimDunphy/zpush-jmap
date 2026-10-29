# Z-Push Stalwart Backend

ActiveSync support for [Stalwart Mail Server](https://stalw.art/) via JMAP. Enables iOS Mail, Android, Outlook, and other ActiveSync clients to sync email, contacts, calendars, and tasks with Stalwart.

The application release is defined in [VERSION](VERSION). Run
`nginx/manage.sh --version` to compare the checkout, built image, and running
application versions, including the image ID, Z-Push, and PHP-FPM package.
The application version is independent of the upstream Z-Push framework version.
Use `nginx/manage.sh --remote-version` to read the configured SSH deployment
host's versions. Host/user/path settings belong in the ignored `nginx/.env`;
published examples contain no deployment destination.

## Documentation

- Executive overview: `docs/EXECUTIVE_SUMMARY.md`
- Technical internals: `docs/INTERNALS.md`
- Production deployment runbook: `docs/PRODUCTION_DEPLOYMENT.md`
- Verify locally and deploy over SSH: `docs/RELEASE_WORKFLOW.md`
- Task support and limitations: `docs/TASKS.md`
- Security and operations hardening: `docs/HARDENING.md`
- Security audit status: `docs/SECURITY_AUDIT.md`
- Third-party licensing notes: `THIRD_PARTY_LICENSES.md`

## Key Features (Admin-Focused)

| Capability | What you get |
|-----------|--------------|
| ActiveSync bridge for Stalwart | Native iOS/Android/Outlook Exchange-account connectivity over JMAP backend |
| Mail + Contacts + Calendar + Tasks sync | Tasks appear in separate Exchange task lists backed by Stalwart calendars |
| Push-assisted change detection | JMAP push (`eventSourceUrl`) with polling fallback for resilient device updates |
| Autodiscover endpoint | `/autodiscover/Autodiscover.xml` support for compatible clients |
| Security-first network model | TLS at front-end Nginx, host-firewall-controlled backend listener, explicit lab-only relaxations |
| Device ABQ policy controls | Optional allow/block/quarantine rule engine for device admission control |
| State-safe operations | Persistent Z-Push state to preserve existing mobile partnerships across upgrades |
| Repeatable operations tooling | `manage.sh` for build/deploy/health/logs/backup/image export/import workflows |
| Multi-host ready design | Shared-state guidance for horizontal scaling and DNS/LB deployment patterns |
| Open source delivery | MIT-licensed repository with full source available |

### Complements Project Z-Bridge

- `zpush-jmap` targets ActiveSync clients and mobile UX.
- `Project Z-Bridge` provides the Zimbra web-client experience on top of Stalwart.
- Together they cover mobile Exchange-style access plus familiar ZWC-style browser workflows.

## Enterprise Gaps / Current Limitations

| Area | Current status in this project | Mitigation / Path |
|------|--------------------------------|-------------------|
| Full Zimbra suite parity | Not a full drop-in replacement for every Zimbra Network module and workflow | Position as modular migration path: Stalwart + `zpush-jmap` + Project Z-Bridge |
| Device management depth | ABQ allow/block/quarantine rules are available, but no full enterprise MDM console in this repo | Use ABQ policy + existing firewall/VPN controls; integrate external MDM where required |
| Task editing scope | Ordinary tasks support create, edit, completion, moves, deletion, and push; recurring and assigned task edits return failure | Use a capable calendar/task client for advanced task workflows; see `docs/TASKS.md` |
| iOS Mail deletion display | In the iOS 26.6.2 rehearsal, externally deleted messages remained visible after the client reused the deletion response's SyncKey; arrival push worked | Pull down to refresh the Inbox. Root cause remains unresolved; key reuse proves response receipt, not application or display. See [investigation and diagnostics](docs/MAIL_DELETION_DIAGNOSTICS.md) |
| Built-in HA orchestration | Multi-host is supported architecturally, but orchestration/operations are operator-managed | Follow shared-state + DNS/LB guidance in docs for scale-out deployments |
| Turnkey migration tooling | Automated migration/assessment tooling is outside this repository | Use staged pilot migrations and documented rollback/image promotion process |
| Commercial support model | Open-source project; no bundled enterprise support SLA from this repo | Use internal ops ownership or partner support model where needed |

## Platform Architecture

This project is one component of a composable mail platform built around Stalwart. Rather than a monolithic mail suite (where a bug in the web UI can take down mail delivery, and patching one component means restarting everything), each service runs independently and communicates through clean, standards-based APIs.

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Front-end Nginx (443/TLS)                        │
│                    URI-based routing to each service                 │
└──────┬──────────────────┬──────────────────┬───────────────────┬────┘
       │                  │                  │                   │
  ActiveSync        Zimbra Web UI      Spam Filtering      Admin UI
  /autodiscover     (Z-Bridge URIs)    (Rspamd URIs)       (restricted)
       │                  │                  │                   │
┌──────▼──────┐  ┌────────▼────────┐  ┌─────▼──────┐  ┌────────▼────────┐
│ zpush-jmap  │  │  Z-Bridge       │  │  Rspamd    │  │   Stalwart      │
│ (container) │  │  (container)    │  │ (container)│  │ mail-admin.     │
│ ActiveSync  │  │  Zimbra UX for  │  │ spam/virus │  │ example.com     │
│ for mobile  │  │  Stalwart via   │  │ filtering  │  │ (IP-restricted) │
│ devices     │  │  SOAP→JMAP      │  │            │  │                 │
└──────┬──────┘  └────────┬────────┘  └─────┬──────┘  └────────┬────────┘
       │                  │                  │                   │
       │                  │    JMAP          │  milter/          │
       └──────────────────┴─────────────┐    │  HTTP             │
                                        │    │                   │
                                  ┌─────▼────▼───────────────────▼────┐
                                  │          Stalwart                  │
                                  │   Mail storage, JMAP, IMAP, SMTP  │
                                  │   The only component that touches  │
                                  │   mail data                        │
                                  └───────────────────────────────────┘
```

| Component | Role | Integration | Update impact |
|-----------|------|-------------|---------------|
| [Stalwart](https://stalw.art/) | Mail storage, JMAP/IMAP/SMTP | Core — everything connects here | Restart Stalwart only |
| **zpush-jmap** (this project) | ActiveSync for mobile devices | JMAP client → Stalwart | Rebuild container, no impact to webmail or mail flow |
| [Project Z-Bridge](https://github.com/JimDunphy/Project-Z-Bridge) | Zimbra web UI (passwords, 2FA, settings) | SOAP/XML → JMAP bridge → Stalwart | Rebuild container, no impact to ActiveSync or mail flow |
| [Rspamd](https://rspamd.com/) | Spam/virus filtering | milter or HTTP integration with Stalwart | Rebuild container, no impact to user-facing services |
| Nginx | TLS termination, URI routing | Reverse proxy to all services | Reload config, no downtime |

**Why this matters:**
- **Independent updates.** Patch PHP, Rust, or Rspamd without touching mail delivery. Upgrade Stalwart without rebuilding containers. Each component is on its own release cycle.
- **Security isolation.** A vulnerability in the web UI doesn't expose mail storage. The admin interface is firewalled separately. Each container has its own attack surface.
- **Replaceable components.** If Stalwart's built-in spam filtering matures, drop the Rspamd container and change one config line. If a better ActiveSync solution appears, swap out zpush-jmap. The JMAP contract between components stays the same.
- **Flexible deployment.** Power users run Z-Bridge on their desktop for a local Zimbra experience. Mobile users hit the server's zpush-jmap over ActiveSync. Same components, different topology.

This is the opposite of a monolithic mail suite where everything is tightly coupled and a single update can cascade failures across the entire system.

## How It Works

```
Mobile Device (ActiveSync/EAS)
        |
   [ Nginx ] ── port 10000 (configurable)
        |
   [ PHP-FPM ] ── Z-Push framework + BackendStalwart adapter
        |
   [ Rust Extension ] ── libstalwart_zpush.so (ext-php-rs FFI)
        |
   [ Stalwart ] ── JMAP over HTTP/HTTPS
```

Z-Push translates the ActiveSync protocol into internal calls. The `BackendStalwart` PHP class dispatches those calls to a Rust extension that speaks JMAP to Stalwart. The Rust layer handles all JMAP protocol details, session management, and data flattening. PHP maps the results to ActiveSync objects.

**Supported sync types:** Email, Contacts, Calendar

## Prerequisites

- **Docker and Docker Compose** (v2+)
- **Stalwart Mail Server** with JMAP enabled
- Network access from the Docker host to Stalwart's JMAP endpoint

## Quick Start

For a first installation with host Nginx serving HTTPS and a prebuilt container
image, follow the [first-host quick start](docs/QUICK_START.md).

```bash
# 1. Clone the repository
git clone <repository-url>
cd zpush-jmap

# 2. Initialize environment and data directories
cd nginx
./manage.sh --init

# 3. Edit .env — set your Stalwart URL and backend release ID
#    STALWART_URL=https://mail.example.com
#    STALWART_BACKEND_VERSION=2026.02.23-lab1
vi .env

# 4. Build and start
./manage.sh --up

# 5. Verify
./manage.sh --health
```

The container listens on `http://127.0.0.1:10000` by default. `ZPUSH_BIND_ADDRESS` controls the listener address (`127.0.0.1` by default for secure deployments; `0.0.0.0` can be used in lab/testing). Point your reverse proxy at `/Microsoft-Server-ActiveSync` on that port.

## Security Requirements (MUST)

For any public deployment:

1. Expose only the front-end TLS endpoint (`443`) to clients.
2. Do not expose `ZPUSH_HTTP_PORT` directly to the internet.
3. Bind zpush-jmap to loopback or a dedicated internal service IP (`ZPUSH_BIND_ADDRESS`).
4. Enforce host firewall policy on the backend listener and allow only trusted source ranges (reverse proxy host, internal ranges, VPN ranges, or equivalent).
5. Keep mobile/public clients on the TLS endpoint (for example `mail.example.com`), not on backend port `10000`.
6. For Stalwart upstream transport:
   - Same-host loopback (`http://localhost:10080`) is acceptable.
   - Non-loopback connections should use `https://` with `STALWART_SSL_VERIFYPEER=true` and `STALWART_SSL_VERIFYHOST=true`.

Lab-only relaxations (not for production):

- `ZPUSH_BIND_ADDRESS=0.0.0.0`
- `STALWART_SSL_VERIFYPEER=false`
- `STALWART_SSL_VERIFYHOST=false`
- `STALWART_ALLOW_INSECURE_HTTP=true` (only when required for controlled lab tests)

**What happens during build:** You do not need to install Rust, PHP, or Nginx on your host — everything is compiled and packaged inside Docker automatically. The Dockerfile uses a multi-stage build: stage 1 compiles the Rust extension inside a temporary Rust container, stage 2 assembles the final image with Nginx, PHP-FPM, Z-Push, and the compiled extension. The first build takes 5-10 minutes (Rust compilation); subsequent builds are fast (~10 seconds) because Docker caches the Rust layer and only rebuilds what changed.

## Mobile Device Setup

1. **Add Account** -> choose **Exchange** (or Microsoft Exchange ActiveSync)
2. **Server:** your-domain.com (the host proxying to port 10000)
3. **Username:** your full email address (e.g., `user@example.com`)
4. **Password:** your Stalwart password
5. **Enable:** Mail, Contacts, Calendar, and Reminders as desired. Task lists appear in Reminders; see [task support and limits](docs/TASKS.md).

The container also serves `/autodiscover/Autodiscover.xml` for clients that support it.

## Container Management (manage.sh)

All commands are run from the `nginx/` directory:

```bash
./manage.sh <command> [args]
```

| Command | Description |
|---------|-------------|
| `--init` | Create `.env` from template and initialize data directories |
| `--build [--refresh]` | Build the image; `--refresh` pulls bases and repeats package installation |
| `--verify-image [image]` | Test a candidate image in temporary local containers and record source/image hashes |
| `--deploy [--dry-run]` | Explicitly deploy the verified release to the configured SSH host, with backup and rollback |
| `--up` | Build image (if needed) and start the container |
| `--up-no-build` | Start/recreate container using existing image tag (no rebuild) |
| `--down` | Stop and remove the container |
| `--restart` | Stop, rebuild, and start the container (`down -> up --build`) |
| `--restart-no-build` | Stop and start using existing image (`down -> up --no-build`) |
| `--logs` | Tail container logs (last 200 lines) |
| `--health` | Check the `/healthz` endpoint |
| `--version` | Report the current Docker host's checkout, built image, and running versions |
| `--remote-version [--host HOST --dir PATH]` | Read running versions from the configured SSH destination |
| `--backend-version` | Show configured and running `STALWART_BACKEND_VERSION` |
| `--zpush-version` | Show pinned Dockerfile and running Z-Push versions |
| `--console` | Open a bash shell inside the running container |
| `--backup` | Create a timestamped `.tar.gz` backup of persistent data |
| `--set-port <port>` | Change the listen port in `.env` |
| `--set-image <image:tag>` | Change `ZPUSH_IMAGE` in `.env` |
| `--export-image <file.tar.gz>` | Export current image to tar/tar.gz |
| `--import-image <file.tar.gz>` | Import image tar/tar.gz on target host |

### Application and Deployment Version Tracking

The root `VERSION` file defines the application release. Update it for the next
release, select a new image tag, build, and verify locally. Verification starts
temporary containers and removes them after the checks; it does not upgrade a
long-running local service. You then explicitly deploy when ready.

`STALWART_BACKEND_VERSION` is a separate deployment/build identifier. The SSH
deployment command updates it from the selected image tag. It does not override
the embedded application version. Exact builds are identified by immutable
image IDs and source hashes.

Check what is configured and what is currently running:

```bash
cd nginx
./manage.sh --version
./manage.sh --remote-version
./manage.sh --backend-version
./manage.sh --zpush-version
```

Z-Push is pinned by default (not auto-latest). The image build uses `nginx/Dockerfile` `ARG ZPUSH_VERSION` + `ARG ZPUSH_SHA256` until you intentionally update them.
See [the release workflow](docs/RELEASE_WORKFLOW.md) for build, verification,
manual deployment, private target configuration, and rollback.

## Configuration

### Environment Variables (.env)

The `.env` file in `nginx/` controls both the container and the Stalwart backend:

| Variable | Default | Description |
|----------|---------|-------------|
| `ZPUSH_IMAGE` | `local/zpush:latest` | Docker image name |
| `ZPUSH_BUILD_NETWORK` | `default` | Network for Docker build steps; use `host` when bridge networking is unavailable. Runtime networking remains host mode. |
| `ZPUSH_BIND_ADDRESS` | `127.0.0.1` | Listener address inside host network namespace (`127.0.0.1` for production reverse-proxy use; `0.0.0.0` for lab testing) |
| `ZPUSH_HTTP_PORT` | `10000` | Host port the container listens on |
| `STALWART_URL` | `http://localhost:10080` | Stalwart JMAP endpoint (no trailing slash) |
| `STALWART_BACKEND_VERSION` | `dev` | Backend release identifier for logs and runtime diagnostics |
| `STALWART_SSL_VERIFYPEER` | `true` | Verify SSL peer certificate |
| `STALWART_SSL_VERIFYHOST` | `true` | Verify SSL hostname matches certificate |
| `STALWART_ALLOW_INSECURE_HTTP` | `false` | Allow non-HTTPS to non-loopback Stalwart hosts (lab-only) |
| `STALWART_CONNECT_TIMEOUT_SECS` | `10` | Rust JMAP connect timeout in seconds |
| `STALWART_REQUEST_TIMEOUT_SECS` | `60` | Rust JMAP request timeout in seconds |
| `STALWART_BLOB_TIMEOUT_SECS` | `180` | Rust JMAP blob transfer timeout in seconds |
| `STALWART_PUSH_CHANGES_ENABLED` | `true` | Enable JMAP Push-assisted `ChangesSink` waits with poll fallback |
| `STALWART_PUSH_EVENT_TYPES` | `Mailbox,Email,ContactCard,CalendarEvent` | Event types used when expanding `eventSourceUrl` templates |
| `STALWART_PUSH_CLOSEAFTER` | `state` | Push stream close behavior (`state` or `no`) |
| `STALWART_PUSH_PING_SECS` | `30` | Push ping interval used for `eventSourceUrl` template expansion |
| `STALWART_ABQ_ENABLED` | `false` | Enable ABQ device policy enforcement before JMAP logon |
| `STALWART_ABQ_ALLOWED_RULES` | *(empty)* | Comma-separated allow rules (`ua:`, `dt:`, `id:`, or `any:` prefixes supported) |
| `STALWART_ABQ_BLOCKED_RULES` | *(empty)* | Comma-separated block rules |
| `STALWART_ABQ_QUARANTINED_RULES` | *(empty)* | Comma-separated quarantine rules (currently enforced as deny) |
| `STALWART_ABQ_QUARANTINE_BY_DEFAULT` | `false` | Quarantine unmatched devices when allow rules are set |
| `PHP_MEMORY_LIMIT` | `256M` | PHP memory limit |
| `PHP_UPLOAD_MAX_FILESIZE` | `50M` | Max upload size |
| `PHP_POST_MAX_SIZE` | `50M` | Max POST body size |
| `PHP_MAX_EXECUTION_TIME` | `3600` | Script timeout (seconds) |
| `PHP_MAX_INPUT_TIME` | `3600` | Input parsing timeout (seconds) |
| `NGINX_CLIENT_MAX_BODY_SIZE` | `50m` | Nginx body size limit (must be >= PHP post size) |

`nginx/entrypoint.sh` validates `STALWART_*` booleans/integers/enums and also validates runtime `ZPUSH_*`, `PHP_*`, and `NGINX_*` bind/port/size/timeout values before applying them; invalid values fail container startup instead of being injected into PHP or config files.

### Backend Configuration (config.php)

The file `backend/stalwart/config.php` has additional settings. In Docker deployments, `STALWART_URL` and SSL settings are injected automatically from environment variables. For manual deployments, edit the file directly:

| Setting | Default | Description |
|---------|---------|-------------|
| `STALWART_URL` | *(from env)* | JMAP server base URL |
| `STALWART_BACKEND_VERSION` | *(from env)* | Backend release identifier logged during authentication |
| `STALWART_SSL_VERIFYPEER` | *(from env)* | SSL peer verification |
| `STALWART_SSL_VERIFYHOST` | *(from env)* | SSL host verification |
| `STALWART_ALLOW_INSECURE_HTTP` | *(from env)* | Allow insecure HTTP to non-loopback hosts (lab-only) |
| `STALWART_CONNECT_TIMEOUT_SECS` | *(from env)* | Rust JMAP connect timeout |
| `STALWART_REQUEST_TIMEOUT_SECS` | *(from env)* | Rust JMAP request timeout |
| `STALWART_BLOB_TIMEOUT_SECS` | *(from env)* | Rust JMAP blob timeout |
| `STALWART_PUSH_CHANGES_ENABLED` | *(from env)* | Enable Push-assisted `ChangesSink` waits |
| `STALWART_PUSH_EVENT_TYPES` | *(from env)* | Push event type list for `eventSourceUrl` templates |
| `STALWART_PUSH_CLOSEAFTER` | *(from env)* | Push close behavior (`state` or `no`) |
| `STALWART_PUSH_PING_SECS` | *(from env)* | Push ping interval for template expansion |
| `STALWART_ABQ_ENABLED` | *(from env)* | Enable ABQ policy checks in `Logon()` |
| `STALWART_ABQ_ALLOWED_RULES` | *(from env)* | Allow rules (array in `config.php` or comma-separated from env) |
| `STALWART_ABQ_BLOCKED_RULES` | *(from env)* | Block rules |
| `STALWART_ABQ_QUARANTINED_RULES` | *(from env)* | Quarantine rules (currently denied) |
| `STALWART_ABQ_QUARANTINE_BY_DEFAULT` | *(from env)* | Quarantine unmatched devices when allow rules are set |
| `STALWART_HTML` | `true` | Enable HTML email for older AS protocol levels |
| `STALWART_DEBUG` | `false` | Debug logging: `true` (all), `false` (off), or `'user@domain'` |
| `STALWART_DELETESASMOVES` | `true` | Move deleted items to Trash (vs. permanent delete) |

### Device ABQ Policy (Optional)

ABQ decisions run during `Logon()` before creating a JMAP session:

- `block` and `quarantine` both deny login.
- `allow` continues normally.
- If `STALWART_ABQ_ALLOWED_RULES` is non-empty, unmatched devices are denied by default (or quarantined when `STALWART_ABQ_QUARANTINE_BY_DEFAULT=true`).

Rule syntax:

- Wildcards (case-insensitive): `*iphone*`, `corp-*`
- Regex (case-insensitive) with `re:` prefix: `re:^iphone[0-9]+$`
- Regex `re:` patterns are cached per process and limited to 256 characters
- ABQ debug logs include regex cache counters (hits, misses, compile errors, evictions, entries)
- Field prefixes:
  - `ua:` = User-Agent
  - `dt:` = device type
  - `id:` = device ID
  - `any:` = match any field (default when no prefix)

Example:

```dotenv
STALWART_ABQ_ENABLED=true
STALWART_ABQ_ALLOWED_RULES=dt:iphone,dt:ipad,ua:*outlook*
STALWART_ABQ_BLOCKED_RULES=ua:*legacy-eas-client*,id:re:^test-
STALWART_ABQ_QUARANTINED_RULES=ua:*android-unknown*
STALWART_ABQ_QUARANTINE_BY_DEFAULT=false
```

If you need commas inside a regex, define ABQ rules directly in `backend/stalwart/config.php` as PHP arrays instead of comma-separated env strings.

## Nginx Routing

The container runs Nginx as a reverse proxy to PHP-FPM. Only three paths are served; everything else returns 404:

| Path | Purpose | Timeout |
|------|---------|---------|
| `/Microsoft-Server-ActiveSync` | ActiveSync protocol endpoint | 3600s (long-lived EAS connections) |
| `/autodiscover/Autodiscover.xml` | Client autodiscovery | 60s |
| `/healthz` | Health check (returns `200 ok`) | n/a |

If Stalwart is behind a separate reverse proxy (e.g., on a different host), configure that proxy to forward `/Microsoft-Server-ActiveSync` and `/autodiscover/` to `http://<docker-host>:<ZPUSH_HTTP_PORT>`.

## Persistent Data

The container mounts `nginx/zpush/` as `/data/` inside the container. Three subdirectories hold all persistent state:

```
nginx/zpush/                          (host path, mounted as /data/)
  config/
    config.php                        Z-Push main configuration
    autodiscover-config.php           Autodiscover service configuration
  state/                              Per-device sync state (managed by Z-Push)
  log/
    z-push.log                        Main application log
    z-push-error.log                  PHP errors from Z-Push
    autodiscover.log                  Autodiscover request log
    autodiscover-error.log            Autodiscover errors
```

**config/** — Auto-generated on first container start from Z-Push defaults. The entrypoint script patches these files at startup to set `STATE_DIR`, `LOGFILEDIR`, `BACKEND_PROVIDER=BackendStalwart`, and injects `STALWART_URL` / SSL settings from environment variables. Safe to edit manually — changes survive container restarts.

**state/** — Z-Push stores per-device synchronization state here (folder hierarchies, sync keys, device info). This directory must persist across container restarts or devices will need to re-sync from scratch. For horizontal scaling, this must be on shared storage (see [Production Deployment](#production-deployment)).

**log/** — All four log files are created automatically. For troubleshooting, the most useful is `z-push.log` — enable verbose output with `STALWART_DEBUG=true` in `backend/stalwart/config.php`.

### Container Internal Layout

For debugging (`./manage.sh --console`), here's what's inside the container:

| Path | Contents |
|------|----------|
| `/var/www/zpush/` | Z-Push framework source (downloaded at build time) |
| `/var/www/zpush/backend/stalwart/` | Stalwart backend PHP code + Rust extension config |
| `/data/config/`, `/data/state/`, `/data/log/` | Mounted volumes (see above) |
| `/usr/lib/php/20220829/stalwart_zpush.so` | Compiled Rust extension |
| `/etc/php/8.2/mods-available/stalwart_zpush.ini` | Extension loader (`extension=stalwart_zpush.so`) |
| `/etc/nginx/conf.d/zpush.conf` | Nginx routing (patched at startup with port and body size) |
| `/etc/php/8.2/fpm/php.ini` | PHP-FPM settings (patched at startup from env vars) |

Back up persistent data with: `./manage.sh --backup`

## Running Tests

For a release image built with `nginx/manage.sh --build`, run
`nginx/manage.sh --verify-image`. This runs the isolated mail, push, task,
compiled transport, deployment rollback, and fresh-start checks and records
the result for the explicit deployment command. See
[the release workflow](docs/RELEASE_WORKFLOW.md).

Run the backend regression tests without accessing a live mailbox (from the
project root, using your built image tag):

```bash
docker run --rm --network none --entrypoint php -v "$PWD:/workspace:ro" \
  local/zpush:latest /workspace/tests/test_backend_sync.php
docker run --rm --network none --entrypoint php -v "$PWD:/workspace:ro" \
  local/zpush:latest /workspace/tests/test_backend_push.php
python3 tests/test_push_transport.py --image local/zpush:latest
python3 tests/test_eas_lifecycle.py --image local/zpush:latest
python3 tests/test_eas_trace.py
```

The push tests cover sink registration, arrival/deletion notifications, moves,
initialization gaps, missed events, disconnects, expired tokens, and independent
calendar failures. The Python test runs the compiled Rust extension against a
loopback HTTP/SSE fixture with dummy credentials; it requires Docker host
networking on Linux.

The lifecycle suite adds real HTTP Ping/Sync/SyncKey and persisted-state coverage,
including deletion retries, paging, mixed read/deletion changes and restart
recovery. It uses dummy data and no live mailbox. See the [deletion investigation](docs/MAIL_DELETION_DIAGNOSTICS.md)
for the distinction between response receipt, command application and iOS display.

An integration test suite exercises every Rust FFI method against a live Stalwart server:

```bash
# 1. Configure test credentials
cp tests/.env.example tests/.env
vi tests/.env   # Set STALWART_URL, TEST_USER, TEST_PASSWORD

# 2. Ensure the Docker image is built
docker compose -f nginx/docker-compose.yml build

# 3. Run tests
bash tests/run_tests.sh
```

The test suite runs inside the Docker container and covers 24 tests: connection, mailboxes, email query/metadata/download/flags/state/changes/send, address books, contact query/detail/metadata/state/changes, calendars, event query/detail/metadata/state/changes. Two destructive tests (move/trash email) are opt-in via `TEST_DESTRUCTIVE=1`.

## Production Deployment

### Overview

Both zpush-jmap and [Project Z-Bridge](https://github.com/JimDunphy/Project-Z-Bridge) are Stalwart solutions that provide different access methods to the same mail server:

- **zpush-jmap** — ActiveSync for mobile devices (iOS Mail, Android, Outlook)
- **Project Z-Bridge** — Zimbra web UI for browsers (bridges SOAP/XML to JMAP, using the static assets from zm-web-client)

Both talk to Stalwart via JMAP. Since they're containers, they can run anywhere that has network access to Stalwart.

### Typical Usage

A typical deployment runs Stalwart on a server alongside both containers. Users access their mail through whichever method suits the situation:

```
  At the desk (power user)             On the road
  ┌─────────────────────┐             ┌──────────────────────┐
  │ Desktop/Laptop      │             │ Phone / Tablet       │
  │                     │             │ iOS Mail / Android   │
  │ Z-Bridge container  │──┐         │ (Exchange account)   │──┐
  │ (local Zimbra UI)   │  │         └──────────────────────┘  │
  └─────────────────────┘  │                                    │
                            │  JMAP    ┌──────────────────┐     │ ActiveSync
                            └────────▶│    Server         │◀────┘
                                       │                  │
  In a browser (anywhere)              │  Stalwart        │
  ┌─────────────────────┐             │  + zpush-jmap    │
  │ Browser             │  HTTPS      │  + Z-Bridge      │
  │ mail.example.com    │────────────▶│                  │
  └─────────────────────┘             └──────────────────┘
```

- **At the desk:** Power users run a Z-Bridge container on their own workstation for a fast, local Zimbra web UI experience. The container talks directly to Stalwart over JMAP — no round-trip through the server's web proxy.
- **On the road:** Mobile devices use the built-in mail app (iOS Mail, Android Gmail, Outlook) configured as an Exchange account. ActiveSync traffic hits the server's zpush-jmap container.
- **In a browser:** The server also runs Z-Bridge for users who need webmail access from any device without installing anything.

This flexibility comes from the containerized architecture — Z-Bridge is the same container whether it runs on the server or a desktop. zpush-jmap stays on the server since mobile devices need a stable, publicly reachable endpoint.

### Single Host Architecture

In production, each host runs one instance of each service behind a front-end Nginx that terminates TLS and routes by URI. You scale by adding more hosts and distributing traffic via DNS round-robin or a load balancer.

Each host runs a front-end Nginx (443), one zpush-jmap container, and one Z-Bridge container. Stalwart runs on its own host (or on the same host with a separate vhost). The front-end Nginx uses two server blocks:

- **`mail.example.com`** — Public, user-facing. Routes ActiveSync to zpush-jmap, everything else to Z-Bridge (Zimbra web UI). Users manage passwords, 2FA, and account settings through Z-Bridge.
- **`mail-admin.example.com`** — Stalwart's native admin interface. Firewalled to trusted IP addresses only.

```
  mail.example.com (public)            mail-admin.example.com (restricted)
  ┌──────────────────────────┐         ┌──────────────────────────┐
  │  Front-end Nginx (443)   │         │  Front-end Nginx (443)   │
  └────────────┬─────────────┘         │  allow 10.0.0.0/8;       │
               │ URI routing            │  deny all;               │
        ┌──────┴──────┐                └────────────┬─────────────┘
        │             │                             │
  ActiveSync    everything else                     │
  /autodiscover       │                             │
        │             │                             │
  ┌─────▼───────┐ ┌───▼──────────┐          ┌──────▼──────┐
  │ zpush-jmap  │ │  z-bridge    │          │  Stalwart   │
  │ :10000      │ │  :10010      │──JMAP──▶│  (admin UI, │
  │ (ActiveSync)│ │ (Zimbra UI)  │          │   JMAP)     │
  └─────┬───────┘ └──────────────┘          └──────▲──────┘
        │                                          │
        └───────────────JMAP───────────────────────┘
```

### Front-end Nginx Configuration

```nginx
# =============================================================
# mail.example.com — User-facing (public)
# ActiveSync → zpush-jmap, everything else → Z-Bridge
# =============================================================
server {
    listen 443 ssl;
    server_name mail.example.com autodiscover.example.com;

    ssl_certificate     /etc/ssl/certs/mail.example.com.pem;
    ssl_certificate_key /etc/ssl/private/mail.example.com.key;

    # --- zpush-jmap: ActiveSync for mobile devices ---

    location /Microsoft-Server-ActiveSync {
        proxy_pass http://127.0.0.1:10000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;   # Long-lived ActiveSync connections
        proxy_send_timeout 3600s;
        client_max_body_size 50m;
    }

    # Autodiscover — case-insensitive (some clients send /Autodiscover/)
    location ~* ^/autodiscover/ {
        proxy_pass http://127.0.0.1:10000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # --- Project Z-Bridge: Zimbra web UI (default for all other paths) ---
    # Z-Bridge serves the Zimbra UX including password changes, 2FA, etc.
    # See Project Z-Bridge documentation for its specific location directives.
    location / {
        proxy_pass http://127.0.0.1:10010;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

# =============================================================
# mail-admin.example.com — Stalwart admin interface (restricted)
# Firewall to trusted IPs only; users don't need this since
# Z-Bridge handles passwords, 2FA, and account management.
# =============================================================
server {
    listen 443 ssl;
    server_name mail-admin.example.com;

    ssl_certificate     /etc/ssl/certs/mail.example.com.pem;
    ssl_certificate_key /etc/ssl/private/mail.example.com.key;

    # Restrict to trusted networks
    allow 10.0.0.0/8;        # internal network
    allow 192.168.0.0/16;    # internal network
    # allow <your-vpn-cidr>;
    deny all;

    location / {
        proxy_pass https://127.0.0.1:443;   # Stalwart's native HTTPS port
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Scaling with Multiple Hosts

Scale by adding identical hosts and distributing traffic across them. Each host runs the same setup — one zpush-jmap container, one Z-Bridge container, and a front-end Nginx.

```
            DNS round-robin (or load balancer)
    mail.example.com         → A 10.0.0.1, A 10.0.0.2, A 10.0.0.3
    autodiscover.example.com → A 10.0.0.1, A 10.0.0.2, A 10.0.0.3

  Host 1 (10.0.0.1)        Host 2 (10.0.0.2)        Host 3 (10.0.0.3)
  ├── Nginx (443)           ├── Nginx (443)           ├── Nginx (443)
  ├── zpush-jmap (:10000)   ├── zpush-jmap (:10000)   ├── zpush-jmap (:10000)
  ├── z-bridge (:10010)     ├── z-bridge (:10010)     ├── z-bridge (:10010)
  └── state/ mount ─────────┴───────┬─────────────────┘
                                     │
                          ┌──────────▼──────────┐
                          │  Shared State Dir   │
                          │  (zpush/state/ only) │
                          │  NFS / GlusterFS /  │
                          │  RustFS             │
                          └─────────────────────┘

  All hosts ────── JMAP ──────▶ [ Stalwart ]
```

**DNS round-robin** is the simplest approach — add multiple A records for `mail.example.com` and `autodiscover.example.com`. Clients distribute across hosts automatically. No infrastructure changes required.

**Load balancer** (HAProxy, cloud LB, etc.) can be added later for health checks, connection draining, and weighted routing. The same host setup works behind either approach.

### Autodiscovery DNS Setup

ActiveSync clients look for the server in this order:

1. `https://autodiscover.<domain>/autodiscover/autodiscover.xml`
2. `https://<domain>/autodiscover/autodiscover.xml`
3. SRV DNS record `_autodiscover._tcp.<domain>`

**Required DNS records:**

| Type | Name | Value |
|------|------|-------|
| A (one per host) | `mail.example.com` | `10.0.0.1`, `10.0.0.2`, ... |
| A (one per host) | `autodiscover.example.com` | `10.0.0.1`, `10.0.0.2`, ... |
| A | `mail-admin.example.com` | Stalwart host IP (single, not round-robin) |
| SRV | `_autodiscover._tcp.example.com` | `0 0 443 mail.example.com` |

For a single host, use one A record per name. For scaling, add A records for `mail` and `autodiscover` as you add hosts — clients round-robin across them. The `mail-admin` record points to a single host since admin access is restricted.

For automated DNS record management (including Cloudflare), see [zimbra-autodiscover](https://github.com/JimDunphy/zimbra-autodiscover) — it handles creating and verifying the autodiscover DNS records.

### Shared Storage (Multi-Host Only)

Of the three services, only zpush-jmap requires shared storage in a multi-host setup — and only for its sync state directory. Z-Bridge is stateless. Rspamd maintains its own state independently.

**What needs sharing and what doesn't:**

| Directory | Share across hosts? | Reason |
|-----------|-------------------|--------|
| `zpush/state/` | **Yes** | Per-device sync keys. If DNS round-robin sends an iPad to host 2 instead of host 1, host 2 needs the state files or the device re-syncs from scratch. |
| `zpush/config/` | **No** | Static configuration. Deploy identical copies on each host. |
| `zpush/log/` | **No** | Keep local per host. Avoids NFS write contention and lets you identify which host generated a log entry. |

Z-Push state files are organized by device ID in hash subdirectories with **no hostname component** in the paths. Any host can serve any device transparently. Z-Push uses `flock()` with retry loops for concurrent access, which works on NFS v3+ and v4.

**Setup:**

1. Create a shared mount on each host (NFS, GlusterFS, or similar):
   ```bash
   # On each host
   mount -t nfs fileserver:/zpush-state /mnt/zpush-state
   ```

2. Adjust volume mounts in `docker-compose.yml` — only `state/` uses shared storage:
   ```yaml
   volumes:
     - ./zpush/config:/data/config          # local per host
     - /mnt/zpush-state:/data/state         # shared across hosts
     - ./zpush/log:/data/log                # local per host
   ```

**IPC provider caveat:** Z-Push uses an IPC mechanism for push notifications and change signaling between requests. The default (`IpcSharedMemoryProvider`) uses per-host shared memory, which doesn't work across hosts. For multi-host deployments, switch to memcached:

```php
// In Z-Push config.php (zpush/config/config.php)
define('IPC_PROVIDER', 'IpcMemcachedProvider');
define('MEMCACHED_HOST', 'memcached.internal');
define('MEMCACHED_PORT', 11211);
```

Run a shared memcached instance that all hosts can reach. Without this, push notifications may be delayed (the device still syncs correctly, but "instant push" degrades to polling).

**Shared storage options:**

| Option | Pros | Cons |
|--------|------|------|
| NFS | Simple, well-understood, works everywhere | Single point of failure |
| GlusterFS | Distributed, replicated, no SPOF | More complex setup |
| RustFS | High-performance, S3-compatible, modern | Newer, less battle-tested |

For single-host deployments, none of this is needed — the local `nginx/zpush/` directory is sufficient.

## Troubleshooting

### Container won't start
```bash
./manage.sh --logs          # Check startup errors
./manage.sh --health        # Check health endpoint
```

### Authentication failures
- Verify `STALWART_URL` is reachable from inside the container:
  ```bash
  ./manage.sh --console
  curl -v $STALWART_URL/.well-known/jmap
  ```
- Check credentials work with Stalwart's web interface
- For self-signed certificates in lab environments, set `STALWART_SSL_VERIFYPEER=false` and `STALWART_SSL_VERIFYHOST=false`
- For non-HTTPS Stalwart lab endpoints on non-loopback hosts, set `STALWART_ALLOW_INSECURE_HTTP=true`

### Sync issues
- Enable debug logging: set `STALWART_DEBUG=true` in `backend/stalwart/config.php`
- Check logs:
  ```bash
  ./manage.sh --console
  tail -f /data/log/z-push.log
  ```

### Device shows no folders
- Confirm the Z-Push backend is set correctly:
  ```bash
  ./manage.sh --console
  grep BACKEND_PROVIDER /data/config/config.php
  # Should show: BackendStalwart
  ```

### Port conflicts
```bash
./manage.sh --set-port 10001
./manage.sh --restart
```

## Project Structure

```
zpush-jmap/
  VERSION           ← Application release number
  docs/             ← Quick start, internals, task limits, and release runbooks
  backend/stalwart/
    stalwart.php     ← PHP backend — Z-Push ↔ Rust adapter
    tasks.php        ← ActiveSync task mapping and native task operations
    config.php       ← Backend configuration
    mime.php         ← MIME library (from PEAR Mail_Mime)
    mimePart.php     ← MIME part library
  rust-lib/
    src/lib.rs       ← Rust FFI layer — all JMAP protocol work
    Cargo.toml       ← Rust dependencies
  nginx/
    Dockerfile       ← Multi-stage build: compiles Rust, then assembles Debian/PHP/Nginx
    docker-compose.yml
    entrypoint.sh    ← Container initialization
    nginx-zpush.conf ← Nginx routing config
    manage.sh        ← Container lifecycle management
    release.py       ← Version reporting, local verification, and explicit SSH deployment
    env.example      ← Environment variable template
  tests/
    test_jmap.php           ← Optional live JMAP integration suite
    test_backend_*.php      ← Isolated mail, push, and task/WBXML regressions
    test_*_transport.py     ← Compiled Rust/PHP tests with local HTTP fixtures
    test_release.py         ← Verification gates, remote reporting, and rollback checks
    run_tests.sh            ← Live integration runner (Docker-based)
```

## License

This repository is licensed under the MIT License. See `LICENSE`.

Full source for this project is provided in this repository.

Third-party components and upstream dependencies retain their own licenses and terms. See `THIRD_PARTY_LICENSES.md` for details.
