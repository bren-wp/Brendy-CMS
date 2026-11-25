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

def translation_data(raw):
    # Parse only the static addI18n call. No JavaScript evaluation.
    if len(raw)>256*1024:
        raise ValueError('Locale size limit')
    m=re.fullmatch(rb'\s*tinymce\.addI18n\("hr",\s*(\{[\s\S]*\})\s*\);?\s*',raw)
    if not m:
        raise ValueError('Unexpected locale executable wrapper')
    # JS hex escapes are permitted only as string escape tokens.
    body=re.sub(rb'\\(?:["\\/bfnrt]|u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2})',
                lambda m: b'\\u00'+m.group()[2:] if m.group().startswith(b'\\x') else m.group(),m.group(1))
    def pairs(items):
        result={}
        for k,v in items:
            if k in result or k in ('__proto__','constructor','prototype'):
                raise ValueError('Unsafe or duplicate translation key')
            result[k]=v
        return result
    data=json.loads(body.decode('utf-8'),object_pairs_hook=pairs)
    if not isinstance(data,dict) or not data or len(data)>2000:
        raise ValueError('Invalid locale object')
    for key,value in data.items():
        if not isinstance(value,str) or len(key)>4096 or len(value)>4096 or re.search(r'[\x00-\x1f<>]',value):
            raise ValueError('Unexpected locale value')
    return data

def verify_locale(root):
    local=(root/'jezgra/urednik/tinymce/langs/hr.js').read_bytes()
    with npm_package('tinymce-i18n','26.11.2') as tar:
        official=member(tar,'langs8/hr.js')
    actual,reference=translation_data(local),translation_data(official)
    if actual.keys()!=reference.keys():
        raise ValueError('Locale keys differ from versioned community pack')
    for key in actual:
        if sorted(re.findall(r'\{\d+\}',actual[key]))!=sorted(re.findall(r'\{\d+\}',reference[key])):
            raise ValueError('Locale placeholder mismatch: '+key)
    return {'reference_package':'tinymce-i18n@26.11.2','reference_sha256':hashlib.sha256(official).hexdigest(),
            'local_sha256':hashlib.sha256(local).hexdigest(),'keys':len(actual),
            'custom_values':sum(actual[k]!=reference[k] for k in actual),
            'scope':'strict static translation data and key/placeholder compatibility; not identical upstream bytes or license clearance'}

def verify_tiny_assets(root, version, dom_verified, prism_verified):
    if not dom_verified or not prism_verified:
        raise ValueError('Embedded library provenance must be verified first')
    folder=root/'jezgra/urednik/tinymce'
    evidence={}
    errors=[]
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
            try:
                local=path.read_bytes()
                if rel=='langs/hr.js':
                    evidence[rel]=verify_locale(root)
                    continue
                if rel=='license.md':
                    reference='4ef63a1c99ac348152f5a19d5a4d57e627dfc692'
                    with urllib.request.urlopen('https://raw.githubusercontent.com/tinymce/tinymce/'+reference+'/LICENSE.md',timeout=15) as f:
                        source_license=f.read(65537)
                    if len(source_license)>65536 or local!=source_license:
                        raise ValueError('Local license differs from tagged upstream source')
                    # The npm distribution may have different copyright or formatting.
                    # Preserve the verified tagged source notice and record both hashes.
                    package_license=member(tar,rel)
                    evidence[rel]={'source_reference':reference,'source_sha256':hashlib.sha256(local).hexdigest(),
                                   'package_sha256':hashlib.sha256(package_license).hexdigest(),
                                   'package_bytes_equal':local==package_license}
                    continue
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
            except (OSError,ValueError,KeyError,StopIteration,tarfile.TarError) as e:
                errors.append(rel+': '+str(e))
    if errors:
        raise ValueError('Editor verification failures: '+'; '.join(errors))
    return evidence


def verify_prism_theme_distribution(root, tiny_version, theme_version):
    # The theme CSS is compiled inside TinyMCE, not a standalone Prism package.
    # Require exact verified TinyMCE skin bytes and the publisher's own notice.
    with npm_package('tinymce',tiny_version) as tar:
        notices=member(tar,'notices.txt').decode('utf-8')
        if not re.search(r'(?m)^prism-themes\s*\n(?:(?!\n\s*\n)[\s\S])*?^version:\s*'+re.escape(theme_version)+r'\s*$',notices):
            raise ValueError('Upstream theme version notice missing')
        folder=root/'jezgra/urednik/tinymce'
        skins=[p.relative_to(folder).as_posix() for p in sorted((folder/'skins').rglob('*.css')) if p.is_file() and not p.is_symlink()]
        if not skins:
            raise ValueError('No verified upstream skin assets')
        for rel in skins:
            if member(tar,rel)!=(folder/rel).read_bytes():
                raise ValueError('Skin digest mismatch: '+rel)
    return {'embedded_in':'tinymce@'+tiny_version,'upstream_notices_version':theme_version,'verified_skins':skins}

def verify_bundled_popper(root, bootstrap_version, popper_version):
    # Verify the exact distributed bundle, then its publisher-supplied source
    # map's Popper source contents against the pinned Popper package.
    with npm_package('bootstrap',bootstrap_version) as tar:
        bundle=member(tar,'dist/js/bootstrap.bundle.min.js')
        if bundle!=(root/'jezgra/js/bootstrap.bundle.min.js').read_bytes():
            raise ValueError('Bootstrap bundle differs')
        source_map=json.loads(member(tar,'dist/js/bootstrap.bundle.min.js.map'))
    sources=source_map['sources']; contents=source_map['sourcesContent']
    if len(sources)!=len(contents):
        raise ValueError('Invalid source map')
    checked={}
    with npm_package('@popperjs/core',popper_version) as tar:
        for path,content in zip(sources,contents):
            match=re.search(r'(?:^|/)@popperjs/core/(lib/[^?#]+\.js)$',path)
            if not match:
                continue
            rel=match.group(1)
            if '..' in rel.split('/') or not isinstance(content,str) or member(tar,rel)!=content.encode('utf-8'):
                raise ValueError('Popper source mismatch: '+rel)
            checked[rel]=hashlib.sha256(content.encode('utf-8')).hexdigest()
    if len(checked)<10:
        raise ValueError('Insufficient Popper source map evidence')
    return {'bundle_sha256':hashlib.sha256(bundle).hexdigest(),'matched_sources':checked,
            'scope':'exact upstream Bootstrap bundle and publisher source-map attribution; not independent reproducible build'}
