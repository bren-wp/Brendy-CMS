#!/usr/bin/env python3
"""Linux-only, isolated real write syscall faults. No disk filling or production hooks."""
import os, pathlib, subprocess, tempfile, signal, shutil, sys
root=pathlib.Path(__file__).resolve().parent.parent
if not sys.platform.startswith('linux') or not shutil.which('cc'):
    print('[SKIP] Linux and C compiler required');sys.exit(2)
source=r'''
#define _GNU_SOURCE
#include <dlfcn.h>
#include <unistd.h>
#include <string.h>
#include <stdio.h>
#include <stdlib.h>
#include <errno.h>
#include <signal.h>
ssize_t write(int fd, const void *buf, size_t n) {
    static ssize_t (*real_write)(int,const void*,size_t);
    static int partial;
    if (!real_write) real_write=dlsym(RTLD_NEXT,"write");
    const char *prefix=getenv("BRENDY_FAULT_DIR"), *mode=getenv("BRENDY_FAULT_MODE");
    char proc[64], path[4096];
    snprintf(proc,sizeof(proc),"/proc/self/fd/%d",fd);
    ssize_t len=readlink(proc,path,sizeof(path)-1);
    if (len>0 && prefix && mode) {
        path[len]=0;
        if (strncmp(path,prefix,strlen(prefix))==0 && !strncmp(path+strlen(prefix),"/.brendy-write-",15)) {
            if (partial) { errno=ENOSPC; return -1; }
            partial=1;
            ssize_t result=real_write(fd,buf,n>64?64:n);
            if (!strcmp(mode,"kill")) kill(getpid(),SIGKILL);
            return result;
        }
    }
    return real_write(fd,buf,n);
}
'''
with tempfile.TemporaryDirectory(prefix='brendy-atomic-fault-') as folder:
    d=pathlib.Path(folder); (d/'fault.c').write_text(source)
    subprocess.run(['cc','-shared','-fPIC','-o',str(d/'fault.so'),str(d/'fault.c'),'-ldl'],check=True)
    path=d/'payload'; bootstrap=str(root/'tests/bootstrap.php')
    php="require $argv[1]; exit(Filesystem::atomicWrite($argv[2],str_repeat('N',2097152),0600)?0:9);"
    for mode,expected in [('enospc',9),('kill',-signal.SIGKILL)]:
        path.write_bytes(b'old-content')
        env=dict(os.environ,LD_PRELOAD=str(d/'fault.so'),BRENDY_FAULT_DIR=folder,BRENDY_FAULT_MODE=mode)
        proc=subprocess.run(['php','-r',php,bootstrap,str(path)],env=env,capture_output=True,timeout=10)
        assert proc.returncode==expected,(mode,proc.returncode,proc.stderr.decode())
        assert path.read_bytes()==b'old-content',mode
        staging=list(d.glob('.brendy-write-*'))
        if mode=='enospc': assert not staging,'Failed write leaked staging'
        else: assert len(staging)==1 and staging[0].stat().st_size==64,'No partial write evidence'
        print('[PASS] '+('Injected ENOSPC after real partial write; original preserved and staging cleaned' if mode=='enospc' else 'Actual SIGKILL after partial write; original preserved and orphan staging retained'))
        for file in staging:file.unlink()
    subprocess.run(['php','-r',php,bootstrap,str(path)],check=True,timeout=10)
    assert path.read_bytes()==b'N'*2097152
    print('[PASS] Subsequent atomic write succeeds after fault removal')
    # Reuse the same real syscall fault injector against transaction publications.
    setup="""require $argv[1]; $r=$argv[2]; $stage='restore-'.str_repeat('a',32); mkdir($r.'/tmp/'.$stage.'/sadrzaj/databases',0700,true); mkdir($r.'/databases'); file_put_contents($r.'/databases/site.php','old'); file_put_contents($r.'/tmp/'.$stage.'/sadrzaj/databases/site.php','new'); $j=new RestoreJournal($r); $l=new RecoveryLedger($r.'/tmp'); if (!$l->initialize()) exit(2); if ($argv[3]!=='prepare') { $p=$j->prepare($stage); if (!$p || !$j->apply($p)) exit(3); if ($argv[3]==='finalize' && !$j->commit($p)) exit(4); }"""
    operation="""require $argv[1]; $j=new RestoreJournal($argv[2]); if ($argv[3]==='prepare') $ok=$j->prepare('restore-'.str_repeat('a',32))!==false; elseif ($argv[3]==='commit') $ok=$j->commit($j->read()); else $ok=$j->recover()!=='blocked'; exit($ok?0:9);"""
    recover="""require $argv[1]; $j=new RestoreJournal($argv[2]); echo $j->recover();"""
    for phase,expected_recovery,expected_content in [('prepare','none','old'),('commit','rolled-back','old'),('finalize','committed','new')]:
        for mode,expected in [('enospc',9),('kill',-signal.SIGKILL)]:
            with tempfile.TemporaryDirectory(prefix='brendy-ledger-fault-') as tx:
                subprocess.run(['php','-r',setup,bootstrap,tx,phase],check=True,timeout=15)
                env=dict(os.environ,LD_PRELOAD=str(d/'fault.so'),BRENDY_FAULT_DIR=tx+'/tmp',BRENDY_FAULT_MODE=mode)
                proc=subprocess.run(['php','-r',operation,bootstrap,tx,phase],env=env,capture_output=True,timeout=15)
                assert proc.returncode==expected,(phase,mode,proc.returncode,proc.stderr.decode())
                result=subprocess.check_output(['php','-r',recover,bootstrap,tx],text=True,timeout=15)
                assert result==expected_recovery,(phase,mode,result)
                assert (pathlib.Path(tx)/'databases/site.php').read_text()==expected_content
                print('[PASS] '+phase+' '+mode+' -> '+result+'; '+expected_content+' data preserved')
