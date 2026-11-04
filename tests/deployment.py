#!/usr/bin/env python3
"""Exercise deployment and updates against a local Git repository, never a real site."""
import os,subprocess,tempfile,shutil,tarfile
from pathlib import Path
project=Path(__file__).resolve().parents[1]
def run(args,cwd,env=None,success=True):
 r=subprocess.run(args,cwd=cwd,env=env,capture_output=True,text=True)
 if success and r.returncode:raise AssertionError(r.stdout+r.stderr)
 if not success:assert r.returncode,r.stdout+r.stderr
 return r
with tempfile.TemporaryDirectory(prefix='cloud-deployment-') as directory:
 root=Path(directory);origin=root/'origin';origin.mkdir()
 run(['git','init','-b','main'],origin);run(['git','config','user.name','Deployment Test'],origin);run(['git','config','user.email','deployment@example.invalid'],origin)
 (origin/'_core').mkdir();(origin/'_core/Store.php').write_text('<?php namespace CloudDesk;')
 (origin/'index.php').write_text('<?php echo "v1";');shutil.copy(project/'update.sh',origin/'update.sh')
 run(['git','add','.'],origin);run(['git','commit','-m','initial'],origin)
 env={**os.environ,'CLOUD_DESK_REPOSITORY':str(origin)}
 website=root/'example.com';website.mkdir()
 run(['bash',str(project/'update.sh')],website,env)
 assert (website/'.git').is_dir() and (website/'index.php').is_file() and not (website/'source').exists()
 assert (website/'_core/Store.php').stat().st_mode&0o444==0o444,'PHP source must be readable by web user'
 (website/'_core/data/state.php').write_text('persistent-state');(website/'_core/data/key.php').write_text('persistent-key')
 (origin/'index.php').write_text('<?php echo "v2";');run(['git','add','.'],origin);run(['git','commit','-m','upgrade'],origin)
 run(['bash','update.sh'],website,env)
 assert 'v2' in (website/'index.php').read_text() and (website/'_core/data/state.php').read_text()=='persistent-state'
 backups=list((root/'.cloud-desk-backups').glob('*.tar.gz'));assert backups
 with tarfile.open(backups[0]) as archive:assert './_core/data/key.php' in archive.getnames()
 (website/'index.php').write_text('local customization');run(['bash','update.sh'],website,env,success=False);assert (website/'index.php').read_text()=='local customization'
 archive_site=root/'zip.example.com';archive_site.mkdir();shutil.copytree(website/'_core',archive_site/'_core');shutil.copy(project/'update.sh',archive_site/'update.sh');(archive_site/'index.php').write_text('zip install')
 run(['bash','update.sh'],archive_site,env)
 assert (archive_site/'.git').is_dir() and (archive_site/'_core/data/key.php').read_text()=='persistent-key'
 assert 'v2' in (archive_site/'index.php').read_text()
 unrelated=root/'unrelated.example.com';unrelated.mkdir();(unrelated/'index.html').write_text('Other site')
 run(['bash',str(project/'update.sh')],unrelated,env,success=False);assert (unrelated/'index.html').read_text()=='Other site'
 legacy=root/'legacy.example.com';legacy.mkdir();(legacy/'_app').mkdir();(legacy/'index.php').write_text('old app')
 run(['bash',str(project/'update.sh')],legacy,env,success=False);assert (legacy/'_app').is_dir()
 run(['git','rm','update.sh'],origin);run(['git','commit','-m','simulate pre-script release'],origin)
 old_git=root/'existing-git.example.com';run(['git','clone',str(origin),str(old_git)],root)
 shutil.copy(project/'update.sh',old_git/'update.sh')
 shutil.copy(project/'update.sh',origin/'update.sh');run(['git','add','update.sh'],origin);run(['git','commit','-m','introduce updater'],origin)
 run(['bash','update.sh'],old_git,env)
 assert run(['git','ls-files','update.sh'],old_git).stdout.strip()=='update.sh'
 print('PASS: current-directory install, readable source, fast-forward update, data/key preservation, backup, local edit refusal, ZIP-to-Git adoption, unrelated/legacy directory protection')
