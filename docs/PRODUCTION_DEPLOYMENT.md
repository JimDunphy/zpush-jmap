# Production Deployment Runbook

This runbook is for deploying `zpush-jmap` safely to production with repeatable commands.

All operational commands use `nginx/manage.sh` (no direct `docker compose` usage required).

For routine upgrades, follow [the release workflow](RELEASE_WORKFLOW.md): update
root `VERSION`, build and verify on the development host, then manually run
`--deploy`. Host/user/path settings stay in the ignored local `.env`.
`--remote-version` reports the destination's running software without deploying.
For a first host, follow [QUICK_START.md](QUICK_START.md).

## Goals

- Keep existing mobile device sync state intact.
- Deploy predictable image versions.
- Keep backend exposure controlled by host firewall policy.
- Support fast rollback.

## Critical Rules

- Never overwrite production `nginx/zpush/state/`.
- Never copy development `nginx/zpush/` into production.
- Keep `ZPUSH_BIND_ADDRESS` set to loopback (`127.0.0.1`) or a dedicated internal service IP.
- Keep Stalwart upstream on HTTPS in production:
  - `STALWART_URL=https://...`
  - `STALWART_SSL_VERIFYPEER=true`
  - `STALWART_SSL_VERIFYHOST=true`
  - `STALWART_ALLOW_INSECURE_HTTP=false`
- Set `STALWART_BACKEND_VERSION` to a release identifier before each deployment (for example `2026.02.23-rc1`).
- Z-Push upstream is pinned by Dockerfile build args (`ARG ZPUSH_VERSION` + `ARG ZPUSH_SHA256`) and does not automatically track "latest".

If `nginx/zpush/state/` is deleted or replaced, clients may require full re-sync and can appear to "break."

## Manage Script Restart Commands

From `nginx/`:

```bash
./manage.sh --restart           # down -> up --build
./manage.sh --restart-no-build  # down -> up --no-build
```

Use `--restart` for source changes that require a rebuild, and `--restart-no-build` for config/image-tag changes where the image is already present.


## Update Playbooks

### 1) Update Z-Push Upstream Version

Use this when you want a newer upstream Z-Push release in the container image.
Check current pinned/runtime version first:

```bash
cd nginx
./manage.sh --zpush-version
```

1. Pick target Z-Push tag and SHA256 from upstream release artifacts.
2. Update build args in `nginx/Dockerfile`:
   - `ARG ZPUSH_VERSION=...`
   - `ARG ZPUSH_SHA256=...`
3. Build and deploy a new image tag.

Example (from repo root):

```bash
vi nginx/Dockerfile

cd nginx
./manage.sh --set-image local/zpush:2026-03-01-zpush-2.7.7
./manage.sh --build
./manage.sh --up-no-build
./manage.sh --health
./manage.sh --logs
```

Recommended verification:

```bash
cd ..
rg -n "ARG ZPUSH_VERSION|ARG ZPUSH_SHA256" nginx/Dockerfile
```

### 2) Update Stalwart Backend and Rebuild

Use this when `backend/stalwart` and/or `rust-lib` changed.

1. Pull/copy updated source.
2. Bump the application release in root `VERSION` and select a new image tag.
3. Build and verify locally using temporary test containers.
4. Manually deploy to the configured SSH target when ready.
5. Check the running release with `--remote-version`.

Example (from repo root):

```bash
vi VERSION
cd nginx
./manage.sh --set-image local/zpush:0.2.4
./manage.sh --build
./manage.sh --verify-image
./manage.sh --deploy --dry-run
./manage.sh --deploy
./manage.sh --remote-version
```

The application version comes from root `VERSION`, embedded in the image as
`STALWART_APPLICATION_VERSION`. `STALWART_BACKEND_VERSION` is a deployment/build
identifier used in logs; the SSH deployment command derives it from the image
tag. Image IDs and source hashes identify exact builds. Z-Push and PHP keep
their own upstream/package versions. `--version` distinguishes local built and
running software; it does not query the remote destination.


## Deployment Models

Choose one:

1. Build from source on production host.
2. Build in lab and promote prebuilt image to production (recommended).

Both are valid; model 2 gives better change control.

## One-Time Production Host Setup

```bash
git clone <repo-url> /srv/zpush-jmap
cd /srv/zpush-jmap/nginx
./manage.sh --init
```

Edit `nginx/.env` for production values:

- `ZPUSH_BIND_ADDRESS=127.0.0.1` (or dedicated internal service IP)
- `ZPUSH_HTTP_PORT=10000` (or your chosen backend port)
- `STALWART_URL=https://stalwart.example.com` (example)
- `STALWART_BACKEND_VERSION=2026.02.23-rc1` (example release ID)
- `STALWART_SSL_VERIFYPEER=true`
- `STALWART_SSL_VERIFYHOST=true`
- `STALWART_ALLOW_INSECURE_HTTP=false`

Optional ABQ hardening (device allow/block policy):

- `STALWART_ABQ_ENABLED=true`
- `STALWART_ABQ_ALLOWED_RULES=dt:iphone,dt:ipad`
- `STALWART_ABQ_BLOCKED_RULES=ua:*legacy-eas-client*`
- `STALWART_ABQ_QUARANTINED_RULES=` (optional)
- `STALWART_ABQ_QUARANTINE_BY_DEFAULT=false`

`block` and `quarantine` actions are both enforced as login deny in the current backend.

## ABQ Rollout Checklist (Audit-First)

Use this sequence to avoid unexpected client lockouts.

### Phase 1: Enable audit mode only

Set in `nginx/.env`:

- `STALWART_ABQ_ENABLED=true`
- `STALWART_ABQ_ALLOWED_RULES=`
- `STALWART_ABQ_BLOCKED_RULES=`
- `STALWART_ABQ_QUARANTINED_RULES=`
- `STALWART_ABQ_QUARANTINE_BY_DEFAULT=false`

Deploy:

```bash
cd nginx
./manage.sh --up-no-build
./manage.sh --logs
```

Collect device fingerprints from logs:

```bash
grep 'Stalwart->EvaluateDevicePolicy()' zpush/log/z-push.log | tail -n 200
```

### Phase 2: Add explicit block rules first

Start with clearly unwanted clients only:

- Example: `STALWART_ABQ_BLOCKED_RULES=ua:*legacy-eas-client*,ua:*unknown-eas*`

Redeploy and verify no trusted device regressions:

```bash
./manage.sh --up-no-build
./manage.sh --logs
```

### Phase 3: Add allowlist enforcement (optional, strict)

Only after inventory is complete, set `STALWART_ABQ_ALLOWED_RULES` for your approved fleet.

Example:

- `STALWART_ABQ_ALLOWED_RULES=dt:iphone,dt:ipad,ua:*outlook*`
- Keep `STALWART_ABQ_QUARANTINE_BY_DEFAULT=false` unless you explicitly want unmatched devices treated as quarantine (also denied today).

### Fast rollback if any lockout occurs

```bash
cd nginx
sed -ri 's/^STALWART_ABQ_ENABLED=.*/STALWART_ABQ_ENABLED=false/' .env
./manage.sh --up-no-build
./manage.sh --health
```

## Pre-Deploy Checklist

From `nginx/` on production:

```bash
./manage.sh --backup
./manage.sh --zpush-version
```

Record current deployed image for rollback:

```bash
grep '^ZPUSH_IMAGE=' .env
grep '^STALWART_BACKEND_VERSION=' .env
```

Confirm host-mode networking is still configured in source:

```bash
grep -n 'network_mode: host' docker-compose.yml
```

If `STALWART_URL` is non-loopback, confirm TLS verification is enabled in runtime `.env`:

```bash
grep -E '^STALWART_URL=|^STALWART_SSL_VERIFYPEER=|^STALWART_SSL_VERIFYHOST=|^STALWART_ALLOW_INSECURE_HTTP=' .env
```

Expected for production non-loopback upstreams:

- `STALWART_SSL_VERIFYPEER=true`
- `STALWART_SSL_VERIFYHOST=true`
- `STALWART_ALLOW_INSECURE_HTTP=false`


## Path A: Build From Source on Production

From `nginx/`:

```bash
./manage.sh --build
./manage.sh --up-no-build
./manage.sh --health
./manage.sh --logs
```

Use this when production is allowed to compile and fetch dependencies during maintenance windows.

## Path B (Recommended): Build in Lab, Promote Image

### Step 1: Build and Export in Lab

From lab `nginx/`:

```bash
./manage.sh --set-image local/zpush:2026-02-14-push1
./manage.sh --build
./manage.sh --export-image /tmp/zpush-2026-02-14-push1.tar.gz
sha256sum /tmp/zpush-2026-02-14-push1.tar.gz
```

Copy artifact to production (example):

```bash
scp /tmp/zpush-2026-02-14-push1.tar.gz admin@prod-host:/tmp/
```

### Step 2: Import and Deploy on Production

From production `nginx/`:

```bash
./manage.sh --backup
./manage.sh --zpush-version
./manage.sh --import-image /tmp/zpush-2026-02-14-push1.tar.gz
./manage.sh --set-image local/zpush:2026-02-14-push1
./manage.sh --up-no-build
./manage.sh --health
./manage.sh --logs
```

## What to Copy vs What Not to Copy

Copy to production:

- New git source (code/config/docs) via `git pull`, or
- Prebuilt image artifact (`.tar` / `.tar.gz`) via `--import-image`.

Do not copy from development to production:

- `nginx/zpush/state/`
- `nginx/zpush/config/`
- `nginx/zpush/log/`
- `nginx/.env` (unless you intentionally merge values)

## Post-Deploy Verification

### 1. Container health

```bash
cd nginx
./manage.sh --health
```

### 2. Listener check (host)

```bash
ss -ltnp | grep -E ':443\\b|:10000\\b'
```

Expected:

- `:443` is exposed by your front-end TLS proxy per policy.
- `:10000` is bound only to loopback/internal service IP (not public wildcard).

### 3. Z-Push version confirmation

```bash
cd nginx
./manage.sh --zpush-version
```

Expected: runtime Z-Push version matches your pinned Dockerfile version and intended release plan.

### 4. Backend version confirmation

```bash
cd nginx
./manage.sh --backend-version
```

Expected: runtime `STALWART_BACKEND_VERSION` matches the release ID planned for this change.

### 5. ActiveSync path check from a trusted source

```bash
curl -kI https://mail.example.com/Microsoft-Server-ActiveSync
```

Unauthenticated `401` is normal and usually indicates routing is working.

## State and Mobile Client Safety

If `nginx/zpush/state/` is preserved across upgrades:

- Existing device partnerships and sync keys remain.
- Mobile users usually continue without re-adding accounts.

If state is lost/replaced:

- Devices can trigger full re-sync.
- In worst cases, users must remove/re-add accounts.

## Rollback Procedure

Use image-tag rollback (fastest):

```bash
cd nginx
./manage.sh --set-image <previous-image:tag>
./manage.sh --up-no-build
./manage.sh --health
./manage.sh --logs
```

If needed, restore data backup created by `./manage.sh --backup` per your site recovery policy.
./manage.sh --zpush-version

## Suggested Change Ticket Template

- Change type: `zpush-jmap image upgrade`
- Source tag: `<new tag>`
- Rollback tag: `<previous tag>`
- Backup file: `<zpush-backup-YYYYMMDD-HHMMSS.tar.gz>`
- Validation:
  - `./manage.sh --health` = OK
  - listener check reviewed
  - test device sync confirmed
