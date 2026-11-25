#!/usr/bin/env python3
"""Verify the exact upstream bytes in the local editor integration.

No executable bytes are normalized; only trailing line endings and the
explicit upstream source map directive may be omitted. The wrapper and
unmodified surrounding TinyMCE code are separately verified.
"""
import base64
import hashlib
import io
import json
import re
import tarfile
import urllib.request
from urllib.parse import quote, urlparse

def npm_package(name, version):
    with urllib.request.urlopen('https://registry.npmjs.org/'+quote(name,safe='')+'/'+version,timeout=10) as f:
        metadata=json.load(f)
    if metadata.get('name')!=name or metadata.get('version')!=version:
        raise ValueError('Unexpected registry package identity')
    dist=metadata['dist']
    url=dist['tarball']; parts=urlparse(url)
    if parts.scheme!='https' or parts.hostname!='registry.npmjs.org':
        raise ValueError('Unexpected tarball host')
    integrity=next(x for x in dist['integrity'].split() if x.startswith('sha512-'))
    with urllib.request.urlopen(url,timeout=20) as f:
        raw=f.read(64*1024*1024+1)
    if len(raw)>64*1024*1024 or 'sha512-'+base64.b64encode(hashlib.sha512(raw).digest()).decode()!=integrity:
        raise ValueError('Registry integrity mismatch')
    return tarfile.open(fileobj=io.BytesIO(raw),mode='r:gz')

def member(tar, path):
    info=tar.getmember('package/'+path)
    if not info.isfile() or info.size>32*1024*1024:
        raise ValueError('Unsafe upstream member: '+path)
    with tar.extractfile(info) as f:
        return f.read()

def source_code(raw):
    return re.sub(rb'\r?\n//# sourceMappingURL=[^\r\n]+\r?\n?$',b'',raw.rstrip(b'\r\n')).rstrip(b'\r\n')

def isolate(raw, start, end):
    if raw.count(start)!=1 or raw.count(end)!=1:
        raise ValueError('Expected a single integration boundary')
    prefix,rest=raw.split(start,1)
    payload,suffix=rest.split(end,1)
    return prefix,payload,suffix

DOM_START=b'\n/* Brendy local security integration: DOMPurify 3.5.0, isolated CommonJS scope. */\nvar Jw=(function(){var module={exports:{}};var exports=module.exports;\n'
DOM_END=b'\nreturn module.exports;})();\n'
PRISM_START=b'const l=function(){const previous=Object.getOwnPropertyDescriptor(window,"Prism");window.Prism={manual:true,disableWorkerMessageHandler:true};try{\n/* PrismJS 1.30.0 | MIT | Copyright (c) 2012 Lea Verou. Local subset, no optional plugins. */\n'
PRISM_END=b'\nreturn Prism;}finally{if(previous)Object.defineProperty(window,"Prism",previous);else delete window.Prism;}}()'
PRISM_COMPONENTS=('core','markup','css','clike','javascript','c','cpp','csharp','ruby','markup-templating','java','php','python','regex')

def verify_dompurify(root, version):
    raw=(root/'jezgra/urednik/tinymce/tinymce.min.js').read_bytes()
    _,payload,_=isolate(raw,DOM_START,DOM_END)
    with npm_package('dompurify',version) as tar:
        official=source_code(member(tar,'dist/purify.min.js'))
    if payload!=official:
        raise ValueError('Embedded DOMPurify does not match the exact registry distribution')
    return hashlib.sha256(payload).hexdigest()

def verify_prism(root, version):
    raw=(root/'jezgra/urednik/tinymce/plugins/codesample/plugin.min.js').read_bytes()
    _,payload,_=isolate(raw,PRISM_START,PRISM_END)
    with npm_package('prismjs',version) as tar:
        official=b'\n'.join(source_code(member(tar,'components/prism-'+name+'.min.js')) for name in PRISM_COMPONENTS)
    if payload!=official:
        raise ValueError('Embedded Prism components differ from exact registry distributions')
    return hashlib.sha256(payload).hexdigest()

def compare_outside_patch(local, upstream, start, end, prefix_transform=lambda x:x):
    prefix,_,suffix=isolate(local,start,end)
    prefix=prefix_transform(prefix)
    if not prefix or not suffix or not upstream.startswith(prefix) or not upstream.endswith(suffix) or len(upstream)<=len(prefix)+len(suffix):
        raise ValueError('Executable bytes outside documented integration differ')
    return hashlib.sha256(upstream[len(prefix):len(upstream)-len(suffix)]).hexdigest()

def verify_tiny_assets(root, version, dom_verified, prism_verified):
    if not dom_verified or not prism_verified:
        raise ValueError('Embedded library provenance must be verified first')
    folder=root/'jezgra/urednik/tinymce'
    evidence={}
    # Only explicitly enumerated local documentation/license files are excluded.
    # The language bundle is executable and must match upstream too.
    notices={'DOMPurify-LICENSE-MPL.txt','DOMPurify-LICENSE.txt','Prism-LICENSE.txt','notices.txt'}
    with npm_package('tinymce',version) as tar:
        for path in sorted(folder.rglob('*')):
            if not path.is_file():
                continue
            if path.is_symlink():
                raise ValueError('Unexpected editor symlink')
            rel=path.relative_to(folder).as_posix()
            if rel in notices:
                continue
            local=path.read_bytes()
            upstream=member(tar,rel)
            if rel=='tinymce.min.js':
                # Original declaration may be part of a comma-separated var
                # statement. Permit only that exact documented delimiter change.
                # The upstream DOMPurify version notice is the sole header edit.
                def prefix_transform(prefix):
                    versions=re.findall(rb'DOMPurify ([0-9]+\.[0-9]+\.[0-9]+)',upstream[:500])
                    if len(set(versions))!=1:
                        raise ValueError('Unknown upstream DOMPurify notice')
                    old=versions[0]
                    prefix=prefix.replace(b'DOMPurify 3.5.0',b'DOMPurify '+old,1).replace(b'/blob/3.5.0/LICENSE',b'/blob/'+old+b'/LICENSE',1)
                    if prefix.endswith(b';'):
                        if upstream.startswith(prefix[:-1]+b',Jw='):
                            return prefix[:-1]
                    return prefix
                evidence[rel]=compare_outside_patch(local,upstream,DOM_START,DOM_END,prefix_transform)
            elif rel=='plugins/codesample/plugin.min.js':
                evidence[rel]=compare_outside_patch(local,upstream,PRISM_START,PRISM_END)
            elif local!=upstream:
                raise ValueError('Editor asset differs: '+rel)
            else:
                evidence[rel]=hashlib.sha256(local).hexdigest()
    return evidence
