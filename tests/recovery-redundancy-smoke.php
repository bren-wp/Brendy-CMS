<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return [
 'Mirror never promotes ahead candidate or stale state; explicit reconciliation restores redundancy' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize() && $l->mirrorStatus()==='in-sync','Genesis mirror missing');
        $primary=$r.'/tmp/'.RecoveryLedger::FILE; $mirror=$r.'/tmp/'.RecoveryLedger::MIRROR;
        $before=file_get_contents($primary); $p=$j->prepare($stage); brendyTestAssert(is_array($p),'Prepare failed');
        $ahead=file_get_contents($mirror);
        // Reproduce a candidate mirror published before authoritative primary.
        Filesystem::atomicWrite($primary,$before,0600);
        brendyTestAssert($l->mirrorStatus()==='divergent' && $l->read()['active']===null,'Mirror promoted');
        brendyTestAssert($l->reconcileMirror() && file_get_contents($mirror)===$before,'Reconciliation failed');
        Filesystem::atomicWrite($mirror,$ahead,0600); unlink($primary);
        brendyTestAssert($l->read()===false && $j->recover()==='blocked' && $l->mirrorStatus()==='primary-invalid' && !$l->reconcileMirror(),'Missing primary promoted'); journalAssertOld($r);
        Filesystem::atomicWrite($primary,$before,0600);
        brendyTestAssert($j->recover()==='rolled-back' && $l->mirrorStatus()==='in-sync','Prepared gap recovery failed');
    } finally { brendyTestRemoveTree($r); }
 },
 'Pre-upgrade single ledger remains readable and mirror repair never changes primary' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize(),'Initialization failed');
        $p=$r.'/tmp/'.RecoveryLedger::FILE; $raw=file_get_contents($p); unlink($r.'/tmp/'.RecoveryLedger::MIRROR);
        brendyTestAssert(is_array($l->read()) && $l->mirrorStatus()==='missing-or-invalid' && $l->reconcileMirror(),'Legacy repair failed');
        brendyTestAssert(file_get_contents($p)===$raw && $l->mirrorStatus()==='in-sync','Primary changed');
    } finally { brendyTestRemoveTree($r); }
 },
 'Independent pinned history detects rollback and changed historical prefix' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture(); $a=brendyTestTempDir('external-anchor'); $original=getenv('BRENDY_RECOVERY_CHECKPOINT');
    try { $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize(),'Initialization failed'); $old=$l->read();
        $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->recover()==='rolled-back','Recovery failed');
        $state=$l->read(); $checkpoint=$a.'/checkpoint.json'; file_put_contents($checkpoint,RecoveryLedger::encode($l->checkpoint($state))); putenv('BRENDY_RECOVERY_CHECKPOINT='.$checkpoint);
        brendyTestAssert($l->read()===$state && !$l->save($old),'Older state accepted');
        $modified=$state; $modified['history'][0]['time']++;
        brendyTestAssert(!$l->save($modified),'Modified prefix accepted');
        Filesystem::atomicWrite($r.'/tmp/'.RecoveryLedger::FILE,$l->seal($old,'ledger-v1'),0600);
        brendyTestAssert($l->read()===false && $j->recover()==='blocked','Pinned rollback not detected');
        Filesystem::atomicWrite($r.'/tmp/'.RecoveryLedger::FILE,$l->seal($state,'ledger-v1'),0600);
        $stage2='restore-'.str_repeat('b',32);mkdir($r.'/tmp/'.$stage2.'/sadrzaj/databases',0700,true); file_put_contents($r.'/tmp/'.$stage2.'/sadrzaj/databases/site.php','new2');
        $q=$j->prepare($stage2);brendyTestAssert(is_array($q) && $j->recover()==='rolled-back' && count($l->read()['history'])===2,'Valid history append blocked');
        file_put_contents($r.'/tmp/checkpoint.json',file_get_contents($checkpoint));putenv('BRENDY_RECOVERY_CHECKPOINT='.$r.'/tmp/checkpoint.json');
        brendyTestAssert($l->read()===false,'Checkpoint inside content accepted');
    } finally { if ($original===false) putenv('BRENDY_RECOVERY_CHECKPOINT'); else putenv('BRENDY_RECOVERY_CHECKPOINT='.$original);brendyTestRemoveTree($r);brendyTestRemoveTree($a); }
 },
 'Configured missing external checkpoint fails closed' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();$original=getenv('BRENDY_RECOVERY_CHECKPOINT');
    try { $l=new RecoveryLedger($r.'/tmp');brendyTestAssert($l->initialize(),'Init failed'); putenv('BRENDY_RECOVERY_CHECKPOINT='.$r.'/missing');
        brendyTestAssert($l->read()===false && $j->pending() && $j->recover()==='blocked','Missing checkpoint bypassed');
    } finally { if ($original===false) putenv('BRENDY_RECOVERY_CHECKPOINT');else putenv('BRENDY_RECOVERY_CHECKPOINT='.$original);brendyTestRemoveTree($r); }
 },
 'Sharded ledger migration retains history and repairs only content-addressed matching shards' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize(),'Init failed'); $state=$l->read();
        for ($i=1;$i<=RecoveryLedger::SHARD_SIZE-1;$i++) $state['history'][]=['schema'=>1,'seq'=>$i,'id'=>sprintf('%032x',$i),'stage'=>'restore-'.sprintf('%032x',$i),'result'=>'rolled-back','time'=>time(),'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',(string)$i)];
        brendyTestAssert($l->save($state) && $l->read()===$state,'Legacy history setup failed');
        $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $p['seq']===RecoveryLedger::SHARD_SIZE && $j->recover()==='rolled-back','Shard boundary failed');
        $saved=$l->read(); $index=$l->open(file_get_contents($r.'/tmp/'.RecoveryLedger::FILE),'ledger-index-v2');
        brendyTestAssert(is_array($index) && count($index['shards'])===1 && count($index['tail'])===0 && count($saved['history'])===RecoveryLedger::SHARD_SIZE,'Bad index');
        $digest=$index['shards'][0]; $shard=$r.'/tmp/restore-ledger-shard-'.$digest.'.json'; $copy=$r.'/tmp/restore-ledger-shard-copy-'.$digest.'.json';
        $raw=file_get_contents($shard); $identity=RestoreJournal::identity($shard);
        $stage2='restore-'.str_repeat('b',32);mkdir($r.'/tmp/'.$stage2.'/sadrzaj/databases',0700,true);file_put_contents($r.'/tmp/'.$stage2.'/sadrzaj/databases/site.php','new2');
        $q=$j->prepare($stage2);brendyTestAssert(is_array($q) && $j->recover()==='rolled-back','Tail append failed');$saved=$l->read();
        brendyTestAssert(RestoreJournal::identity($shard)===$identity && count($saved['history'])===RecoveryLedger::SHARD_SIZE+1,'Immutable shard rewritten or tail lost');
        unlink($shard); brendyTestAssert($l->read()===$saved && $l->mirrorStatus()==='shard-degraded','Exact shard fallback failed');
        brendyTestAssert($l->reconcileMirror() && file_get_contents($shard)===$raw && $l->mirrorStatus()==='in-sync','Shard repair failed');
        Filesystem::atomicWrite($shard,'invalid',0600); Filesystem::atomicWrite($copy,'invalid',0600);
        brendyTestAssert($l->read()===false && $j->pending() && $j->recover()==='blocked','Invalid shards accepted');
        Filesystem::atomicWrite($copy,$raw,0600);brendyTestAssert($l->read()===$saved && $l->reconcileMirror(),'Exact backup retry failed');
        brendyTestAssert($l->rotateKey() && $l->read()===$saved && $l->mirrorStatus()==='in-sync' && $l->open($raw,'ledger-shard-v2')===false,'Sharded key rotation failed');
        unlink($r.'/tmp/'.RecoveryLedger::FILE);unlink($r.'/tmp/'.RecoveryLedger::MIRROR);unlink($r.'/tmp/'.RecoveryLedger::KEY);
        brendyTestAssert($l->exists() && !$l->initialize() && $j->recover()==='blocked','Remaining shard evidence reset');
    } finally { brendyTestRemoveTree($r); }
 },
 'External checkpoint detects removal of every local ledger artifact' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture(); $a=brendyTestTempDir('external-anchor'); $original=getenv('BRENDY_RECOVERY_CHECKPOINT');
    try { $l=new RecoveryLedger($r.'/tmp');brendyTestAssert($l->initialize(),'Init failed');
        $checkpoint=$a.'/checkpoint.json';file_put_contents($checkpoint,RecoveryLedger::encode($l->checkpoint($l->read())));putenv('BRENDY_RECOVERY_CHECKPOINT='.$checkpoint);
        unlink($r.'/tmp/'.RecoveryLedger::FILE);unlink($r.'/tmp/'.RecoveryLedger::MIRROR);unlink($r.'/tmp/'.RecoveryLedger::KEY);
        brendyTestAssert($j->pending() && $j->recover()==='blocked' && !$l->initialize() && !file_exists($r.'/tmp/'.RecoveryLedger::KEY),'Anchor bypassed after deletion');
    } finally { if ($original===false) putenv('BRENDY_RECOVERY_CHECKPOINT');else putenv('BRENDY_RECOVERY_CHECKPOINT='.$original);brendyTestRemoveTree($r);brendyTestRemoveTree($a); }
 },
 'Controlled key rotation preserves checkpoint and retires previous HMAC key' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();$a=brendyTestTempDir('external-anchor');$original=getenv('BRENDY_RECOVERY_CHECKPOINT');
    try { $l=new RecoveryLedger($r.'/tmp');$p=$j->prepare($stage);brendyTestAssert(is_array($p) && !$l->rotateKey(),'Active rotation allowed');
        brendyTestAssert($j->recover()==='rolled-back','Rollback failed');$state=$l->read();$old=$l->seal(['test'=>true],'test');
        $anchor=$a.'/checkpoint.json';file_put_contents($anchor,RecoveryLedger::encode($l->checkpoint($state)));putenv('BRENDY_RECOVERY_CHECKPOINT='.$anchor);
        brendyTestAssert($l->rotateKey() && $l->read()===$state && $l->keyStatus()==='active' && $l->mirrorStatus()==='in-sync','Rotation failed');
        brendyTestAssert($l->open($old,'test')===false && $l->open($l->seal(['test'=>true],'test'),'test')===['test'=>true],'Old key still accepted');
        file_put_contents($r.'/tmp/restore-gc-'.str_repeat('a',32).'.json','evidence');brendyTestAssert(!$l->rotateKey(),'GC evidence ignored');
    } finally { if ($original===false) putenv('BRENDY_RECOVERY_CHECKPOINT');else putenv('BRENDY_RECOVERY_CHECKPOINT='.$original);brendyTestRemoveTree($r);brendyTestRemoveTree($a); }
 },
];
