#!/usr/bin/env python3
"""Package a root-entry release, with internal code under a protected directory."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import hashlib
root = Path(__file__).resolve().parents[1]
output = root / 'dist'; output.mkdir(exist_ok=True)
archive = output / 'cloud-server-baota.zip'
for name in ['vendor/autoload.php','public/assets/admin-app/.vite/manifest.json','install.php']:
    if not (root/name).is_file(): raise SystemExit('Missing input: '+name)
with ZipFile(archive,'w',ZIP_DEFLATED) as package:
    # No enclosing directory: extract directly into the domain's website directory.
    for folder in ['app','bootstrap','config','library','public','resources','routes','vendor']:
        for path in sorted((root/folder).rglob('*')):
            relative=path.relative_to(root)
            if not path.is_file() or path.is_symlink() or path.name=='.DS_Store': continue
            if str(relative).startswith('bootstrap/cache/'): continue
            package.write(path,'_app/'+relative.as_posix())
    for name in ['artisan','install.php','.env.example','composer.json','composer.lock','LICENSE']:
        package.write(root/name,'_app/'+name)
    for directory in ['bootstrap/cache','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','storage/app/private']:
        package.writestr('_app/'+directory+'/', '')
    for path in (root/'public/assets').rglob('*'):
        if path.is_file() and '.vite' not in path.parts: package.write(path,path.relative_to(root/'public').as_posix())
    package.write(root/'deploy/root-index.php','index.php')
    package.write(root/'deploy/nginx.conf','nginx-rules.txt')
    package.write(root/'readme.md','readme.md')
    package.writestr('_app/.htaccess','Require all denied\n')
    package.writestr('.htaccess','DirectoryIndex index.php\nRewriteEngine On\nRewriteRule ^_app(?:/|$) - [F,L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^ index.php [L,QSA]\n')
(output/'cloud-server-baota.zip.sha256').write_text(hashlib.sha256(archive.read_bytes()).hexdigest()+'  '+archive.name+'\n')
print(f'{archive} ({archive.stat().st_size/1024/1024:.1f} MB)')
