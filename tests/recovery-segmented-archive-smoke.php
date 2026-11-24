<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/recovery-segmented-archive.class.php';
function segmentedArchiveInput(array $p,bool $trailer=true) {
    $f=tmpfile(); fwrite($f,RecoveryLedger::encode(['schema'=>2,'installation'=>$p['installation']])."\n");
    foreach ($p['history'] as $r) fwrite($f,RecoveryLedger::encode($r)."\n");
    if ($trailer) fwrite($f,RecoveryLedger::encode(['end'=>count($p['history'])])."\n"); rewind($f); return $f;
}
return [
 'Segmented archive verifies append, previous checkpoint, Merkle root and historical manifests' => static function () {
    $dir=brendyTestTempDir('segarchive');
    try {
        $pair=sodium_crypto_sign_keypair(); $key=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
        $p=['installation'=>str_repeat('a',64),'history'=>streamingTestHistory(257)]; $f=segmentedArchiveInput($p);
        brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,true),'init'); fclose($f);
        $old=RecoverySegmentedArchive::verify($dir,$pub); $oldRaw=file_get_contents($dir.'/'.RecoverySegmentedArchive::MANIFEST);
        $cp=RecoveryLedger::signAnchor($p,null,$key); $p['history']=streamingTestHistory(513); $f=segmentedArchiveInput($p);
        brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,false,$cp),'advance'); fclose($f);
        $new=RecoverySegmentedArchive::verify($dir,$pub,$cp);
        brendyTestAssert($new['seq']===513 && count($new['segments'])===3 && $old['segments'][0]===$new['segments'][0] && $old['root']!==$new['root'],'segments');
        brendyTestAssert(is_file($dir.'/archive-manifest-'.hash('sha256',$oldRaw).'.json'),'snapshot');
        $newCp=RecoveryLedger::signAnchor($p,$cp,$key); $current=file_get_contents($dir.'/'.RecoverySegmentedArchive::MANIFEST);
        Filesystem::atomicWrite($dir.'/'.RecoverySegmentedArchive::MANIFEST,$oldRaw,0600);
        brendyTestAssert(RecoverySegmentedArchive::verify($dir,$pub,$newCp)===false,'rollback');
        Filesystem::atomicWrite($dir.'/'.RecoverySegmentedArchive::MANIFEST,$current,0600);
        $orphan='unpublished'; $name='archive-segment-'.hash('sha256',$orphan).'.ndjson'; Filesystem::atomicWrite($dir.'/'.$name,$orphan,0600); touch($dir.'/'.$name,time()-90000);
        $plan=RecoverySegmentedArchive::retentionPlan($dir,$pub,$newCp);
        brendyTestAssert($plan!==false && $plan['signedSnapshots']===1 && $plan['unreferencedCandidates'][0]['file']===$name && is_file($dir.'/'.$name),'retention inventory');
        brendyTestAssert(RecoverySegmentedArchive::retentionPlan($dir,$pub,$cp)===false,'stale retention checkpoint');
        $path=$dir.'/archive-segment-'.$new['segments'][0].'.ndjson'; Filesystem::atomicWrite($path,'corrupt',0600);
        brendyTestAssert(RecoverySegmentedArchive::verify($dir,$pub)===false,'tamper');
    } finally { brendyTestRemoveTree($dir); }
 },
 'Segmented archive refuses truncated proposals, duplicate IDs and forks without publishing' => static function () {
    $dir=brendyTestTempDir('segarchive');
    try {
        $pair=sodium_crypto_sign_keypair(); $key=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
        $p=['installation'=>str_repeat('b',64),'history'=>streamingTestHistory(256)]; $f=segmentedArchiveInput($p); brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,true),'init'); fclose($f);
        $old=RecoverySegmentedArchive::verify($dir,$pub); $p['history']=streamingTestHistory(513);
        $f=segmentedArchiveInput($p,false); brendyTestAssert(!RecoverySegmentedArchive::publish($dir,$f,$key,false),'truncated'); fclose($f);
        $p['history'][512]['id']=$p['history'][0]['id']; $f=segmentedArchiveInput($p); brendyTestAssert(!RecoverySegmentedArchive::publish($dir,$f,$key,false),'duplicate'); fclose($f);
        $p['history']=streamingTestHistory(513); $p['history'][0]['time']++; $f=segmentedArchiveInput($p); brendyTestAssert(!RecoverySegmentedArchive::publish($dir,$f,$key,false),'fork'); fclose($f);
        brendyTestAssert(RecoverySegmentedArchive::verify($dir,$pub)===$old,'old pointer changed');
    } finally { brendyTestRemoveTree($dir); }
 },
 'Live ledger exports a compatible segmented proposal without altering history' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture(); $dir=brendyTestTempDir('segarchive');
    try {
        $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize(),'init'); $s=$l->read(); $s['history']=streamingTestHistory(257); brendyTestAssert($l->save($s),'seed');
        $f=tmpfile(); brendyTestAssert($l->exportHistory($f,true) && rewind($f),'export');
        $pair=sodium_crypto_sign_keypair(); $key=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
        brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,true),'publish'); fclose($f);
        $m=RecoverySegmentedArchive::verify($dir,$pub); brendyTestAssert($m['seq']===257 && $m['prefix']===RecoveryLedger::historyPrefix($s['history'],257) && $l->read()===$s,'history');
    } finally { brendyTestRemoveTree($dir); brendyTestRemoveTree($r); }
 },
];
