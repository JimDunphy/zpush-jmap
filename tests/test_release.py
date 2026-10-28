#!/usr/bin/env python3
"""Deployment gates and rollback tested without Docker or SSH access."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch

spec=importlib.util.spec_from_file_location('release',Path(__file__).resolve().parents[1]/'nginx/release.py')
release=importlib.util.module_from_spec(spec); spec.loader.exec_module(release)


class ReleaseTests(unittest.TestCase):
    def test_remote_version_uses_only_the_configured_target_and_read_command(self):
        with patch.object(release, 'ssh') as ssh:
            release.show_remote_version('admin@sync-host','/home/admin/zpush-jmap')
        host,script,directory=ssh.call_args.args
        self.assertEqual(host,'admin@sync-host')
        self.assertEqual(directory,'/home/admin/zpush-jmap')
        self.assertIn('bash "$script" --version',script)
        for mutation in ('deploy','docker load','docker stop','mkdir','cp '):
            self.assertNotIn(mutation,script)

    def test_remote_version_requires_configuration_and_never_falls_back(self):
        with patch.object(release, 'ssh') as ssh:
            for host,directory in [(None,None),('admin@sync-host',None),(None,'/home/admin/zpush-jmap')]:
                with self.assertRaises(RuntimeError): release.show_remote_version(host,directory)
            ssh.assert_not_called()

    def test_source_bundle_excludes_state_and_credentials(self):
        names=release.release_files()
        self.assertIn('backend/stalwart/tasks.php',names)
        self.assertIn('nginx/release.py',names)
        self.assertIn('VERSION',names)
        self.assertIn('tests/.env.example',names)
        self.assertFalse(any('/zpush/' in p or ('/.env' in p and not p.endswith('/.env.example')) or p.startswith('docs-private/') or '/target/' in p or '/.releases/' in p for p in names))

    def test_receipt_rejects_changed_source_and_retagged_image(self):
        info={'Id':'sha256:a','Config':{'Labels':{release.LABEL:'source'}}}
        receipt={'image_id':'sha256:a','source_sha256':'source','files':{'file':'hash'}}
        release.validate_receipt(receipt,info,{'file':'hash'},'source')
        for altered,current in [({'Id':'sha256:b','Config':info['Config']},{'file':'hash'}),(info,{'file':'changed'})]:
            with self.assertRaises(RuntimeError): release.validate_receipt(receipt,altered,current,'source')
        info['Config']['Labels'][release.LABEL]='other'
        with self.assertRaises(RuntimeError): release.validate_receipt(receipt,info,{'file':'hash'},'source')

    def test_target_rejects_shell_injection_and_parent_paths(self):
        release.validate_target('admin@sync-host','/home/admin/zpush-jmap')
        for host,path in [('-oProxyCommand=bad','/safe'),('host;id','/safe'),('host','/'),('host','/safe/../other'),('host','/safe;id')]:
            with self.assertRaises(RuntimeError): release.validate_target(host,path)

    def exercise_remote(self,fail):
        with tempfile.TemporaryDirectory() as temp:
            temp=Path(temp); root=temp/'checkout'; stage=root/'.releases/test'; stage.mkdir(parents=True)
            binpath=temp/'bin'; binpath.mkdir()
            for name in ('config','state','log'): (root/'nginx/zpush'/name).mkdir(parents=True)
            (root/'nginx/zpush/state/device-state').write_text('preserve-device-state')
            (root/'nginx/.env').write_text('ZPUSH_IMAGE=old\nSTALWART_URL=https://private.example.test\n')
            (root/'old-source').write_text('old')
            manager='''#!/bin/bash
set -e
case "$1" in
--set-image) sed -i "s#^ZPUSH_IMAGE=.*#ZPUSH_IMAGE=$2#" "$(dirname "$0")/.env" ;;
--up-no-build) echo up >> "$TEST_EVENTS" ;;
--health) echo OK ;;
esac
'''
            (root/'nginx/manage.sh').write_text(manager)
            payload=temp/'payload'; (payload/'nginx').mkdir(parents=True)
            (payload/'nginx/manage.sh').write_text(manager)
            (payload/'old-source').write_text('new')
            with tarfile.open(stage/'source.tar.gz','w:gz') as archive:
                archive.add(payload/'nginx/manage.sh',arcname='nginx/manage.sh'); archive.add(payload/'old-source',arcname='old-source')
            (stage/'image.tar.gz').write_bytes(b'test image')
            (stage/'verification.json').write_text(json.dumps({'files':release.manifest(['nginx/manage.sh','old-source'],payload)}))
            (stage/'SHA256SUMS').write_text(''.join(hashlib.sha256((stage/n).read_bytes()).hexdigest()+'  '+n+'\n' for n in ('source.tar.gz','image.tar.gz','verification.json')))
            docker='''#!/bin/bash
set -e
echo "$*" >> "$TEST_EVENTS"
case "$1" in
load|commit) echo fake-id ;;
compose) if [[ "$*" == *'ps -q'* ]]; then echo fake-container; fi ;;
inspect|image) echo sha256:new ;;
exec) if [[ "$TEST_FAIL" == 1 ]]; then exit 1; fi ;;
run) tar -czf - -C "$TEST_ROOT/nginx/zpush" config state ;;
esac
'''
            (binpath/'docker').write_text(docker); (binpath/'docker').chmod(0o700)
            events=temp/'events'
            env=dict(os.environ,PATH=str(binpath)+':'+os.environ['PATH'],TEST_EVENTS=str(events),TEST_FAIL=str(int(fail)),TEST_ROOT=str(root))
            result=subprocess.run(['bash','-s','--',str(root),str(stage),'local/zpush:new','local/zpush:rollback','sha256:new'],input=release.REMOTE,text=True,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            self.assertEqual(result.returncode,1 if fail else 0,result.stdout+result.stderr)
            self.assertEqual((root/'nginx/zpush/state/device-state').read_text(),'preserve-device-state')
            self.assertIn('STALWART_URL=https://private.example.test',(root/'nginx/.env').read_text())
            self.assertTrue((stage/'state.before.tar.gz').is_file())
            with tarfile.open(stage/'state.before.tar.gz') as archive:
                self.assertEqual(archive.extractfile('state/device-state').read(),b'preserve-device-state')
            self.assertEqual((root/'old-source').read_text(),'old' if fail else 'new')
            self.assertIn('ZPUSH_IMAGE=local/zpush:'+('rollback' if fail else 'new'),(root/'nginx/.env').read_text())
            self.assertNotIn('systemctl',events.read_text())

    def test_success_preserves_configuration_and_device_state(self): self.exercise_remote(False)
    def test_failed_postcheck_rolls_back_source_and_image(self): self.exercise_remote(True)


if __name__=='__main__': unittest.main()
