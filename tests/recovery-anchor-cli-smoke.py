#!/usr/bin/env python3
"""Independent operator publication and CMS verification, isolated local accounts simulation."""
import json, os, pathlib, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parent.parent
bootstrap=str(root/'tests/bootstrap.php')
def run(args,expected=0,env=None):
    p=subprocess.run(args,capture_output=True,text=True,env=env,timeout=20)
    assert p.returncode==expected,(p.returncode,p.stdout,p.stderr)
    return p.stdout
with tempfile.TemporaryDirectory(prefix='brendy-anchor-cli-') as folder:
    base=pathlib.Path(folder); content=base/'content'; content.mkdir(); (content/'tmp').mkdir(mode=0o700); operator=base/'operator';operator.mkdir(mode=0o700)
    secret=operator/'secret.hex';anchor=operator/'anchor.json';proposal=operator/'proposal.json'
    keys=json.loads(run(['php','-r', '$k=sodium_crypto_sign_keypair();echo json_encode([bin2hex(sodium_crypto_sign_secretkey($k)),bin2hex(sodium_crypto_sign_publickey($k))]);']))
    fd=os.open(secret,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
    with os.fdopen(fd,'w') as f:f.write(keys[0])
    seed='require $argv[1]; $l=new RecoveryLedger($argv[2]."/tmp");exit($l->initialize()?0:1);'
    run(['php','-r',seed,bootstrap,str(content)])
    cmd=['php',str(root/'tests/recovery-ledger-admin.php'),'anchor-proposal',str(content)]
    proposal.write_text(run(cmd)); old_proposal=proposal.read_text()
    def publish(mode,expected=0):return run(['php',str(root/'tests/recovery-anchor-operator.php'),mode,str(anchor),str(secret),str(proposal)],expected)
    publish('initialize');publish('initialize',4);old_anchor=anchor.read_text()
    env=dict(os.environ,BRENDY_RECOVERY_CHECKPOINT=str(anchor),BRENDY_RECOVERY_ANCHOR_PUBLIC_KEY=keys[1]);run(cmd,env=env)
    print('[PASS] Independent signer creates signed genesis; CMS accepts it; reinitialization refused')
    append='require $argv[1];$l=new RecoveryLedger($argv[2]."/tmp");$s=$l->read();$s["history"][]=["schema"=>1,"seq"=>1,"id"=>str_repeat("a",32),"stage"=>"restore-".str_repeat("a",32),"result"=>"rolled-back","time"=>time(),"databaseVerified"=>true,"contentVerified"=>true,"marker"=>str_repeat("b",64)];exit($l->save($s)?0:1);'
    run(['php','-r',append,bootstrap,str(content)],env=env);proposal.write_text(run(cmd,env=env));publish('advance');new_anchor=anchor.read_text();assert new_anchor!=old_anchor
    run(cmd,env=env);proposal.write_text(old_proposal);publish('advance',5);assert anchor.read_text()==new_anchor
    print('[PASS] Operator monotonic advance succeeds; old proposal rejected without replacing anchor')
    streamed=subprocess.run(['php',str(root/'tests/recovery-anchor-operator.php'),'advance-stdin',str(anchor),str(secret),'-'],input=run(cmd,env=env),capture_output=True,text=True,timeout=20)
    assert streamed.returncode==0 and anchor.read_text()==new_anchor,(streamed.returncode,streamed.stderr)
    refused=subprocess.run(['php',str(root/'tests/recovery-anchor-operator.php'),'advance-stdin',str(anchor),str(secret),'-'],input=old_proposal,capture_output=True,text=True,timeout=20)
    assert refused.returncode==5 and anchor.read_text()==new_anchor
    print('[PASS] Piped proposals advance idempotently; stale stdin proposal refused')
    lookup=['php',str(root/'tests/recovery-ledger-admin.php'),'lookup',str(content)]
    assert json.loads(run(lookup+['1'],env=env))==json.loads(run(lookup+['a'*32],env=env))
    run(lookup+['2'],9,env=env);run(lookup+['bad'],2,env=env)
    print('[PASS] CLI transaction lookup by sequence and ID; missing and invalid lookup refused')
    anchor.unlink();publish('advance',4);run(cmd,4,env=env)
    print('[PASS] Missing authoritative anchor blocks advance and CMS read')
