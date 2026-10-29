#!/usr/bin/env python3
"""Verify an image locally, then explicitly deploy that exact image over SSH."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import tarfile
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[1]
RELEASES = ROOT / 'nginx/.releases'
LABEL = 'io.zpush.source-sha256'
VERSION_LABEL = 'org.opencontainers.image.version'


def application_version(root=ROOT):
    version = (root / 'VERSION').read_text().strip()
    if not re.fullmatch(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?', version):
        raise RuntimeError('VERSION must contain a semantic version, such as 0.2.0')
    prerelease = version.split('+', 1)[0].partition('-')[2]
    if any(part.isdigit() and len(part) > 1 and part.startswith('0') for part in prerelease.split('.')):
        raise RuntimeError('Numeric prerelease identifiers cannot have leading zeroes')
    return version


def run(args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)


def capture(args):
    return run(args, stdout=subprocess.PIPE, text=True).stdout.strip()


def source_files(root=ROOT):
    paths = []
    for pattern in ('backend/stalwart/*', 'rust-lib/src/**/*.rs'):
        paths.extend(p for p in root.glob(pattern) if p.is_file())
    paths.extend(root / p for p in (
        'VERSION', 'rust-lib/Cargo.toml', 'rust-lib/Cargo.lock', '.dockerignore',
        'nginx/Dockerfile', 'nginx/entrypoint.sh', 'nginx/nginx-zpush.conf',
        'nginx/docker-compose.yml',
    ))
    return sorted(set(p.relative_to(root).as_posix() for p in paths))


def manifest(names, root=ROOT):
    return {name: hashlib.sha256((root / name).read_bytes()).hexdigest() for name in sorted(names)}


def fingerprint(root=ROOT):
    return hashlib.sha256(json.dumps(manifest(source_files(root), root), sort_keys=True).encode()).hexdigest()


def release_files():
    names = source_files()
    names += [p.relative_to(ROOT).as_posix() for p in (ROOT / 'docs').glob('*.md')]
    names += [p.relative_to(ROOT).as_posix() for p in (ROOT / 'tests').glob('test_*.php')]
    names += [p.relative_to(ROOT).as_posix() for p in (ROOT / 'tests').glob('test_*.py')]
    names += ['README.md', 'LICENSE', 'THIRD_PARTY_LICENSES.md', '.gitignore', '.gitattributes',
              'nginx/manage.sh', 'nginx/release.py', 'nginx/env.example', 'nginx/README.md',
              'nginx/host-nginx.conf.example', 'tests/run_tests.sh', 'tests/.env.example',
              'tools/eas_trace.py']
    return sorted(set(names))


def image_info(image):
    return json.loads(capture(['docker', 'image', 'inspect', image]))[0]


def receipt_path(image_id):
    return RELEASES / (image_id.replace(':', '-') + '.json')


def validate_receipt(receipt, image, current_manifest, current_fingerprint):
    if receipt.get('image_id') != image['Id']:
        raise RuntimeError('Image changed since verification; run --verify-image again')
    if receipt.get('files') != current_manifest or receipt.get('source_sha256') != current_fingerprint:
        raise RuntimeError('Source or release files changed since verification; rebuild and verify again')
    if (image['Config'].get('Labels') or {}).get(LABEL) != current_fingerprint:
        raise RuntimeError('Image was not built from this source; build using manage.sh')


def verify(image):
    info = image_info(image)
    start = manifest(release_files())
    source_hash = fingerprint()
    if (info['Config'].get('Labels') or {}).get(LABEL) != source_hash:
        raise RuntimeError('Build this source using manage.sh --build before verifying')
    if (info['Config'].get('Labels') or {}).get(VERSION_LABEL) != application_version():
        raise RuntimeError('Image application version does not match VERSION; rebuild')
    print('Checking PHP security packages, extension loading and image/source contents...', flush=True)
    run(['docker', 'run', '--rm', '--network', 'none', '--entrypoint', 'sh', image, '-ec',
         'for p in php8.2-cli php8.2-fpm; do '
         'v=$(dpkg-query -W -f=\'${Version}\' "$p"); '
         'dpkg --compare-versions "$v" ge "8.2.35-1~deb12u1"; echo "$p $v"; done; '
         'php -r \'if (!method_exists("JmapClient", "getTasks")) exit(1);\''])
    expected = {name: 'var/www/zpush/' + name for name in source_files() if name.startswith('backend/')}
    expected.update({'VERSION': 'var/www/zpush/backend/stalwart/VERSION', 'nginx/entrypoint.sh': 'entrypoint.sh', 'nginx/nginx-zpush.conf': 'etc/nginx/conf.d/zpush.conf'})
    actual = capture(['docker', 'run', '--rm', '--network', 'none', '--entrypoint', 'sha256sum', image] + ['/' + p for p in expected.values()])
    hashes = {line.split(None, 1)[1].lstrip('/'): line.split()[0] for line in actual.splitlines()}
    for name, location in expected.items():
        if start[name] != hashes[location]:
            raise RuntimeError('Image/source mismatch: ' + name)
    for suite in ('test_backend_sync.php', 'test_backend_push.php', 'test_backend_tasks.php'):
        run(['docker', 'run', '--rm', '--network', 'none', '--entrypoint', 'php',
             '-v', str(ROOT) + ':/workspace:ro', image, '/workspace/tests/' + suite])
    for suite in ('test_push_transport.py', 'test_tasks_transport.py', 'test_autodiscover.py', 'test_eas_lifecycle.py'):
        run(['python3', str(ROOT / 'tests' / suite), '--image', image])
    run(['python3', str(ROOT / 'tests/test_release.py')])
    run(['python3', str(ROOT / 'tests/test_eas_trace.py')])
    name = 'zpush-verify-' + uuid.uuid4().hex[:12]
    try:
        run(['docker', 'run', '-d', '--pull', 'never', '--network', 'none', '--name', name,
             '-e', 'STALWART_URL=https://stalwart.example.com', image], stdout=subprocess.DEVNULL)
        for attempt in range(30):
            health = subprocess.run(['docker', 'exec', name, 'curl', '-fsS', 'http://127.0.0.1:10000/healthz'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            if health.returncode == 0:
                break
            time.sleep(0.5)
        else:
            raise RuntimeError('Fresh container failed its health check')
        runtime_version = capture(['docker', 'exec', name, 'php', '-r',
            "require '/var/www/zpush/backend/stalwart/config.php'; echo STALWART_APPLICATION_VERSION;"])
        if runtime_version != application_version():
            raise RuntimeError('Running application version does not match VERSION')
        for endpoint in ('Microsoft-Server-ActiveSync', 'autodiscover/autodiscover.xml'):
            code = capture(['docker', 'exec', name, 'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', 'http://127.0.0.1:10000/' + endpoint])
            if code != '401':
                raise RuntimeError('Expected authentication challenge at ' + endpoint + ', got ' + code)
    finally:
        subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if manifest(release_files()) != start or fingerprint() != source_hash:
        raise RuntimeError('Source changed during verification; verify again')
    receipt = dict(application_version=application_version(), image_id=info['Id'], image_tag=image, source_sha256=source_hash, files=start,
                   verified_at=time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), checks='php,source,sync,push,tasks,transport,autodiscover,eas-lifecycle,trace,release,startup')
    RELEASES.mkdir(mode=0o700, exist_ok=True)
    receipt_path(info['Id']).write_text(json.dumps(receipt, indent=2) + '\n')
    print('VERIFIED ' + image + ' ' + info['Id'], flush=True)


def show_version(image):
    print('Checkout application: ' + application_version())
    print('Configured image: ' + image)
    try:
        print('Docker host: ' + capture(['docker', 'info', '--format', '{{.Name}}']))
    except (OSError, subprocess.CalledProcessError):
        print('Docker host: unavailable')
    try:
        info = image_info(image)
        print('Built application: ' + str((info['Config'].get('Labels') or {}).get(VERSION_LABEL, 'unversioned')))
        print('Built image ID: ' + info['Id'])
        print('Built source SHA256: ' + str((info['Config'].get('Labels') or {}).get(LABEL, 'unknown')))
        path = receipt_path(info['Id'])
        verified = 'not verified'
        if path.exists():
            try:
                receipt = json.loads(path.read_text())
                validate_receipt(receipt, info, manifest(release_files()), fingerprint())
                verified = 'verified at ' + receipt['verified_at']
            except RuntimeError:
                verified = 'stale; verify again'
        print('Local verification: ' + verified)
    except (OSError, subprocess.CalledProcessError):
        print('Built image: unavailable locally')
    env_file = ROOT / 'nginx/.env'
    if not env_file.exists():
        print('Running application: no deployment configuration')
        return
    try:
        cid = capture(['docker', 'compose', '-p', 'zpush-stalwart', '--env-file', str(env_file),
                       '-f', str(ROOT / 'nginx/docker-compose.yml'), 'ps', '-q', 'stalwart-sync'])
        if not cid:
            print('Running application: container not running on this host')
            return
        runtime = json.loads(capture(['docker', 'exec', cid, 'php', '-r',
            "require '/var/www/zpush/backend/stalwart/config.php'; echo json_encode(array('application'=>defined('STALWART_APPLICATION_VERSION')?STALWART_APPLICATION_VERSION:'unversioned','release'=>defined('STALWART_BACKEND_VERSION')?STALWART_BACKEND_VERSION:'unknown')); "]))
        print('Running application: ' + runtime['application'])
        print('Running release: ' + runtime['release'])
        print('Running image: ' + capture(['docker', 'inspect', '--format', '{{.Config.Image}} ({{.Image}})', cid]))
        print('Running Z-Push: ' + capture(['docker', 'exec', cid, 'cat', '/var/www/zpush/version']))
        print('Running PHP-FPM package: ' + capture(['docker', 'exec', cid, 'dpkg-query', '-W', '-f=${Version}', 'php8.2-fpm']))
    except (OSError, subprocess.CalledProcessError):
        print('Running versions: unavailable')


REMOTE = r'''set -euo pipefail
root=$1
stage=$2
image=$3
rollback=$4
image_id=$5
exec 9>"$root/.release.lock"
flock -n 9 || { echo 'Another deployment is running'; exit 1; }
cd "$stage"
sha256sum -c SHA256SUMS
docker load -i image.tar.gz
docker image inspect "$image_id" > /dev/null
docker tag "$image_id" "$image"
cd "$root/nginx"
cid=$(docker compose -p zpush-stalwart --env-file .env -f docker-compose.yml ps -q stalwart-sync)
test -n "$cid" || { echo 'Expected an existing deployment'; exit 1; }
# Retain the actual running filesystem, including any earlier operator patches.
docker commit --pause=false "$cid" "$rollback" > "$stage/rollback-image-id"
cp .env "$stage/env.before"
tar -czf "$stage/source.before.tar.gz" --exclude=./nginx/zpush --exclude=./nginx/.env --exclude=./nginx/.releases --exclude=./rust-lib/target --exclude=./.git --exclude=./docs-private --exclude=./.releases -C "$root" .
restore() {
    code=$?
    trap - EXIT
    if [ "$code" -ne 0 ]; then
        echo 'Deployment failed; restoring previous source and rollback image'
        tar -xzf "$stage/source.before.tar.gz" --no-same-owner -C "$root"
        cp "$stage/env.before" "$root/nginx/.env"
        bash "$root/nginx/manage.sh" --set-image "$rollback"
        bash "$root/nginx/manage.sh" --up-no-build
        echo "Rollback image: $rollback; backup: $stage"
    fi
    exit "$code"
}
trap restore EXIT
docker compose -p zpush-stalwart --env-file .env -f docker-compose.yml stop stalwart-sync
docker run --rm --network none --volumes-from "$cid:ro" --entrypoint tar "$rollback" -czf - -C /data config state > "$stage/state.before.tar.gz"
tar -xzf "$stage/source.tar.gz" --no-same-owner -C "$root"
python3 - "$root" "$stage/verification.json" <<'PY'
from pathlib import Path
import hashlib,json,sys
root=Path(sys.argv[1])
for name,expected in json.loads(Path(sys.argv[2]).read_text())['files'].items():
    if hashlib.sha256((root/name).read_bytes()).hexdigest()!=expected:
        raise SystemExit('Transferred source hash mismatch: '+name)
PY
bash "$root/nginx/manage.sh" --set-image "$image"
python3 - "$root/nginx/.env" "$image" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); lines=p.read_text().splitlines()
lines=[x for x in lines if not x.startswith('STALWART_BACKEND_VERSION=')]
lines.append('STALWART_BACKEND_VERSION='+sys.argv[2].rsplit(':',1)[-1])
p.write_text('\n'.join(lines)+'\n')
PY
bash "$root/nginx/manage.sh" --up-no-build
for attempt in $(seq 1 30); do
    if bash "$root/nginx/manage.sh" --health > /dev/null 2>&1; then break; fi
    sleep 1
done
bash "$root/nginx/manage.sh" --health
cid=$(docker compose -p zpush-stalwart --env-file .env -f docker-compose.yml ps -q stalwart-sync)
test "$(docker inspect --format '{{.Image}}' "$cid")" = "$image_id"
docker exec "$cid" php -r 'if (!method_exists("JmapClient", "getTasks")) exit(1);'
docker exec "$cid" sh -ec 'for p in php8.2-cli php8.2-fpm; do v=$(dpkg-query -W -f='"'"'${Version}'"'"' "$p"); dpkg --compare-versions "$v" ge "8.2.35-1~deb12u1"; echo "$p $v"; done'
echo "DEPLOYED $image; rollback image: $rollback; backup: $stage"
'''


def validate_target(host, directory):
    if not re.fullmatch(r'[A-Za-z0-9_.@-]+', host or '') or host.startswith('-'):
        raise RuntimeError('Set ZPUSH_DEPLOY_SSH to an SSH host, such as admin@sync-host')
    if not re.fullmatch(r'/[A-Za-z0-9_./-]+', directory or '') or str(Path(directory)) == '/' or '..' in Path(directory).parts:
        raise RuntimeError('Set ZPUSH_DEPLOY_DIR to the absolute deployment checkout path')


def ssh(host, script, *args):
    return run(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', host,
                shlex.join(['bash', '-s', '--', *args])], input=script, text=True)


def show_remote_version(host, directory):
    validate_target(host, directory)
    print('Remote target: ' + host + ':' + directory, flush=True)
    ssh(host, 'set -euo pipefail\nscript="$1/nginx/manage.sh"\n'
        'test -f "$script" || { echo "No management script in configured remote checkout" >&2; exit 1; }\n'
        'bash "$script" --version | while IFS= read -r line; do\n'
        '  case "$line" in\n'
        '    "Docker host:"*|"Running "*) printf "%s\\n" "$line" ;;\n'
        '  esac\n'
        'done\n', directory)


def deploy(image, host, directory, dry_run):
    validate_target(host, directory)
    info = image_info(image)
    receipt = json.loads(receipt_path(info['Id']).read_text())
    validate_receipt(receipt, info, manifest(release_files()), fingerprint())
    ssh(host, 'set -e\ntest -f "$1/nginx/.env"\ntest -d "$1/nginx/zpush/state"\ndocker info > /dev/null\ncommand -v python3 > /dev/null\ncommand -v flock > /dev/null\ndf -h "$1"\n', directory)
    print('Verified source/image: ' + image + '\nTarget: ' + host + ':' + directory, flush=True)
    if dry_run:
        print('DRY RUN: preflight passed; would transfer source/image, back up current deployment, recreate container, and check health.')
        return
    release = time.strftime('%Y%m%dT%H%M%SZ', time.gmtime()) + '-' + uuid.uuid4().hex[:6]
    stage = directory + '/.releases/' + release
    rollback = 'local/zpush:rollback-' + release.lower()
    RELEASES.mkdir(mode=0o700, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='transfer-', dir=RELEASES) as temp:
        temp = Path(temp)
        with tarfile.open(temp / 'source.tar.gz', 'w:gz') as archive:
            for name in receipt['files']:
                archive.add(ROOT / name, arcname=name, recursive=False)
        with (temp / 'image.tar.gz').open('wb') as out:
            save = subprocess.Popen(['docker', 'save', info['Id']], stdout=subprocess.PIPE)
            try:
                run(['gzip', '-c'], stdin=save.stdout, stdout=out)
            finally:
                save.stdout.close()
            if save.wait(): raise RuntimeError('Image export failed')
        # Guard against concurrent edits or an image tag being replaced during export.
        validate_receipt(receipt, image_info(image), manifest(release_files()), fingerprint())
        (temp / 'verification.json').write_text(json.dumps(receipt, indent=2) + '\n')
        (temp / 'SHA256SUMS').write_text(''.join(hashlib.sha256((temp / name).read_bytes()).hexdigest() + '  ' + name + '\n' for name in ('source.tar.gz', 'image.tar.gz', 'verification.json')))
        ssh(host, 'set -e\numask 077\nmkdir -p "$1"\n', stage)
        # Stream the bundle over SSH without depending on the remote login shell.
        with tempfile.TemporaryFile() as stream:
            with tarfile.open(fileobj=stream, mode='w') as archive:
                for name in ('source.tar.gz','image.tar.gz','SHA256SUMS','verification.json'):
                    archive.add(temp / name, arcname=name)
            stream.seek(0)
            run(['ssh', '-o', 'BatchMode=yes', host, shlex.join(['tar', '-xf', '-', '-C', stage])], stdin=stream)
        ssh(host, REMOTE, directory, stage, image, rollback, info['Id'])


def main():
    os.umask(0o077)
    parser=argparse.ArgumentParser(description=__doc__)
    subs=parser.add_subparsers(dest='command',required=True)
    subs.add_parser('fingerprint')
    subs.add_parser('application-version')
    version=subs.add_parser('version'); version.add_argument('--image',required=True)
    remote_version=subs.add_parser('remote-version', help='Read versions over SSH without deploying')
    remote_version.add_argument('--host',default=os.environ.get('ZPUSH_DEPLOY_SSH'))
    remote_version.add_argument('--dir',default=os.environ.get('ZPUSH_DEPLOY_DIR'))
    v=subs.add_parser('verify'); v.add_argument('--image',required=True)
    d=subs.add_parser('deploy'); d.add_argument('--image',required=True)
    d.add_argument('--host',default=os.environ.get('ZPUSH_DEPLOY_SSH'))
    d.add_argument('--dir',default=os.environ.get('ZPUSH_DEPLOY_DIR'))
    d.add_argument('--dry-run',action='store_true')
    args=parser.parse_args()
    try:
        if args.command=='fingerprint': print(fingerprint())
        elif args.command=='application-version': print(application_version())
        elif args.command=='version': show_version(args.image)
        elif args.command=='remote-version': show_remote_version(args.host,args.dir)
        elif args.command=='verify': verify(args.image)
        else: deploy(args.image,args.host,args.dir,args.dry_run)
    except (RuntimeError, OSError, subprocess.CalledProcessError) as error:
        raise SystemExit('ERROR: '+str(error))


if __name__=='__main__': main()
