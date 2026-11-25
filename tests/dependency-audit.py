#!/usr/bin/env python3
"""Inventory-based OSV and npm distribution audit. Nonzero if incomplete or findings."""
import hashlib, json, re, sys, urllib.request, urllib.error, io, tarfile, base64
from pathlib import Path
root=Path(__file__).resolve().parent.parent
inventory=json.loads((root/'DEPENDENCIES.json').read_text())['components']
# Explicit package-to-file mapping; only byte-identical distributions are verified.
mappings={
 'bootstrap':('npm','bootstrap',{'dist/css/bootstrap.min.css':'jezgra/css/bootstrap.min.css','dist/js/bootstrap.bundle.min.js':'jezgra/js/bootstrap.bundle.min.js'}),
 'jquery-ui':('npm','jquery-ui-dist',{'jquery-ui.min.js':'jezgra/js/jquery-ui.min.js'}),
 'jquery':('npm','jquery',{'dist/jquery.min.js':'jezgra/js/jquery.min.js'}),
 'select2':('npm','select2',{'dist/js/select2.full.min.js':'jezgra/js/select2.full.min.js','dist/css/select2.min.css':'jezgra/css/select2.min.css'}),
 'tinymce':('npm','tinymce',{'tinymce.min.js':'jezgra/urednik/tinymce/tinymce.min.js'}),
 'parsedown':('Packagist','erusev/parsedown',{}),
 'dompurify':('npm','dompurify',{}), 'prismjs':('npm','prismjs',{}), 'prism-themes':('npm','prism-themes',{}),
 'bootstrap-icons':('npm','bootstrap-icons',{'font/bootstrap-icons.css':'jezgra/css/bootstrap-icons/bootstrap-icons.css','font/bootstrap-icons.json':'jezgra/css/bootstrap-icons/bootstrap-icons.json','font/fonts/bootstrap-icons.woff':'jezgra/css/bootstrap-icons/fonts/bootstrap-icons.woff','font/fonts/bootstrap-icons.woff2':'jezgra/css/bootstrap-icons/fonts/bootstrap-icons.woff2'}),
 'popper.js':('npm','@popperjs/core',{})
}
queries=[]; components=[]
for c in inventory:
 name=c['name']; version=c['installed']; entry={'name':name,'declared_version':version,'local_files':{},'osv_status':'unresolved','provenance':'unverified'}
 for pattern in c['paths']:
  for path in sorted(root.glob(pattern)):
   paths=path.rglob('*') if path.is_dir() else [path]
   for f in paths:
    if f.is_file() and not f.is_symlink():entry['local_files'][f.relative_to(root).as_posix()]=hashlib.sha256(f.read_bytes()).hexdigest()
 entry['missing_artifacts'] = not bool(entry['local_files'])
 if name=='brendy-select2-skin':entry['osv_status']='first-party';entry['provenance']='first-party'
 elif name in mappings and re.fullmatch(r'\d+\.\d+\.\d+',version):
  eco,pkg,_=mappings[name];queries.append((entry,{'package':{'name':pkg,'ecosystem':eco},'version':version}))
 components.append(entry)
manifest=json.loads((root/'tests/vendor-manifest.json').read_text())['files']
manifest_errors=[]
for rel, expected in manifest.items():
 path=root/rel
 if not path.is_file() or path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest()!=expected['sha256']:
  manifest_errors.append(rel)
report={'scope':'declared bundled components; not a complete transitive SBOM','components':components,'osv_network_error':None,'vendor_manifest_files':len(manifest),'vendor_manifest_errors':manifest_errors}
if queries:
 try:
  req=urllib.request.Request('https://api.osv.dev/v1/querybatch',data=json.dumps({'queries':[q for _,q in queries]}).encode(),headers={'Content-Type':'application/json'},method='POST')
  with urllib.request.urlopen(req,timeout=8) as f:results=json.load(f)['results']
  if len(results)!=len(queries):raise ValueError('Unexpected OSV result count')
  for (entry,_),result in zip(queries,results):
   entry['advisories']=[v['id'] for v in result.get('vulns',[])];entry['osv_status']='findings' if entry['advisories'] else 'no-reported-advisories'
 except (OSError,ValueError,KeyError) as e:
  report['osv_network_error']=str(e)
# Fetch immutable source reference from Packagist for the exact declared release.
# Compare all bytes, not just a version banner.
def verify_parsedown(entry):
 from urllib.parse import urlparse
 with urllib.request.urlopen('https://repo.packagist.org/p2/erusev/parsedown.json',timeout=10) as f:
  versions=json.load(f)['packages']['erusev/parsedown']
 version=next(v for v in versions if v['version'].lstrip('v')==entry['declared_version'])
 source=version['source']
 if source['url'] not in ('https://github.com/erusev/parsedown.git','https://github.com/erusev/parsedown') or not re.fullmatch('[0-9a-f]{40}',source['reference']):
  raise ValueError('Unexpected Parsedown source identity')
 with urllib.request.urlopen('https://raw.githubusercontent.com/erusev/parsedown/'+source['reference']+'/Parsedown.php',timeout=15) as f:
  data=f.read(2*1024*1024+1)
 if len(data)>2*1024*1024 or data!=(root/'jezgra/parsedown.class.php').read_bytes():
  raise ValueError('Parsedown bytes differ from release source')
 entry['source_reference']=source['reference']
 entry['provenance']='packagist-release-source-match'

def verify_jquery_ui(entry):
 # The local custom distribution includes the complete list of UI components.
 # Compare the executable bytes to the official distribution. Only the single
 # leading build banner may vary; all bytes of the executable body must match.
 version=entry['declared_version']
 with urllib.request.urlopen('https://code.jquery.com/ui/'+version+'/jquery-ui.min.js',timeout=15) as f:
  data=f.read(4*1024*1024+1)
 local=(root/'jezgra/js/jquery-ui.min.js').read_bytes()
 def body(raw):
  if not raw.startswith(('/*! jQuery UI - v'+version).encode()) or b'*/' not in raw:
   raise ValueError('Unexpected jQuery UI build banner')
  return raw.split(b'*/',1)[1].lstrip(b'\r\n')
 if len(data)>4*1024*1024 or body(data)!=body(local):
  raise ValueError('jQuery UI executable bytes differ from official distribution')
 entry['provenance']='official-distribution-code-match'
 entry['source_sha256']=hashlib.sha256(data).hexdigest()

# Optional network provenance check, separate from advisory lookup. No package scripts executed.
if '--verify-registry' in sys.argv:
 for entry,q in queries:
  eco,pkg,files=mappings[entry['name']]
  if entry['name'] in ('parsedown','jquery-ui'):
   try:
    (verify_parsedown if entry['name']=='parsedown' else verify_jquery_ui)(entry)
   except (OSError,ValueError,KeyError,StopIteration) as e:entry['provenance_error']=str(e)
   continue
  if eco!='npm' or not files:continue
  try:
   from urllib.parse import quote,urlparse
   u='https://registry.npmjs.org/'+quote(pkg,safe='')+'/'+entry['declared_version']
   with urllib.request.urlopen(u,timeout=8) as f:dist=json.load(f)['dist']
   url=dist['tarball']; parts=urlparse(url)
   if parts.scheme!='https' or parts.hostname!='registry.npmjs.org':raise ValueError('Unexpected registry host')
   integrity=next(x for x in dist['integrity'].split() if x.startswith('sha512-'))
   with urllib.request.urlopen(url,timeout=15) as f:data=f.read(64*1024*1024+1)
   if len(data)>64*1024*1024 or 'sha512-'+base64.b64encode(hashlib.sha512(data).digest()).decode()!=integrity:raise ValueError('Registry integrity mismatch')
   with tarfile.open(fileobj=io.BytesIO(data),mode='r:gz') as tar:
    for upstream,local in files.items():
     member=tar.getmember('package/'+upstream)
     if not member.isfile() or member.size>32*1024*1024:raise ValueError('Unexpected package file')
     if hashlib.sha256(tar.extractfile(member).read()).digest()!=hashlib.sha256((root/local).read_bytes()).digest():raise ValueError('Vendor bytes differ: '+local)
   entry['provenance']='registry-mapped-files-match'
  except (OSError,ValueError,KeyError,StopIteration,tarfile.TarError) as e:entry['provenance_error']=str(e)
report['manual_advisory_review']=json.loads((root/'tests/manual-advisories.json').read_text())
print(json.dumps(report,ensure_ascii=False,indent=2))
# Fail closed: unresolved and unverified dependencies are not a clean audit.
sys.exit(1 if manifest_errors or any(f['status'].startswith(('affected dependency','potentially affected dependency')) for f in report['manual_advisory_review']['findings']) or any(e['missing_artifacts'] or e['osv_status'] not in ('first-party','no-reported-advisories') or e['provenance']=='unverified' for e in components) else 0)
