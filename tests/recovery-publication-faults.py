#!/usr/bin/env python3
"""Linux subprocess SIGKILL after real rename/unlink/rmdir; isolated temp roots only."""
import os, pathlib, shutil, signal, subprocess, sys, tempfile, json
if not sys.platform.startswith('linux') or not shutil.which('cc'):
    print('[SKIP] Linux and C compiler required'); sys.exit(2)
root=pathlib.Path(__file__).resolve().parent.parent
bootstrap=str(root/'tests/bootstrap.php')
source=r'''
#define _GNU_SOURCE
#include <dlfcn.h>
#include <unistd.h>
#include <stdlib.h>
#include <string.h>
#include <signal.h>
static void stop(const char *kind,const char *path,int rc) {
    const char *target=getenv("BRENDY_STOP_PATH"), *operation=getenv("BRENDY_STOP_OPERATION");
    if (rc==0 && target && operation && !strcmp(operation,kind) && !strcmp(target,path)) kill(getpid(),SIGKILL);
}
int rename(const char *from,const char *to) {
    int (*next)(const char*,const char*)=dlsym(RTLD_NEXT,"rename"); int rc=next(from,to); stop("rename",to,rc); return rc;
}
int unlink(const char *path) {
    int (*next)(const char*)=dlsym(RTLD_NEXT,"unlink"); int rc=next(path); stop("unlink",path,rc); return rc;
}
int rmdir(const char *path) {
    int (*next)(const char*)=dlsym(RTLD_NEXT,"rmdir"); int rc=next(path); stop("rmdir",path,rc); return rc;
}
'''
setup="""require $argv[1]; $r=$argv[2]; $stage='restore-'.str_repeat('a',32); mkdir($r.'/tmp/'.$stage.'/sadrzaj/databases',0700,true); mkdir($r.'/databases'); file_put_contents($r.'/databases/site.php','old'); file_put_contents($r.'/tmp/'.$stage.'/sadrzaj/databases/site.php','new'); file_put_contents($r.'/old.txt','other'); $j=new RestoreJournal($r); $p=$j->prepare($stage); if (!$p || !$j->apply($p)) exit(3); if ($argv[3]==='gc') { if (!$j->commit($p) || $j->recover()!=='committed') exit(4); $l=new RecoveryLedger($r.'/tmp'); $s=$l->read(); $s['history'][0]['time']=time()-90000; if (!$l->save($s)) exit(5); } echo $p['id'];"""
commit="require $argv[1]; $j=new RestoreJournal($argv[2]); exit($j->commit($j->read())?0:9);"
recover="require $argv[1]; $j=new RestoreJournal($argv[2]); echo $j->recover();"
status="require $argv[1]; echo (new RecoveryLedger($argv[2].'/tmp'))->mirrorStatus();"
gc="""require $argv[1]; $j=new RestoreJournal($argv[2]); $name='backup-restore-rollback-'.$argv[3]; $items=array_column($j->inventory()['items'],null,'name'); if (empty($items[$name]['token'])) exit(8); exit($j->cleanupVerified($name,$items[$name]['token'])?0:9);"""
def php(code,folder,*args,env=None):
    return subprocess.run(['php','-r',code,bootstrap,str(folder),*args],capture_output=True,text=True,env=env,timeout=20)
def ok(proc):
    assert proc.returncode==0,(proc.returncode,proc.stdout,proc.stderr)
    return proc.stdout
with tempfile.TemporaryDirectory(prefix='brendy-recovery-fault-build-') as build:
    build=pathlib.Path(build); (build/'fault.c').write_text(source)
    subprocess.run(['cc','-shared','-fPIC','-o',str(build/'fault.so'),str(build/'fault.c'),'-ldl'],check=True)
    def fault(kind,path): return dict(os.environ,LD_PRELOAD=str(build/'fault.so'),BRENDY_STOP_OPERATION=kind,BRENDY_STOP_PATH=str(path))
    for target,result,data in [('restore-ledger-mirror.json','rolled-back','old'),('restore-ledger.json','committed','new')]:
        with tempfile.TemporaryDirectory(prefix='brendy-ledger-publication-') as folder:
            folder=pathlib.Path(folder); ok(php(setup,folder,'commit'))
            p=php(commit,folder,env=fault('rename',folder/'tmp'/target)); assert p.returncode==-signal.SIGKILL,(target,p.returncode,p.stderr)
            assert ok(php(recover,folder))==result
            assert (folder/'databases/site.php').read_text()==data and ok(php(status,folder))=='in-sync'
            print('[PASS] SIGKILL after '+target+' rename; '+result+'; '+data+' preserved')
    for phase in ['first-unlink','final-rmdir','tamper-after-unlink']:
        with tempfile.TemporaryDirectory(prefix='brendy-gc-interrupt-') as folder:
            folder=pathlib.Path(folder); tx=ok(php(setup,folder,'gc')); rollback=folder/'tmp'/('backup-restore-rollback-'+tx)
            final=phase=='final-rmdir'; p=php(gc,folder,tx,env=fault('rmdir' if final else 'unlink',rollback if final else rollback/'databases/site.php'))
            assert p.returncode==-signal.SIGKILL,(phase,p.returncode,p.stderr)
            assert (folder/'databases/site.php').read_text()=='new'
            assert not (rollback/'databases/site.php').exists()
            if final: assert not rollback.exists()
            if phase=='tamper-after-unlink':
                (rollback/'old.txt').write_text('tamper')
                p=php(gc,folder,tx); assert p.returncode==9,(p.returncode,p.stderr)
                assert (rollback/'old.txt').read_text()=='tamper' and (rollback/'databases').is_dir()
                (rollback/'old.txt').write_text('other')
                print('[PASS] Resumed GC refuses changed remaining file before further deletion')
            ok(php(gc,folder,tx)); assert not rollback.exists() and (folder/'databases/site.php').read_text()=='new'
            cursor=json.loads((folder/'tmp'/('restore-gc-'+tx+'-cursor.json')).read_text()); plan=json.loads((folder/'tmp'/('restore-gc-'+tx+'.json')).read_text()); assert json.loads(cursor['payload'])['next']==len(json.loads(plan['payload'])['entries'])
            print('[PASS] GC resumes after '+phase+'; durable completion and live data preserved')
    rotate="require $argv[1]; $l=new RecoveryLedger($argv[2].'/tmp'); exit($l->rotateKey()?0:9);"
    key_status="require $argv[1]; echo (new RecoveryLedger($argv[2].'/tmp'))->keyStatus();"
    init_rotate="require $argv[1]; mkdir($argv[2].'/tmp',0700); $l=new RecoveryLedger($argv[2].'/tmp'); if (!$l->initialize()) exit(2); echo $l->seal(['old'=>true],'test');"
    verify="require $argv[1]; $l=new RecoveryLedger($argv[2].'/tmp'); exit($l->read()!==false && $l->open($argv[3],'test')===false && $l->mirrorStatus()==='in-sync'?0:9);"
    for target in ['restore-ledger-key.php','restore-ledger-retirement.json','restore-ledger-mirror.json','restore-ledger.json']:
        with tempfile.TemporaryDirectory(prefix='brendy-key-rotation-') as folder:
            folder=pathlib.Path(folder); old=ok(php(init_rotate,folder)); p=php(rotate,folder,env=fault('rename',folder/'tmp'/target)); assert p.returncode==-signal.SIGKILL,(target,p.returncode,p.stderr)
            assert ok(php(key_status,folder))=='rotation-pending'
            ok(php(rotate,folder)); assert ok(php(key_status,folder))=='active';ok(php(verify,folder,old))
            print('[PASS] Key rotation resumes after '+target+' publication; old HMAC retired')
    for suffix in ['.json','-cursor.json']:
        with tempfile.TemporaryDirectory(prefix='brendy-gc-key-migration-') as folder:
            folder=pathlib.Path(folder); tx=ok(php(setup,folder,'gc')); rollback=folder/'tmp'/('backup-restore-rollback-'+tx)
            p=php(gc,folder,tx,env=fault('unlink',rollback/'databases/site.php')); assert p.returncode==-signal.SIGKILL,(p.returncode,p.stderr)
            plan=folder/'tmp'/('restore-gc-'+tx+'.json'); cursor=folder/'tmp'/('restore-gc-'+tx+'-cursor.json')
            old_plan=json.loads(plan.read_text()); old_cursor=json.loads(cursor.read_text())
            p=php(rotate,folder,env=fault('rename',folder/'tmp'/('restore-gc-'+tx+suffix))); assert p.returncode==-signal.SIGKILL,(p.returncode,p.stderr)
            assert ok(php(key_status,folder))=='rotation-pending'; assert php(gc,folder,tx).returncode==9
            ok(php(rotate,folder)); assert ok(php(key_status,folder))=='active'
            new_plan=json.loads(plan.read_text());new_cursor=json.loads(cursor.read_text())
            assert old_plan['payload']==new_plan['payload'] and old_cursor['payload']==new_cursor['payload'] and old_plan['mac']!=new_plan['mac'] and old_cursor['mac']!=new_cursor['mac']
            ok(php(gc,folder,tx)); assert not rollback.exists() and (folder/'databases/site.php').read_text()=='new'
            print('[PASS] SIGKILL during GC evidence migration '+suffix+'; rotation and interrupted GC both resume')

    # A derived lookup cache is published after the authoritative primary.
    # Crash at either point must not roll back a confirmed commit or lose history.
    build_index="require $argv[1]; $l=new RecoveryLedger($argv[2].'/tmp'); exit($l->rebuildLookup()?0:9);"
    verify_index="require $argv[1]; $l=new RecoveryLedger($argv[2].'/tmp'); $q=$l->query(); $r=$l->lookup($argv[3]); exit($q!==false && $q['total']===1 && $r===$q['last'] && $r['result']==='committed'?0:9);"
    for target in ['restore-ledger.json','restore-ledger-lookup.json','bucket']:
        with tempfile.TemporaryDirectory(prefix='brendy-lookup-fault-') as folder:
            folder=pathlib.Path(folder); tx=ok(php(setup,folder,'prepare')); ok(php(build_index,folder)); ok(php(commit,folder))
            actual=('restore-ledger-lookup-bucket-'+__import__('hashlib').sha256(tx.encode()).hexdigest()[:2]+'.json') if target=='bucket' else target
            p=php(recover,folder,env=fault('rename',folder/'tmp'/actual)); assert p.returncode==-signal.SIGKILL,(target,p.returncode,p.stderr)
            assert ok(php(recover,folder))=='committed'; ok(php(verify_index,folder,tx)); assert (folder/'databases/site.php').read_text()=='new'
            print('[PASS] SIGKILL after '+target+' publication; receipt and indexed/fallback lookup preserved')
    # The independent operator's archive manifest is the sole publication point.
    archive_lib=str(root/'tests/recovery-archive.class.php')
    arch_setup="""require $argv[1]; $d=$argv[2]; $pair=sodium_crypto_sign_keypair(); $sk=bin2hex(sodium_crypto_sign_secretkey($pair)); file_put_contents($d.'/secret.hex',$sk); chmod($d.'/secret.hex',0600); mkdir($d.'/archive',0700); $history=[]; for($i=1;$i<=2;$i++) { $id=sprintf('%032x',$i); $history[]=['schema'=>1,'seq'=>$i,'id'=>$id,'stage'=>'restore-'.$id,'result'=>'rolled-back','time'=>1700000000,'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',$id)]; $p=['installation'=>str_repeat('a',64),'history'=>$history]; file_put_contents($d.'/proposal'.$i.'.json',RecoveryLedger::encode($p)); } echo bin2hex(sodium_crypto_sign_publickey($pair));"""
    archive_cli=str(root/'tests/recovery-archive-operator.php')
    def archive(folder,mode,n,env=None):
        return subprocess.run(['php',archive_cli,mode,str(folder/'archive'),str(folder/'secret.hex'),str(folder/f'proposal{n}.json')],capture_output=True,text=True,env=env,timeout=20)
    def archive_verify(folder,pub):
        return json.loads(ok(subprocess.run(['php',archive_cli,'verify',str(folder/'archive'),pub],capture_output=True,text=True,timeout=20)))
    import hashlib
    for point in ['blob','manifest']:
        with tempfile.TemporaryDirectory(prefix='brendy-archive-fault-') as folder:
            folder=pathlib.Path(folder); pub=ok(php(arch_setup,folder)); ok(archive(folder,'initialize',1))
            digest=hashlib.sha256((folder/'proposal2.json').read_bytes()).hexdigest()
            target=folder/'archive'/('archive-'+digest+'.json' if point=='blob' else 'archive-current.json')
            proc=archive(folder,'advance',2,env=fault('rename',target)); assert proc.returncode==-signal.SIGKILL,(point,proc.returncode,proc.stderr)
            assert archive_verify(folder,pub)['seq']==(1 if point=='blob' else 2)
            ok(archive(folder,'advance',2)); assert archive_verify(folder,pub)['seq']==2
            print('[PASS] Independent archive resumes after '+point+' publication; manifest selects verified history')
    archive_cli2=str(root/'tests/recovery-segmented-archive-operator.php')
    def archive2(folder,mode,n,env=None):
        return subprocess.run(['php',archive_cli2,mode,str(folder/'archive'),str(folder/'secret.hex'),str(folder/f'proposal{n}.ndjson')],capture_output=True,text=True,env=env,timeout=20)
    def verify2(folder,pub):
        return json.loads(ok(subprocess.run(['php',archive_cli2,'verify',str(folder/'archive'),pub],capture_output=True,text=True,timeout=20)))
    for point in ['blob','snapshot','manifest']:
        with tempfile.TemporaryDirectory(prefix='brendy-archive-v2-fault-') as folder:
            folder=pathlib.Path(folder); pub=ok(php(arch_setup,folder))
            for n in [1,2]:
                p=json.loads((folder/f'proposal{n}.json').read_text()); records=[json.dumps(r,separators=(',',':'))+'\n' for r in p['history']]
                (folder/f'proposal{n}.ndjson').write_text(json.dumps({'schema':2,'installation':p['installation']})+'\n'+''.join(records)+json.dumps({'end':n})+'\n')
            ok(archive2(folder,'initialize',1)); manifest=folder/'archive'/'archive-segmented-current.json'; old=manifest.read_bytes()
            digest=hashlib.sha256(''.join(records).encode()).hexdigest()
            target=folder/'archive'/('archive-segment-'+digest+'.ndjson' if point=='blob' else 'archive-manifest-'+hashlib.sha256(old).hexdigest()+'.json' if point=='snapshot' else manifest.name)
            p=archive2(folder,'advance',2,env=fault('rename',target)); assert p.returncode==-signal.SIGKILL,(point,p.returncode,p.stderr)
            assert verify2(folder,pub)['seq']==(2 if point=='manifest' else 1)
            ok(archive2(folder,'advance',2)); assert verify2(folder,pub)['seq']==2
            print('[PASS] Segmented archive survives SIGKILL after '+point+' publication')
    gc_setup="""require $argv[1]; require dirname($argv[1]).'/recovery-segmented-archive.class.php'; $d=$argv[2]; $sk=trim(file_get_contents($d.'/secret.hex')); $p=json_decode(file_get_contents($d.'/proposal1.json'),true); $cp=RecoveryLedger::signAnchor($p,null,$sk); file_put_contents($d.'/checkpoint.json',$cp); $dir=$d.'/archive'; foreach(['unpublished A','unpublished B'] as $b) { $f=$dir.'/archive-segment-'.hash('sha256',$b).'.ndjson'; Filesystem::atomicWrite($f,$b,0600); touch($f,time()-90000); } $pub=bin2hex(sodium_crypto_sign_publickey_from_secretkey(hex2bin($sk))); $plan=RecoverySegmentedArchive::retentionPlan($dir,$pub,$cp); echo RecoveryLedger::encode(['approval'=>RecoveryLedger::digest($plan),'files'=>array_column($plan['unreferencedCandidates'],'file')]);"""
    def archive_gc(folder,mode,approval=None,env=None):
        args=['php',archive_cli2,mode,str(folder/'archive'),str(folder/'secret.hex'),str(folder/'checkpoint.json')]
        if approval is not None: args.append(approval)
        return subprocess.run(args,capture_output=True,text=True,env=env,timeout=20)
    for point in ['plan','unlink']:
        with tempfile.TemporaryDirectory(prefix='brendy-archive-gc-fault-') as folder:
            folder=pathlib.Path(folder); pub=ok(php(arch_setup,folder)); p=json.loads((folder/'proposal1.json').read_text())
            (folder/'proposal1.ndjson').write_text(json.dumps({'schema':2,'installation':p['installation']})+'\n'+''.join(json.dumps(r,separators=(',',':'))+'\n' for r in p['history'])+json.dumps({'end':1})+'\n')
            ok(archive2(folder,'initialize',1)); manifest=verify2(folder,pub)
            plan=json.loads(ok(php(gc_setup,folder))); target=folder/'archive'/('archive-gc-journal.json' if point=='plan' else plan['files'][0])
            proc=archive_gc(folder,'gc-start',plan['approval'],env=fault('rename' if point=='plan' else 'unlink',target)); assert proc.returncode==-signal.SIGKILL,(point,proc.returncode,proc.stderr)
            ok(archive_gc(folder,'gc-resume')); assert all(not (folder/'archive'/name).exists() for name in plan['files']) and verify2(folder,pub)==manifest
            print('[PASS] Archive GC resumes after SIGKILL at '+point+'; protected history unchanged')
    # Hierarchy migration never changes original receipt shards; primary is the commit point.
    hierarchy_seed="""require $argv[1]; $r=$argv[2]; mkdir($r.'/tmp',0700); $l=new RecoveryLedger($r.'/tmp'); if (!$l->initialize()) exit(1); $s=$l->read(); for ($i=1;$i<=513;$i++) { $id=sprintf('%032x',$i); $s['history'][]=['schema'=>1,'seq'=>$i,'id'=>$id,'stage'=>'restore-'.$id,'result'=>'rolled-back','time'=>1700000000,'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',$id)]; } exit($l->save($s)?0:1);"""
    migrate="require $argv[1]; exit((new RecoveryLedger($argv[2].'/tmp'))->migrateHierarchy()?0:1);"
    hierarchy_verify="""require $argv[1]; $l=new RecoveryLedger($argv[2].'/tmp'); $q=$l->query(null,[],257); if ($q===false || $q['total']!==513 || $q['sequence']['id']!==sprintf('%032x',257)) exit(1); $r=file_get_contents($argv[2].'/tmp/'.RecoveryLedger::FILE); echo $l->open($r,'ledger-index-v3')===false?'v2':'v3';"""
    for target,expected in [('restore-ledger-mirror.json','v2'),('restore-ledger.json','v3')]:
        with tempfile.TemporaryDirectory(prefix='brendy-hierarchy-fault-') as folder:
            folder=pathlib.Path(folder); ok(php(hierarchy_seed,folder)); before=sorted((folder/'tmp').glob('restore-ledger-shard-*.json'))
            hashes={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in before}
            proc=php(migrate,folder,env=fault('rename',folder/'tmp'/target)); assert proc.returncode==-signal.SIGKILL,(target,proc.returncode,proc.stderr)
            assert ok(php(hierarchy_verify,folder))==expected
            ok(php(migrate,folder)); assert ok(php(hierarchy_verify,folder))=='v3' and ok(php(status,folder))=='in-sync'
            assert all(hashlib.sha256((folder/'tmp'/n).read_bytes()).hexdigest()==h for n,h in hashes.items())
            print('[PASS] Hierarchy migration SIGKILL at '+target+'; same receipts and original shards preserved')
    nonce_seed="""require $argv[1]; require dirname($argv[1]).'/recovery-nonce-store.class.php'; $r=$argv[2]; $pair=sodium_crypto_sign_keypair(); $sk=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair)); $raw=RecoveryLedger::signAnchor(['installation'=>str_repeat('a',64),'history'=>[]],null,$sk); $cp=RecoveryLedger::verifyAnchor($raw,$pub); $nonce=RecoveryNonceStore::issue($r,$pub,$cp,time()); file_put_contents($r.'/response.json',RecoveryAnchorFreshness::respond($raw,$nonce,$sk,time())); echo $nonce;"""
    nonce_consume="""require $argv[1]; require dirname($argv[1]).'/recovery-nonce-store.class.php'; $r=$argv[2]; exit(RecoveryNonceStore::consume($r,$argv[3],file_get_contents($r.'/response.json'),time())===false?4:0);"""
    with tempfile.TemporaryDirectory(prefix='brendy-nonce-fault-') as folder:
        folder=pathlib.Path(folder); nonce=ok(php(nonce_seed,folder)); p=php(nonce_consume,folder,nonce,env=fault('rename',folder/('nonce-'+nonce+'.json')))
        assert p.returncode==-signal.SIGKILL,(p.returncode,p.stderr)
        assert php(nonce_consume,folder,nonce).returncode==4
        print('[PASS] SIGKILL after nonce consumption; signed response cannot be reused')
