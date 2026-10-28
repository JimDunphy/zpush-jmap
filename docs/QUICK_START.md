# First-host quick start

This follows the deployment rehearsal: build on a development machine, transfer
the image, and run it behind host Nginx. Host Nginx owns HTTPS port 443; the
container serves ActiveSync on `127.0.0.1:10000` using Docker host networking.
The host can route other applications to separate containers later.

## 1. Prepare the host

Have these available before starting:

- A Linux host with Docker Engine, the Compose plugin, and host Nginx.
- A DNS name for the service, such as `sync.example.com`, and a certificate/key
  valid for that name. Use your existing certificate renewal process.
- A reachable Stalwart JMAP endpoint, such as `https://stalwart.example.com`,
  and an account that can sign in to it.
- Your host firewall policy allowing client HTTPS connections to the service and
  outbound HTTPS from this host to Stalwart. Apply any source-IP restrictions
  using your existing firewall administration process.

The Compose service uses `network_mode: host` and has no published-port mappings.
Its loopback listener should not be exposed directly to the Internet. Check
existing listeners before assigning ports:

```bash
sudo ss -ltnp
```

Use a separate Nginx virtual host for the service when Nginx already serves
other applications on 443.

## 2. Build and export on the development machine

Start from a checkout of this source. Use a release tag of your choice consistently
on both hosts; the commands below use the application release `local/zpush:0.2.1`
as an example. The current application version is recorded in root `VERSION`.

```bash
cd zpush-jmap/nginx
./manage.sh --init
./manage.sh --set-image local/zpush:0.2.1
./manage.sh --build --refresh
./manage.sh --verify-image
./manage.sh --export-image /tmp/zpush-0.2.1.tar.gz
cd /tmp
sha256sum zpush-0.2.1.tar.gz > zpush-0.2.1.tar.gz.sha256
scp zpush-0.2.1.tar.gz zpush-0.2.1.tar.gz.sha256 admin@sync-host:/tmp/
```

If Docker bridge networking is unavailable during compilation, set
`ZPUSH_BUILD_NETWORK=host` in the build machine's `nginx/.env`. Runtime host
networking and build networking are separate settings.

This produces a deployable image from the checked-in source. Base images and
system packages can change over time; rebuilding is not guaranteed to produce
identical bytes. Keep the exported image and checksum when exact rollback or
promotion of a tested build matters. The Z-Push source download is pinned by
version and checksum in the Dockerfile.

Verification uses temporary local containers and dummy test data; it does not
replace any persistent development container. For later upgrades of an existing
installation, [the release workflow](RELEASE_WORKFLOW.md) provides the explicit
`--deploy` command with a verification gate, backup, and rollback.

## 3. Initialize the deployment checkout

On the deployment host, clone your published repository into a directory owned
by your administrative user:

```bash
git clone <repository-url> zpush-jmap
cd zpush-jmap/nginx
./manage.sh --init
```

Edit `nginx/.env` in this checkout:

```dotenv
ZPUSH_IMAGE=local/zpush:0.2.1
ZPUSH_BIND_ADDRESS=127.0.0.1
ZPUSH_HTTP_PORT=10000
STALWART_URL=https://stalwart.example.com
STALWART_BACKEND_VERSION=0.2.1
STALWART_SSL_VERIFYPEER=true
STALWART_SSL_VERIFYHOST=true
STALWART_ALLOW_INSECURE_HTTP=false
STALWART_PUSH_CHANGES_ENABLED=true
```

Users enter their Stalwart credentials on their devices; no shared mailbox
password belongs in this deployment file. The container initializes its
persistent configuration and state directories on first startup.

## 4. Import and start

Verify the transferred archive, then return to the deployment checkout:

```bash
cd /tmp
sha256sum -c zpush-0.2.1.tar.gz.sha256
cd /path/to/zpush-jmap/nginx
./manage.sh --import-image /tmp/zpush-0.2.1.tar.gz
./manage.sh --up-no-build
./manage.sh --health
./manage.sh --version
./manage.sh --backend-version
./manage.sh --zpush-version
```

Use `--up-no-build` when promoting an image. `--up` and `--restart` build an image.
For subsequent upgrades, preserve `nginx/zpush/` and merge host-specific `.env`
settings; see the [production runbook](PRODUCTION_DEPLOYMENT.md).

## 5. Configure host HTTPS and certificate renewal

Copy [the host Nginx example](../nginx/host-nginx.conf.example) into your host's
Nginx configuration directory as a new virtual-host file. Replace the hostname
and certificate paths before enabling it. The example routes ActiveSync and
Autodiscover to the container and returns 404 for other paths.

Certificate paths can point to existing files shared with another service; a
second copy is not required. Ensure the Nginx master can read the certificate
and private key. Your renewal deployment hook should update those files and run,
with the required privileges:

```bash
nginx -t && systemctl reload nginx
```

For an existing `acme.sh --cron` workflow, add this reload to the deploy hook
that already installs the renewed certificate. The container does not terminate
TLS and does not need a certificate-renewal restart.

Validate and start or reload host Nginx using your host's service manager. Then
check from an allowed client network:

```bash
curl -I https://sync.example.com/Microsoft-Server-ActiveSync
curl -I https://sync.example.com/autodiscover/autodiscover.xml
```

Without credentials, HTTP 401 is expected. TLS certificate verification should
succeed. A public request to `/healthz` should return 404; use `--health` locally.

## 6. Add and validate a device

Add a Microsoft Exchange account in the device's mail settings. Use
`sync.example.com` as the server and the full Stalwart email address as the
username. Check initial Inbox loading, opening a full message, and new-message
arrival. Logs are available through `nginx/manage.sh --logs` and under
`nginx/zpush/log/`.

**Known limitation:** the iOS 26.6.2 rehearsal showed immediate arrival push,
but messages deleted in another client could remain visible after iOS
acknowledged their ActiveSync deletion. Pull down to refresh the Inbox after
external deletions. Disabling threading did not resolve it; the root cause is
still unresolved.

## Source validation

The [README test section](../README.md#running-tests) lists the isolated PHP
backend regression suites and Rust push-transport fixture. These use fake
mailboxes. The separate live JMAP integration suite uses credentials from
`tests/.env` and can send mail/change mailbox data; it is not needed to install
the service.
