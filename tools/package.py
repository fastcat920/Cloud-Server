#!/usr/bin/env python3
from pathlib import Path
from zipfile import ZipFile,ZIP_DEFLATED
import hashlib
root=Path(__file__).resolve().parents[1];out=root/'dist';out.mkdir(exist_ok=True)
path=out/'cloud-desk-v2.zip'
with ZipFile(path,'w',ZIP_DEFLATED) as archive:
 for name in ['index.php','readme.md','LICENSE','.htaccess','.gitignore','update.sh']:archive.write(root/name,name)
 for folder in ['_core','assets']:
  for file in (root/folder).rglob('*'):
   if file.is_file() and 'data' not in file.relative_to(root).parts:archive.write(file,file.relative_to(root))
(out/'cloud-desk-v2.zip.sha256').write_text(hashlib.sha256(path.read_bytes()).hexdigest()+'  '+path.name+'\n')
print(f'{path}: {path.stat().st_size/1024:.1f} KB')
