# Docker / Nginx Configuration

This directory contains the Docker build and runtime configuration for the Z-Push Stalwart backend.

## Files

| File | Purpose |
|------|---------|
| `Dockerfile` | Multi-stage build: Rust extension + Debian/PHP-FPM/Nginx |
| `docker-compose.yml` | Service definition with environment variables and volumes |
| `entrypoint.sh` | Container init: config injection, PHP/Nginx tuning, service startup |
| `nginx-zpush.conf` | Nginx routing (ActiveSync, Autodiscover, healthcheck) |
| `host-nginx.conf.example` | Host HTTPS reverse proxy template for a loopback container |
| `manage.sh` | CLI for container lifecycle (build, up, down, logs, etc.) |
| `release.py` | Local verification receipts and explicit SSH deployment with rollback |
| `env.example` | Environment variable template |

## Quick Reference

```bash
./manage.sh --init          # Create .env and data directories
./manage.sh --build --refresh # Pull bases and refresh installed packages
./manage.sh --verify-image  # Run isolated checks and record verified source/image
./manage.sh --deploy --dry-run # Check verified image and deployment target
./manage.sh --deploy        # Explicitly transfer and deploy the verified release
./manage.sh --up            # Build and start
./manage.sh --up-no-build   # Start/recreate using existing image tag
./manage.sh --restart       # Stop, rebuild, and start
./manage.sh --restart-no-build # Stop and start without rebuild
./manage.sh --down          # Stop
./manage.sh --logs          # Tail logs
./manage.sh --health        # Check /healthz
./manage.sh --version       # Application version, image identity, Z-Push and PHP
./manage.sh --remote-version # Versions on the SSH target configured in .env
./manage.sh --backend-version # Show configured/running backend release ID
./manage.sh --zpush-version # Show pinned/running Z-Push version
./manage.sh --console       # Shell into container
./manage.sh --backup        # Backup persistent data
./manage.sh --set-port 9080 # Change listen port
./manage.sh --set-image local/zpush:2026-02-14-push1
./manage.sh --export-image /tmp/zpush.tar.gz
./manage.sh --import-image /tmp/zpush.tar.gz
```

## Persistent Data

Stored in `./zpush/` (created by `--init`):

- `config/` — Z-Push configuration (auto-generated on first run)
- `state/` — Device sync state
- `log/` — Application logs

See the [main README](../README.md) for full documentation.
For the two-stage build/verify and manual deployment process, see
[the release workflow](../docs/RELEASE_WORKFLOW.md).

## Building on hosts with restricted Docker networking

Runtime containers use host networking. Docker image builds have a separate
network setting: set `ZPUSH_BUILD_NETWORK=host` in `.env` if Docker bridge
networking is unavailable, such as when Docker firewall management is disabled.
Both `--build` and `--up` honor this setting.

You can also build on a development host, export the image, and deploy it with
`--import-image` followed by `--up-no-build`. This avoids compilation on the
deployment host; see the [production runbook](../docs/PRODUCTION_DEPLOYMENT.md).

Autodiscover uses the framework's dedicated PHP entry point and persistent
configuration. Startup selects `BackendStalwart` and full-email login for both
ActiveSync and Autodiscover, and relinks their configuration on container recreation.

## Privilege Model

This image intentionally does not set a `USER` directive in `nginx/Dockerfile`.

- The Nginx master process starts as root so it can bind privileged ports (for example `443`) and manage worker processes.
- Nginx workers and PHP-FPM workers run as `www-data`.
- Runtime application/state paths are owned by `www-data` (`/var/www/zpush`, `/data`).

If you only deploy on unprivileged ports, you can run with additional runtime hardening, but that is not the default model for this project.
