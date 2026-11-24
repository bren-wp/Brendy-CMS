<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return [
 'Signed monotonic anchor rejects regression, fork, altered signatures and local rollback' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture(); $a=brendyTestTempDir('signed-anchor');
    $original=getenv('BRENDY_RECOVERY_CHECKPOINT'); $pubOriginal=getenv('BRENDY_RECOVERY_ANCHOR_PUBLIC_KEY');
    try {
        brendyTestAssert(function_exists('sodium_crypto_sign_keypair'),'ext-sodium required for anchor regression');
        $l=new RecoveryLedger($r.'/tmp');brendyTestAssert($l->initialize(),'Init failed');$genesis=$l->read();
        $kp=sodium_crypto_sign_keypair(); $secret=bin2hex(sodium_crypto_sign_secretkey($kp));$public=bin2hex(sodium_crypto_sign_publickey($kp));
        $install=$l->checkpoint($genesis)['installation']; $p0=['installation'=>$install,'history'=>[]];
        $signed0=RecoveryLedger::signAnchor($p0,null,$secret);brendyTestAssert(is_string($signed0),'Signing failed');
        $path=$a.'/anchor.json';file_put_contents($path,$signed0);putenv('BRENDY_RECOVERY_CHECKPOINT='.$path);putenv('BRENDY_RECOVERY_ANCHOR_PUBLIC_KEY='.$public);
        brendyTestAssert($l->read()===$genesis,'Valid signed anchor rejected');
        $p=$j->prepare($stage);brendyTestAssert(is_array($p) && $j->recover()==='rolled-back','Restore failed');$s=$l->read();
        $p1=['installation'=>$install,'history'=>$s['history']]; $signed1=RecoveryLedger::signAnchor($p1,$signed0,$secret);
        brendyTestAssert(is_string($signed1) && RecoveryLedger::signAnchor($p0,$signed1,$secret)===false,'Regression accepted');
        $fork=$p1;$fork['history'][0]['time']++;
        brendyTestAssert(RecoveryLedger::signAnchor($fork,$signed1,$secret)===false,'Fork accepted');
        $wrong=$p1;$wrong['installation']=str_repeat('f',64);brendyTestAssert(RecoveryLedger::signAnchor($wrong,$signed1,$secret)===false,'Installation swap accepted');
        file_put_contents($path,$signed1);brendyTestAssert($l->read()===$s && !$l->save($genesis),'Pinned rollback accepted');
        $env=json_decode($signed1,true);$env['checkpoint']['seq']=0;file_put_contents($path,json_encode($env));brendyTestAssert($l->read()===false,'Tampered signature accepted');
        file_put_contents($path,RecoveryLedger::encode($l->checkpoint($s)));brendyTestAssert($l->read()===false,'Unsigned downgrade accepted');
        file_put_contents($path,$signed1);putenv('BRENDY_RECOVERY_CHECKPOINT');brendyTestAssert($l->read()===false && $l->exists(),'Missing signed anchor accepted');
        putenv('BRENDY_RECOVERY_CHECKPOINT='.$path);brendyTestAssert($l->read()===$s && $l->rotateKey() && $l->read()===$s,'HMAC rotation changed independent identity');
    } finally { foreach (['BRENDY_RECOVERY_CHECKPOINT'=>$original,'BRENDY_RECOVERY_ANCHOR_PUBLIC_KEY'=>$pubOriginal] as $k=>$v) putenv($v===false?$k:$k.'='.$v); brendyTestRemoveTree($r);brendyTestRemoveTree($a); }
 },
 'Streaming history prefix preserves legacy digest bytes' => static function () {
    $history=[['seq'=>1,'text'=>'Hrvatska / čćž'],['seq'=>2,'value'=>['a'=>true,'b'=>null]],['seq'=>3,'id'=>'x']];
    for($i=0;$i<=count($history);$i++) brendyTestAssert(RecoveryLedger::historyPrefix($history,$i)===RecoveryLedger::digest(array_slice($history,0,$i)),'Prefix digest compatibility broken');
 },
 'GC evidence migration retains completed proof and rejects retired token signatures' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        $p=$j->prepare($stage);brendyTestAssert(is_array($p) && $j->apply($p) && $j->commit($p) && $j->recover()==='committed','Commit failed');
        $l=new RecoveryLedger($r.'/tmp');$state=$l->read();$state['history'][0]['time']=time()-90000;brendyTestAssert($l->save($state),'Age failed');
        $name='backup-restore-rollback-'.$p['id'];$item=array_column($j->inventory()['items'],null,'name')[$name];
        brendyTestAssert($j->cleanupVerified($name,$item['token']),'GC failed');
        $base=$r.'/tmp/restore-gc-'.$p['id'];$oldPlan=file_get_contents($base.'.json');$oldCursor=file_get_contents($base.'-cursor.json');
        $plan=$l->open($oldPlan,'gc-plan-v1');$cursor=$l->open($oldCursor,'gc-cursor-v1');
        brendyTestAssert(is_array($plan) && is_array($cursor) && $l->rotateKey(),'Evidence migration failed');
        brendyTestAssert($l->open($oldPlan,'gc-plan-v1')===false && $l->open($oldCursor,'gc-cursor-v1')===false,'Old HMAC accepted');
        brendyTestAssert($l->open(file_get_contents($base.'.json'),'gc-plan-v1')===$plan && $l->open(file_get_contents($base.'-cursor.json'),'gc-cursor-v1')===$cursor && $l->read()===$state,'Payload changed');
        brendyTestAssert($l->rotateKey() && $l->read()===$state,'Repeated rotation failed');journalAssertNew($r);
    } finally { brendyTestRemoveTree($r); }
 },
 'Orphan shard collector preserves references, rejects young or malformed files and divergent mirror' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        $l=new RecoveryLedger($r.'/tmp');brendyTestAssert($l->initialize(),'Init failed');$state=$l->read();
        for($i=1;$i<=256;$i++) $state['history'][]=['schema'=>1,'seq'=>$i,'id'=>sprintf('%032x',$i),'stage'=>'restore-'.sprintf('%032x',$i),'result'=>'rolled-back','time'=>time(),'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',(string)$i)];
        brendyTestAssert($l->save($state),'Shard seed failed');$tmp=$r.'/tmp/';$index=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v2');$ref=$index['shards'][0];
        $original=file_get_contents($tmp.RecoveryLedger::FILE);$old=$state;$old['history'][0]['time']--;
        brendyTestAssert($l->save($old),'Orphan seed failed');$other=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v2')['shards'][0];
        brendyTestAssert($l->save($state),'Restore index failed');
        $names=['restore-ledger-shard-'.$other.'.json','restore-ledger-shard-copy-'.$other.'.json'];
        $bad='restore-ledger-shard-'.str_repeat('b',64).'.json';Filesystem::atomicWrite($tmp.$bad,'bad',0600);touch($tmp.$bad,time()-90000);
        foreach ([$ref,$other] as $digest) foreach (['','copy-'] as $copy) touch($tmp.'restore-ledger-shard-'.$copy.$digest.'.json',time()-90000);
        touch($tmp.$names[1],time());brendyTestAssert($l->orphanShards()===[$names[0]],'Young shard eligible');touch($tmp.$names[1],time()-90000);
        $mirror=$tmp.RecoveryLedger::MIRROR;Filesystem::atomicWrite($mirror,'invalid',0600);brendyTestAssert($l->orphanShards(true)===false,'Divergent mirror sweep allowed');
        brendyTestAssert($l->reconcileMirror(),'Reconcile failed');$list=$l->orphanShards();sort($list);sort($names);brendyTestAssert($list===$names,'Wrong candidate set');
        $removed=$l->orphanShards(true);sort($removed);brendyTestAssert($removed===$names && is_file($tmp.$bad) && $l->read()===$state && $l->mirrorStatus()==='in-sync','Sweep damaged evidence');
        brendyTestAssert($l->orphanShards(true)===[],'Sweep not idempotent');
    } finally { brendyTestRemoveTree($r); }
 },
 'Recovery inventory limits admin page copies while keeping totals and order' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        $l=new RecoveryLedger($r.'/tmp');brendyTestAssert($l->initialize(),'Init failed');$s=$l->read();
        for($i=1;$i<=101;$i++) $s['history'][]=['schema'=>1,'seq'=>$i,'id'=>sprintf('%032x',$i),'stage'=>'restore-'.sprintf('%032x',$i),'result'=>'rolled-back','time'=>time(),'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',(string)$i)];
        brendyTestAssert($l->save($s),'Seed failed');$one=$j->inventory(1);$two=$j->inventory(2);$three=$j->inventory(3);
        brendyTestAssert($one['historyTotal']===101 && count($one['history'])===50 && $one['history'][0]['seq']===101 && $two['history'][0]['seq']===51 && count($three['history'])===1 && $three['history'][0]['seq']===1,'Page bounds wrong');
        brendyTestAssert($j->inventory(999)['historyPage']===1 && count($j->inventory()['history'])===101,'Compatibility wrong');
    } finally { brendyTestRemoveTree($r); }
 },
];
