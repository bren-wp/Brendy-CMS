#!/usr/bin/env python3
"""Isolated runtime preflight HTTP tests, not an installed-CMS E2E test."""
import http.client, pathlib, shutil, socket, subprocess, tempfile, time
root=pathlib.Path(__file__).resolve().parent.parent
required=['mbstring','json','gd','dom','session','fileinfo']
modules=subprocess.check_output(['php','-m'],text=True).lower().splitlines()
missing=[m for m in required if m not in modules]
with tempfile.TemporaryDirectory(prefix='brendy-preflight-') as d:
    base=pathlib.Path(d); shutil.copy2(root/'index.php',base/'index.php')
    for rel in ['jezgra/pokretanje/restore-gate.php','jezgra/pomocnici/filesystem.class.php','jezgra/pomocnici/restore-journal.class.php','jezgra/pomocnici/recovery-ledger.class.php']:
        target=base/rel;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(root/rel,target)
    with socket.socket() as s:s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
    proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}',str(base/'index.php')],cwd=base,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def get():
        c=http.client.HTTPConnection('127.0.0.1',port,timeout=3)
        try:
            c.request('GET','/'); r=c.getresponse(); return r.status,dict(r.getheaders()),r.read().decode()
        finally:c.close()
    try:
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1',port),.2):break
            except OSError:time.sleep(.1)
        status,headers,_=get(); assert status==302 and headers.get('Location')=='/install.php'; print('[PASS] Fresh installation redirects to installer')
        db=base/'sadrzaj/databases/site.php';db.parent.mkdir(parents=True);db.write_text('fixture only')
        if missing:
            status,headers,body=get(); assert status==500 and all('PHP modul '+m in body for m in missing) and 'no-store' in headers.get('Cache-Control',''); print('[PASS] Installed runtime fails closed when required extensions are missing')
        else: print('[SKIP] Missing-extension case not applicable on this PHP runtime')
        legacy=base/'brendy-content/databases/site.php';legacy.parent.mkdir(parents=True);legacy.write_text('fixture only')
        status,_,body=get();assert status==409 and 'dvije Brendy baze' in body;print('[PASS] Conflicting legacy and current data stores rejected')
        legacy.unlink(); db.unlink();db.symlink_to(base/'index.php');status,_,body=get();assert status==500 and 'simbolička poveznica' in body;print('[PASS] Symlinked site database rejected')
    finally:proc.terminate();proc.wait(timeout=5)
