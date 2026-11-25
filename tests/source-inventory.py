#!/usr/bin/env python3
"""Verify the full imported source inventory, not installation-specific content."""
import json, subprocess, sys
from pathlib import Path
root=Path(__file__).resolve().parent.parent
manifest=json.loads((root/'tests/source-inventory.json').read_text())
if manifest.get('schema')!=1 or manifest.get('storage')!='flat-file JSON':
    sys.exit('Invalid source inventory')
required=manifest['required']
if len(required)!=len(set(required)):
    sys.exit('Duplicate required paths')
tracked=set(subprocess.check_output(['git','ls-files','-z'],cwd=root).decode().rstrip('\0').split('\0'))
errors=[]
for name in required:
    p=root/name
    if name not in tracked or not p.is_file() or p.is_symlink() or not p.resolve().is_relative_to(root):
        errors.append('Missing or unsafe: '+name)
for name in sorted(tracked):
    if name.startswith(('sadrzaj/','brendy-content/')) and name!='sadrzaj/.keep':
        errors.append('Installation-specific content tracked: '+name)
    if '/' not in name and name.lower().endswith('.zip'):
        errors.append('Root distribution ZIP tracked: '+name)
themes={p.parent.name for p in (root/'teme').glob('*/metadata.json')}
plugins={p.parent.name for p in (root/'dodaci').glob('*/metadata.json')}
if not {'alternativa','blog','fokus','novosti','okus'}<=themes: errors.append('Missing bundled theme')
if not {'api','backup-manager','categories','comments','gallery','navigation','search','sitemap','tags'}<=plugins: errors.append('Missing bundled plugin')
if errors:
    print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print(f'[PASS] All {len(required)} imported source paths present; {len(tracked)} tracked files; flat-file content excluded')
print(f'[PASS] {len(themes)} themes and {len(plugins)} extensions available')
