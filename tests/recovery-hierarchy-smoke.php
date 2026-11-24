<?php
if (PHP_SAPI!=='cli') exit;
require_once __DIR__.'/recovery-nonce-store.class.php';
return [
 'Hierarchy migration retains original shard references, receipts, mirror and GC eligibility' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        $l=new RecoveryLedger($r.'/tmp'); $tmp=$r.'/tmp/'; brendyTestAssert($l->initialize(),'init');
        $s=$l->read(); $s['history']=streamingTestHistory(513); brendyTestAssert($l->save($s) && $l->rebuildLookup(),'seed');
        $legacy=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v2');
        brendyTestAssert($l->migrateHierarchy() && $l->read()===$s && $l->checkpointSummary()===$l->checkpoint($s) && $l->mirrorStatus()==='in-sync','migration');
        $new=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v3');
        $digest=$new['pages'][0]; $page=$tmp.'restore-ledger-page-'.$digest.'.json'; $copy=$tmp.'restore-ledger-page-copy-'.$digest.'.json';
        brendyTestAssert($l->open(file_get_contents($page),'ledger-page-v3')['shards']===$legacy['shards'],'shards changed');
        brendyTestAssert($l->lookup($s['history'][256]['id'])===$s['history'][256],'index lost');
        foreach ($legacy['shards'] as $d) foreach (['','copy-'] as $c) touch($tmp.'restore-ledger-shard-'.$c.$d.'.json',time()-90000);
        brendyTestAssert($l->orphanShards()===[],'live shards marked orphan');
        $bytes=file_get_contents($page); Filesystem::atomicWrite($page,'corrupt',0600);
        brendyTestAssert($l->query()['total']===513 && $l->mirrorStatus()==='shard-degraded','fallback');
        brendyTestAssert($l->reconcileMirror() && file_get_contents($page)===$bytes && $l->mirrorStatus()==='in-sync','page repair');
        Filesystem::atomicWrite($page,'corrupt',0600); Filesystem::atomicWrite($copy,'corrupt',0600);
        brendyTestAssert($l->query()===false && !$l->reconcileMirror() && $l->orphanShards()===false,'double corruption not refused');
        Filesystem::atomicWrite($copy,$bytes,0600); brendyTestAssert($l->reconcileMirror(),'repair');
        $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p) && $j->commit($p) && $j->recover()==='committed','restore');
        $last=$l->query()['last']; brendyTestAssert($last['seq']===514 && $l->lookup($p['id'])===$last && $l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v3')!==false,'mutation');
        brendyTestAssert($l->rotateKey() && $l->query()['last']===$last && $l->mirrorStatus()==='in-sync','rotation');
    } finally { brendyTestRemoveTree($r); }
 },
 'Hierarchy migration keeps committed GC receipts and evidence byte-identical' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p) && $j->commit($p) && $j->recover()==='committed','restore');
        $l=new RecoveryLedger($r.'/tmp'); $s=$l->read(); $s['history'][0]['time']=time()-90000;
        $extra=streamingTestHistory(257); array_shift($extra); $s['history']=array_merge($s['history'],$extra); brendyTestAssert($l->save($s),'seed');
        $name='backup-restore-rollback-'.$p['id']; $item=array_column($j->inventory()['items'],null,'name')[$name];
        brendyTestAssert($j->cleanupVerified($name,$item['token']),'cleanup');
        $base=$r.'/tmp/restore-gc-'.$p['id']; $plan=file_get_contents($base.'.json'); $cursor=file_get_contents($base.'-cursor.json');
        brendyTestAssert($l->migrateHierarchy() && $l->read()===$s && file_get_contents($base.'.json')===$plan && file_get_contents($base.'-cursor.json')===$cursor,'GC evidence changed');
        brendyTestAssert($l->rotateKey() && $l->read()===$s && $l->mirrorStatus()==='in-sync','GC rotation after migration');
    } finally { brendyTestRemoveTree($r); }
 },
 'Partial-tail Merkle proof verifies same segment and cross-segment extension' => static function () {
    $dir=brendyTestTempDir('partial-proof');
    try {
        $pair=sodium_crypto_sign_keypair(); $key=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
        $p=['installation'=>str_repeat('a',64),'history'=>streamingTestHistory(257)]; $f=segmentedArchiveInput($p); brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,true),'init'); fclose($f);
        $old=RecoverySegmentedArchive::verify($dir,$pub);
        foreach ([300,769] as $n) {
            $p['history']=streamingTestHistory($n); $f=segmentedArchiveInput($p); brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,false),'advance'); fclose($f);
            $new=RecoverySegmentedArchive::verify($dir,$pub); $proof=RecoverySegmentedArchive::partialConsistencyProof($dir,$old,$new);
            brendyTestAssert(is_array($proof) && RecoverySegmentedArchive::verifyPartialConsistency($proof,$old,$new),'proof');
            $bad=$proof; $bad['boundary']=str_replace('rolled-back','committed',$bad['boundary']); brendyTestAssert(!RecoverySegmentedArchive::verifyPartialConsistency($bad,$old,$new),'changed boundary');
            $bad=$new; $bad['installation']=str_repeat('b',64); brendyTestAssert(!RecoverySegmentedArchive::verifyPartialConsistency($proof,$old,$bad),'different installation');
            if ($proof['suffix']) { $bad=$proof; $bad['suffix'][0]=str_repeat('0',64); brendyTestAssert(!RecoverySegmentedArchive::verifyPartialConsistency($bad,$old,$new),'suffix'); }
        }
    } finally { brendyTestRemoveTree($dir); }
 },
 'Segmented archive publishes independent monotonic checkpoint without monolithic history' => static function () {
    $dir=brendyTestTempDir('checkpoint-archive'); $operator=brendyTestTempDir('checkpoint-operator');
    try {
        $pair=sodium_crypto_sign_keypair(); $key=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
        $p=['installation'=>str_repeat('a',64),'history'=>streamingTestHistory(257)]; $f=segmentedArchiveInput($p); brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,true),'init'); fclose($f);
        $anchor=$operator.'/checkpoint.json'; brendyTestAssert(RecoverySegmentedArchive::publishCheckpoint($dir,$anchor,$key,true) && !RecoverySegmentedArchive::publishCheckpoint($dir,$anchor,$key,true),'init checkpoint');
        $old=file_get_contents($anchor); $oldManifest=file_get_contents($dir.'/'.RecoverySegmentedArchive::MANIFEST);
        $p['history']=streamingTestHistory(513); $f=segmentedArchiveInput($p); brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,false,$old),'advance archive'); fclose($f);
        brendyTestAssert(RecoverySegmentedArchive::publishCheckpoint($dir,$anchor,$key,false),'advance checkpoint');
        $new=file_get_contents($anchor); $cp=RecoveryLedger::verifyAnchor($new,$pub); brendyTestAssert($cp['seq']===513,'wrong head');
        Filesystem::atomicWrite($dir.'/'.RecoverySegmentedArchive::MANIFEST,$oldManifest,0600);
        brendyTestAssert(!RecoverySegmentedArchive::publishCheckpoint($dir,$anchor,$key,false) && file_get_contents($anchor)===$new,'rollback');
        unlink($anchor); brendyTestAssert(!RecoverySegmentedArchive::publishCheckpoint($dir,$anchor,$key,false),'missing anchor');
    } finally { brendyTestRemoveTree($dir); brendyTestRemoveTree($operator); }
 },
 'Persistent nonce store consumes exact signed head once and fails closed on missing state' => static function () {
    $dir=brendyTestTempDir('nonce-store');
    try {
        $pair=sodium_crypto_sign_keypair(); $key=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
        $p=['installation'=>str_repeat('a',64),'history'=>streamingTestHistory(2)]; $raw=RecoveryLedger::signAnchor($p,null,$key); $cp=RecoveryLedger::verifyAnchor($raw,$pub); $now=1700000000;
        $nonce=RecoveryNonceStore::issue($dir,$pub,$cp,$now); brendyTestAssert(is_string($nonce),'issue');
        $response=RecoveryAnchorFreshness::respond($raw,$nonce,$key,$now);
        brendyTestAssert(RecoveryNonceStore::consume($dir,$nonce,$response,$now+1)===$cp,'consume');
        brendyTestAssert(RecoveryNonceStore::consume($dir,$nonce,$response,$now+2)===false,'replay');
        $nonce=RecoveryNonceStore::issue($dir,$pub,$cp,$now); $response=RecoveryAnchorFreshness::respond($raw,$nonce,$key,$now);
        brendyTestAssert(RecoveryNonceStore::consume($dir,$nonce,$response,$now+61)===false,'expired');
        $p['history']=streamingTestHistory(3); $new=RecoveryLedger::signAnchor($p,$raw,$key);
        brendyTestAssert(RecoveryNonceStore::consume($dir,$nonce,RecoveryAnchorFreshness::respond($new,$nonce,$key,$now),$now+1)===false,'wrong exact head');
        unlink($dir.'/nonce-'.$nonce.'.json'); brendyTestAssert(RecoveryNonceStore::consume($dir,$nonce,$response,$now+1)===false,'missing state');
    } finally { brendyTestRemoveTree($dir); }
 },
];
