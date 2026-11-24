#!/usr/bin/env python3
"""Real HTTP tests for the XML responder, not full CMS installation."""
import hashlib, http.client, json, os, pathlib, socket, subprocess, tempfile, time
root = pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='brendy-xml-http-') as tmp:
    base = pathlib.Path(tmp); xml = base/'sitemap.xml'
    xml.write_bytes(b'<urlset><url>one</url></urlset>')
    router = base/'router.php'
    router.write_text('<?php define("BRENDY",true); require '+json.dumps(str(root/'jezgra/pomocnici/filesystem.class.php'))+'; require '+json.dumps(str(root/'jezgra/functions.php'))+'; serveGeneratedXmlFile('+json.dumps(str(xml))+', "application/xml", 1024);')
    with socket.socket() as s: s.bind(('127.0.0.1',0)); port = s.getsockname()[1]
    process = subprocess.Popen(['php','-S',f'127.0.0.1:{port}',str(router)],cwd=base,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def request(method='GET',headers=None):
        c=http.client.HTTPConnection('127.0.0.1',port,timeout=5)
        try:
            c.request(method,'/sitemap.xml',headers=headers or {});r=c.getresponse();return r.status,dict(r.getheaders()),r.read()
        finally:c.close()
    try:
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1',port),.2): break
            except OSError: time.sleep(.1)
        status,h,body=request();etag='"'+hashlib.sha256(body).hexdigest()+'"';assert status==200 and h['ETag']==etag and body==xml.read_bytes() and int(h['Content-Length'])==len(body);print('[PASS] GET returns XML and content-derived ETag')
        status,h,body=request('HEAD');assert status==200 and not body and h['ETag']==etag;print('[PASS] HEAD returns metadata without a body')
        for candidate in (etag,'W/'+etag,'"other", '+etag,'*'):
            status,_,body=request(headers={'If-None-Match':candidate});assert status==304 and not body;print('[PASS] If-None-Match recognizes valid validator')
        stat=xml.stat();xml.write_bytes(b'<urlset><url>two</url></urlset>');os.utime(xml,ns=(stat.st_atime_ns,stat.st_mtime_ns))
        status,h,body=request(headers={'If-None-Match':etag});assert status==200 and h['ETag']!=etag and b'two' in body;print('[PASS] Same-size same-mtime rewrite changes ETag')
        status,h,body=request('POST');assert status==405 and h['Allow']=='GET, HEAD';print('[PASS] Unsupported method rejected')
    finally:process.terminate();process.wait(timeout=5)
