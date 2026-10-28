# Historical Security Remediation Review: Z-Push Stalwart JMAP Backend

**Date:** 2026-02-22
**Status:** No open code-level findings from the active remediation set
**Review mode:** Non-destructive code/config review

This records the remediation set reviewed on the date above. It is not a new
security audit of subsequent task and deployment changes. Current release
verification and operational requirements are described in
[RELEASE_WORKFLOW.md](RELEASE_WORKFLOW.md) and [HARDENING.md](HARDENING.md).

---

## Scope and Assumptions

This review is aligned to the intended deployment model:

- Front-end NGINX terminates TLS on `443`.
- zpush-jmap runs with `network_mode: host` to remain under host firewall control.
- Backend listener exposure is restricted to trusted source ranges.
- Production uses loopback or dedicated internal bind address for backend listener.

---

## Remediation Summary (Completed)

### 1. Build supply-chain hardening

**Files:** `nginx/Dockerfile`

Completed changes:

- Composer now comes from the trusted `composer:2` image (`COPY --from=composer:2`).
- Z-Push build source is pinned to an explicit version (`ARG ZPUSH_VERSION`).
- Z-Push archive integrity is verified with SHA256 (`ARG ZPUSH_SHA256` + `sha256sum -c`).

### 2. Query window safety for large tenants

**File:** `backend/stalwart/stalwart.php`

Completed changes:

- Replaced single-call `50000` ID query pulls with paged retrieval.
- Added page size cap (`10000`) and retained overall cap (`50000`) for compatibility.
- Added warning logs when cap is reached.

### 3. Authentication error detail sanitization

**File:** `backend/stalwart/stalwart.php`

Completed changes:

- Authentication exception returned to caller is now generic (`Access denied.`).
- Detailed backend error remains in server log path only.

### 4. NGINX version token hardening

**File:** `nginx/nginx-zpush.conf`

Completed changes:

- Added `server_tokens off;`.

### 5. Runtime TLS verification checklist

**File:** `docs/PRODUCTION_DEPLOYMENT.md`

Completed changes:

- Added pre-deploy verification step for runtime `.env` TLS flags when using non-loopback `STALWART_URL`.

---

## Residual Operational Requirements

These remain operator responsibilities for production safety:

- Keep `STALWART_SSL_VERIFYPEER=true` and `STALWART_SSL_VERIFYHOST=true` for non-loopback upstreams.
- Keep `STALWART_ALLOW_INSECURE_HTTP=false` in production.
- Keep backend listener restricted by host firewall/trusted ranges.
- Keep public/mobile access only on front-end TLS endpoint.

---

## Current Priority

No additional code changes are required for the remediated set. Future hardening can proceed as standard backlog work (for example: optional container debug-tool reduction, temp-file path isolation, or runtime secret handling refinements).
