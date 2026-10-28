# Build and verify locally, then deploy explicitly

Run these steps on the build host. Python 3, Docker/Compose, SSH, and gzip are
required. The remote host needs Docker/Compose, Python 3, tar, and flock. The SSH
user must have Docker access and own the deployment checkout. This workflow
updates an existing installation; use QUICK_START.md for a first installation.

## Select and build a release

```bash
cd zpush-jmap/nginx
./manage.sh --set-image local/zpush:0.2.1
./manage.sh --build --refresh
```

`--refresh` passes `--pull --no-cache`: it refreshes base images and repeats the
package installation. A normal build can reuse an old package-install layer.
The Dockerfile installs the distribution's PHP packages; apt does not need to
be run manually inside the container. The build labels the image with a hash
of the implementation and build files.

The root `VERSION` file is the authoritative application version, using
`major.minor.patch` numbering (and optional prerelease/build identifiers).
Before 1.0, increment the minor version for a feature release and patch for
compatible fixes. Task support was introduced in 0.2.0; 0.2.1 adds management
convenience and removes a private hostname from an example. Update VERSION before
building the next application release. Rebuilds of the same application version
can use distinct image tags; their exact identity is the image ID/source hash.

The file is embedded in the backend image and reported by PHP, and the build
records it in the standard `org.opencontainers.image.version` image label.
Deployment includes VERSION in the source bundle. Environment settings cannot
override the application version. `STALWART_BACKEND_VERSION` remains a separate
deployment/build identifier for logs; Z-Push and PHP retain their own versions.
The version command names the current Docker host and distinguishes built from
running software. To inspect the destination after deployment, run `--version`
in that host's checkout; the build host's running container is a separate service.
Once an SSH target is configured below, `./manage.sh --remote-version` reads
the destination's report directly from the build host.

## Verify locally

```bash
./manage.sh --verify-image
./manage.sh --version
```

Verification checks PHP CLI/FPM packages against the minimum fixed Bookworm
version `8.2.35-1~deb12u1`, extension loading, copied source files, mail/push/task
regressions, compiled Rust HTTP fixtures, deployment rollback, and clean startup
with fresh data. Network fixtures use dummy credentials and loopback; startup
uses Docker networking disabled. No live account is changed.

Successful checks create a private receipt in `nginx/.releases/`, recording the
exact image ID and file hashes. Changing an image tag's contents or editing
source/release files invalidates that receipt. Rebuild and verify after source
edits. The PHP minimum is a dated security floor, not a promise that future
vulnerabilities are covered; keep using refreshed builds and current advisories.

## Configure the destination on the build host

Add these to the build host's ignored `nginx/.env`, using your destination:

```dotenv
ZPUSH_DEPLOY_SSH=admin@sync-host
ZPUSH_DEPLOY_DIR=/home/admin/zpush-jmap
```

SSH must already work without an interactive password prompt. Existing host
keys are checked using your SSH configuration. The deployment checkout must
already have its own `nginx/.env` and persistent configuration/state directories.

Hostnames, SSH usernames, and deployment paths are private local settings. The
shipped environment example leaves them blank, and there is no default remote
host. `--remote-version` uses these same settings and only runs the remote
management script's `--version` command; it does not deploy or restart anything.
Its output focuses on that host's running application, image, Z-Push and PHP,
while local build/verification details remain in the build host's `--version`.
Private notes, local environment variants, and release archives are ignored by
Git and excluded from Docker build contexts. The publishing package includes the
generic test environment template, not the local test credentials.
Both commands accept `--host` and `--dir` overrides, for example:

```bash
./manage.sh --remote-version
./manage.sh --remote-version --host admin@sync-host --dir /home/admin/zpush-jmap
```

## Push when ready

```bash
./manage.sh --deploy --dry-run
./manage.sh --deploy
```

The dry run validates the local verification receipt and performs read-only SSH
preflight checks. It does not upload files, restart containers, or alter the
destination. `--host` and `--dir` can override the configured destination.

The actual deployment exports and checksum-verifies the exact tested image,
transfers the public project source, and locks the destination against another
deployment. Private docs, credentials, Git metadata, Rust build output, logs,
and device state are excluded from the source bundle. The destination's `.env`
is preserved, with only its selected image and backend version updated.

Before replacement, it saves the actual running container filesystem as a
private rollback image, including operator-installed package fixes. This uses
Docker's image snapshot operation; it does not create a Git commit. The container
is briefly stopped while its mounted config/state are backed up through a
read-only volume mount. The replacement uses host networking, the existing
volumes, and `up --no-build`. Health, image identity, PHP packages and the Rust
task API are checked after startup. Host Nginx, certificates, firewall rules,
the Docker daemon, and other services are not reconfigured.

## Failure and recovery

A failed replacement restores the previous source and configuration, selects
the rollback image, and starts it again. Current device state is preserved
during automatic rollback; restoring an old state backup after clients advanced
their sync keys can cause inconsistencies. The stopped-state backup is retained
for deliberate recovery, not blindly restored.

The command reports the rollback tag and private backup directory, stored under
the destination's `.releases/<timestamp>/`. A later manual rollback can select
that printed image:

```bash
./manage.sh --set-image local/zpush:rollback-<printed-release-id>
./manage.sh --up-no-build
```

Backups and old images are retained. Review disk use and remove obsolete releases
using your normal retention process. Neither command commits or pushes Git.
