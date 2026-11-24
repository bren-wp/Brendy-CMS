#!/usr/bin/env python3
"""Isolated real HTTP entrypoint/recovery checks; not full CMS E2E."""
import fcntl, http.client, pathlib, shutil, socket, subprocess, tempfile, time
root=pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='brendy-gate-') as d:
    base=pathlib.Path(d)
    for rel in ['index.php','install.php','jezgra/pokretanje/restore-gate.php','jezgra/pomocnici/filesystem.class.php','jezgra/pomocnici/restore-journal.class.php','jezgra/pomocnici/recovery-ledger.class.php']:
        target=base/rel;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(root/rel,target)
    content=base/'sadrzaj';tmp=content/'tmp';tmp.mkdir(parents=True)
    stage='restore-'+'a'*32; incoming=tmp/stage/'sadrzaj';(incoming/'databases').mkdir(parents=True)
    (content/'databases').mkdir();(content/'databases/site.php').write_text('old');(incoming/'databases/site.php').write_text('new')
    setup="""define('BRENDY',true); define('DS',DIRECTORY_SEPARATOR); require 'jezgra/pomocnici/filesystem.class.php'; require 'jezgra/pomocnici/restore-journal.class.php'; $root=getcwd().'/sadrzaj'; $j=new RestoreJournal($root); $p=$j->prepare('restore-'.str_repeat('a',32)); if (!$p || !rename($root.'/databases',$root.'/tmp/backup-restore-rollback-'.$p['id'].'/databases')) exit(1);"""
    subprocess.run(['php','-r',setup],cwd=base,check=True)
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(base)],cwd=base,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def get(path):
        c=http.client.HTTPConnection('127.0.0.1',port,timeout=3)
        try:c.request('GET',path);r=c.getresponse();return r.status,dict(r.getheaders()),r.read().decode()
        finally:c.close()
    try:
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1',port),.2):break
            except OSError:time.sleep(.1)
        with open(tmp/'restore-request.lock','a+') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX)
            for entry in ['/index.php','/install.php']:
                status,headers,_=get(entry);assert status==503 and headers.get('Retry-After')=='60';print('[PASS] Active exclusive restore blocks '+entry)
        assert not (content/'databases/site.php').exists()
        status,_,_=get('/install.php');assert status==404 and (content/'databases/site.php').read_text()=='old';print('[PASS] Installer recovers interrupted restore before install-marker check')
        assert not (tmp/'restore-transaction.json').exists()
        # Test the actual shared-to-exclusive conversion in an isolated route.
        (base/'gate-fixture.php').write_text("<?php define('BRENDY',true); define('DS',DIRECTORY_SEPARATOR); define('BRENDY_CONTENT_DIRECTORY','sadrzaj'); define('PATH_TMP',__DIR__.'/sadrzaj/tmp/'); require __DIR__.'/jezgra/pokretanje/restore-gate.php'; brendyRestoreGate(__DIR__); if (isset($_GET['stale'])) $GLOBALS['_brendyRestoreGates']['sadrzaj']['generation']='stale'; if (isset($_GET['exclusive'])) brendyRestoreExclusive(); echo 'gate-ok';")
        with open(tmp/'restore-request.lock','a+') as lock:
            fcntl.flock(lock,fcntl.LOCK_SH)
            status,_,body=get('/gate-fixture.php');assert status==200 and body=='gate-ok';print('[PASS] Shared gate permits concurrent reader')
            status,_,_=get('/gate-fixture.php?exclusive=1');assert status==503;print('[PASS] Restore upgrade refuses active reader')
        status,_,body=get('/gate-fixture.php?exclusive=1');assert status==200 and body=='gate-ok' and len((tmp/'restore-request.lock').read_text())==32;print('[PASS] Exclusive upgrade succeeds when readers drain')
        generation=(tmp/'restore-request.lock').read_text()
        status,_,_=get('/gate-fixture.php?exclusive=1&stale=1');assert status==503 and (tmp/'restore-request.lock').read_text()==generation;print('[PASS] Stale generation refused without rewriting lock token')
        # Corrupt journal must prevent BOTH entrypoints from proceeding.
        path=tmp/'restore-transaction.json';path.write_text('{invalid');path.chmod(0o600)
        for entry in ['/index.php','/install.php']:
            status,_,_=get(entry);assert status==503;print('[PASS] Corrupt journal blocks '+entry)
        assert path.read_text()=='{invalid' and (content/'databases/site.php').read_text()=='old'
        path.unlink()
        # Repeat with index as the recovering entrypoint; it must not redirect to install.
        subprocess.run(['php','-r',setup],cwd=base,check=True)
        # Stop the PHP server with a pending transaction, then start a new process.
        proc.terminate();proc.wait(timeout=5)
        assert path.is_file() and not (content/'databases/site.php').exists()
        proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(base)],cwd=base,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1',port),.2):break
            except OSError:time.sleep(.1)
        status,headers,_=get('/index.php');assert status!=302 and (content/'databases/site.php').read_text()=='old';print('[PASS] Index recovers interrupted restore before install-marker check after actual PHP server restart')
        import json
        receipt=json.loads((tmp/'restore-last-result.json').read_text());assert receipt['result']=='rolled-back' and receipt['databaseVerified'] is True and not path.exists() and (tmp/'restore-request.lock').read_text()!=generation
        print('[PASS] Persistent verified recovery result available after server restart')
    finally:proc.terminate();proc.wait(timeout=5)
