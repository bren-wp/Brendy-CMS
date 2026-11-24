<?php
if (PHP_SAPI!=='cli') exit;
return [
 'Archive GC requires approved inventory and resumes without deleting protected history' => static function () {
    $dir=brendyTestTempDir('archivegc');
    try {
        $pair=sodium_crypto_sign_keypair(); $key=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
        $p=['installation'=>str_repeat('c',64),'history'=>streamingTestHistory(257)]; $f=segmentedArchiveInput($p); brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,true),'init'); fclose($f);
        $cp=RecoveryLedger::signAnchor($p,null,$key); $p['history']=streamingTestHistory(513); $f=segmentedArchiveInput($p); brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,false,$cp),'advance'); fclose($f);
        $cp2=RecoveryLedger::signAnchor($p,$cp,$key);
        foreach (['orphan 1','orphan 2','orphan 3'] as $blob) { $path=$dir.'/archive-segment-'.hash('sha256',$blob).'.ndjson'; Filesystem::atomicWrite($path,$blob,0600); touch($path,time()-90000); }
        $plan=RecoverySegmentedArchive::retentionPlan($dir,$pub,$cp2); $items=$plan['unreferencedCandidates']; brendyTestAssert(count($items)===3,'inventory');
        brendyTestAssert(!RecoverySegmentedArchive::garbageCollect($dir,$key,$cp2,str_repeat('0',64)),'approval');
        brendyTestAssert(!RecoverySegmentedArchive::garbageCollect($dir,$key,$cp,RecoveryLedger::digest($plan)),'stale checkpoint');
        brendyTestAssert(RecoverySegmentedArchive::garbageCollect($dir,$key,$cp2,RecoveryLedger::digest($plan),1),'start');
        brendyTestAssert(!file_exists($dir.'/'.$items[0]['file']) && is_file($dir.'/'.$items[1]['file']),'budget');
        $f=segmentedArchiveInput($p); brendyTestAssert(!RecoverySegmentedArchive::publish($dir,$f,$key,false,$cp2),'publish while GC active'); fclose($f);
        // Model the unlink -> cursor publication crash window.
        unlink($dir.'/'.$items[1]['file']);
        brendyTestAssert(RecoverySegmentedArchive::garbageCollect($dir,$key,$cp2,null,1),'resume missing');
        $journal=$dir.'/'.RecoverySegmentedArchive::GC_JOURNAL; $raw=file_get_contents($journal); $bad=json_decode($raw,true); $bad['state']['cursor']=3;
        Filesystem::atomicWrite($journal,RecoveryLedger::encode($bad),0600);
        brendyTestAssert(!RecoverySegmentedArchive::garbageCollect($dir,$key,$cp2),'tampered journal'); Filesystem::atomicWrite($journal,$raw,0600);
        // Replacement with same bytes is refused even if content hash matches.
        $last=$dir.'/'.$items[2]['file']; $bytes=file_get_contents($last); rename($last,$last.'.hold'); Filesystem::atomicWrite($last,$bytes,0600); touch($last,time()-90000);
        brendyTestAssert(!RecoverySegmentedArchive::garbageCollect($dir,$key,$cp2),'replacement'); unlink($last); rename($last.'.hold',$last);
        brendyTestAssert(RecoverySegmentedArchive::garbageCollect($dir,$key,$cp2),'resume');
        brendyTestAssert(RecoverySegmentedArchive::verify($dir,$pub,$cp2)['seq']===513 && RecoverySegmentedArchive::retentionPlan($dir,$pub,$cp2)['unreferencedCandidates']===[],'protected history');
    } finally { brendyTestRemoveTree($dir); }
 },
 'Merkle inclusion proofs verify every peak and reject altered positions and sibling hashes' => static function () {
    $dir=brendyTestTempDir('archiveproof');
    try {
        $pair=sodium_crypto_sign_keypair(); $key=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
        $p=['installation'=>str_repeat('d',64),'history'=>streamingTestHistory(1600)]; $f=segmentedArchiveInput($p); brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,true),'init'); fclose($f);
        $m=RecoverySegmentedArchive::verify($dir,$pub);
        foreach ($m['segments'] as $i=>$digest) {
            $proof=RecoverySegmentedArchive::inclusionProof($m,$i); brendyTestAssert($proof!==false && RecoverySegmentedArchive::verifyInclusion($proof,$m['root']),'inclusion '.$i);
            $bad=$proof; $bad['digest']=str_repeat('0',64); brendyTestAssert(!RecoverySegmentedArchive::verifyInclusion($bad,$m['root']),'digest');
            $bad=$proof; $bad['index']=($i+1)%count($m['segments']); brendyTestAssert(!RecoverySegmentedArchive::verifyInclusion($bad,$m['root']),'position');
            if ($proof['siblings']) { $bad=$proof; $bad['siblings'][0]=str_repeat('0',64); brendyTestAssert(!RecoverySegmentedArchive::verifyInclusion($bad,$m['root']),'sibling'); }
        }
    } finally { brendyTestRemoveTree($dir); }
 },
 'Merkle append consistency verifies complete-segment prefixes and rejects partial tails' => static function () {
    $dir=brendyTestTempDir('archiveconsistency');
    try {
        $pair=sodium_crypto_sign_keypair(); $key=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
        $p=['installation'=>str_repeat('e',64),'history'=>streamingTestHistory(768)]; $f=segmentedArchiveInput($p); brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,true),'init'); fclose($f);
        $old=RecoverySegmentedArchive::verify($dir,$pub); $p['history']=streamingTestHistory(1600); $f=segmentedArchiveInput($p); brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,false),'advance'); fclose($f);
        $new=RecoverySegmentedArchive::verify($dir,$pub); $proof=RecoverySegmentedArchive::consistencyProof($old,$new);
        brendyTestAssert($proof!==false && RecoverySegmentedArchive::verifyConsistency($proof,$old['root'],$new['root']),'consistent');
        $bad=$proof; $bad['suffix'][0]=str_repeat('0',64); brendyTestAssert(!RecoverySegmentedArchive::verifyConsistency($bad,$old['root'],$new['root']),'tampered suffix');
        $p['history']=streamingTestHistory(1700); $f=segmentedArchiveInput($p); brendyTestAssert(RecoverySegmentedArchive::publish($dir,$f,$key,false),'advance partial'); fclose($f);
        brendyTestAssert(RecoverySegmentedArchive::consistencyProof($new,RecoverySegmentedArchive::verify($dir,$pub))===false,'partial tail must use receipt prefix');
    } finally { brendyTestRemoveTree($dir); }
 },
];
