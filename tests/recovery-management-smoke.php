<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return [
 'Recovery receipt records committed result and verified database digest' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert($p['schema']===3 && $j->apply($p) && $j->commit($p),'Commit failed');
        brendyTestAssert($j->recover()==='committed','Recovery failed');
        $last=(new RestoreJournal($r))->lastResult();
        brendyTestAssert($last['id']===$p['id'] && $last['result']==='committed' && $last['databaseVerified']===true,'Receipt invalid');
        brendyTestAssert((fileperms($r.'/tmp/'.RestoreJournal::RECEIPT)&0777)===0600,'Receipt not private');
    } finally { brendyTestRemoveTree($r); }
 },
 'Receipt publication failure preserves pending journal and allows retry' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p),'Apply failed');
        mkdir($r.'/tmp/'.RestoreJournal::RECEIPT);
        brendyTestAssert($j->recover()==='blocked' && $j->pending(),'Journal lost after receipt failure'); journalAssertOld($r);
        rmdir($r.'/tmp/'.RestoreJournal::RECEIPT);
        brendyTestAssert($j->recover()==='rolled-back' && !$j->pending(),'Retry failed');
        brendyTestAssert($j->lastResult()['result']==='rolled-back','Rollback receipt missing');
    } finally { brendyTestRemoveTree($r); }
 },
 'Recovery detects in-place modification of original database before rollback' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p),'Apply failed');
        $old=$r.'/tmp/backup-restore-rollback-'.$p['id'].'/databases/site.php'; file_put_contents($old,'bad');
        brendyTestAssert($j->recover()==='blocked' && $j->pending(),'Tampered rollback accepted'); journalAssertNew($r);
    } finally { brendyTestRemoveTree($r); }
 },
 'Recovery detects in-place modification of committed database' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p) && $j->commit($p),'Commit failed');
        file_put_contents($r.'/databases/site.php','bad');
        brendyTestAssert($j->recover()==='blocked' && $j->pending(),'Tampered commit accepted');
    } finally { brendyTestRemoveTree($r); }
 },
 'Recovery refuses staged database modification before apply' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p),'Prepare failed'); file_put_contents($incoming.'/databases/site.php','bad');
        brendyTestAssert(!$j->apply($p),'Modified staging accepted'); journalAssertOld($r);
        brendyTestAssert($j->recover()==='rolled-back','Rollback of unmodified original failed');
    } finally { brendyTestRemoveTree($r); }
 },
 'Recovery schema 1 remains readable with honest legacy receipt' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p),'Prepare failed'); $p['schema']=1; unset($p['oldDigest'],$p['newDigest'],$p['seq'],$p['head'],$p['marker']);
        unlink($r.'/tmp/'.RecoveryLedger::MIRROR); unlink($r.'/tmp/'.RecoveryLedger::FILE); unlink($r.'/tmp/'.RecoveryLedger::KEY); // isolated pre-upgrade fixture only
        brendyTestAssert(Filesystem::atomicWrite($r.'/tmp/'.RestoreJournal::FILE,json_encode($p),0600),'Legacy fixture failed');
        brendyTestAssert($j->recover()==='rolled-back' && $j->lastResult()['databaseVerified']===false,'Legacy journal incompatible');
    } finally { brendyTestRemoveTree($r); }
 },
 'Recovery inventory only offers old empty directories, not data or symlinks' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $empty='restore-'.str_repeat('b',32); $nested='restore-'.str_repeat('c',32); $link='restore-'.str_repeat('d',32); $young='restore-'.str_repeat('e',32);
        mkdir($r.'/tmp/'.$empty); mkdir($r.'/tmp/'.$nested.'/sadrzaj',0700,true); symlink($incoming,$r.'/tmp/'.$link); mkdir($r.'/tmp/'.$young);
        foreach ([$empty,$nested,$stage] as $name) touch($r.'/tmp/'.$name,time()-90000);
        $i=$j->inventory(); $items=array_column($i['items'],null,'name');
        brendyTestAssert($i['complete'] && $items[$empty]['token']!==null && $items[$nested]['token']!==null && $items[$stage]['token']===null && $items[$young]['token']===null && !$items[$link]['safeDirectory'],'Invalid eligibility');
        brendyTestAssert($j->cleanupEmpty($empty,$items[$empty]['token']) && $j->cleanupEmpty($nested,$items[$nested]['token']),'Empty cleanup failed');
        brendyTestAssert(is_file($incoming.'/databases/site.php') && is_link($r.'/tmp/'.$link),'Cleanup removed recovery data');
    } finally { brendyTestRemoveTree($r); }
 },
 'Recovery cleanup refuses stale token, nonempty path, traversal and pending journal' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $name='restore-'.str_repeat('b',32); $path=$r.'/tmp/'.$name; mkdir($path); touch($path,time()-90000);
        $token=array_column($j->inventory()['items'],null,'name')[$name]['token'];
        rename($path,$path.'-old'); mkdir($path); touch($path,time()-90000);
        brendyTestAssert(!$j->cleanupEmpty($name,$token),'Replaced directory removed');
        $token=array_column($j->inventory()['items'],null,'name')[$name]['token'];
        file_put_contents($path.'/keep','keep'); touch($path,time()-90000);
        brendyTestAssert(!$j->cleanupEmpty($name,$token) && file_get_contents($path.'/keep')==='keep','Data removed');
        unlink($path.'/keep'); touch($path,time()-90000);
        $token=array_column($j->inventory()['items'],null,'name')[$name]['token'];
        brendyTestAssert(!$j->cleanupEmpty('../'.$name,$token),'Traversal accepted');
        $p=$j->prepare($stage); brendyTestAssert(is_array($p) && !$j->cleanupEmpty($name,$token) && $j->inventory()['pending'],'Pending cleanup accepted');
    } finally { brendyTestRemoveTree($r); }
 },
 'Recovery receipt rejects malformed fields and symbolic link' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $path=$r.'/tmp/'.RestoreJournal::RECEIPT;
        Filesystem::atomicWrite($path,'{"schema":1,"id":"<script>"}',0600);
        brendyTestAssert($j->lastResult()===null,'Malformed receipt accepted'); unlink($path); symlink($r.'/only-old',$path);
        brendyTestAssert($j->lastResult()===null,'Receipt symlink accepted');
    } finally { brendyTestRemoveTree($r); }
 },
 'Owned recursive cleanup refuses a replaced root before traversing' => static function () {
    $r=realpath(brendyTestTempDir('owned-recovery-root'));
    try { mkdir($r.'/stage'); $id=RestoreJournal::identity($r.'/stage'); rename($r.'/stage',$r.'/old'); mkdir($r.'/stage'); file_put_contents($r.'/stage/keep','keep');
        brendyTestAssert(!Filesystem::deleteRecursive($r.'/stage',true,$id) && file_get_contents($r.'/stage/keep')==='keep','Replacement deleted');
        brendyTestAssert(Filesystem::deleteRecursive($r.'/old',true,$id),'Owned root not removed');
    } finally { brendyTestRemoveTree($r); }
 },
 'Tree digest refuses excessive depth without changing legacy hash format' => static function () {
    $r=realpath(brendyTestTempDir('tree-depth'));
    try { file_put_contents($r.'/a','abc'); $digest=Filesystem::treeDigest($r);
        brendyTestAssert($digest['hash']===hash('sha256','1:a:3:'.hash('sha256','abc')."\n"),'Legacy digest changed');
        $path=$r; for($i=0;$i<130;$i++) { $path.='/d';mkdir($path); }
        brendyTestAssert(Filesystem::treeDigest($r)===false,'Excessive directory depth accepted');
    } finally { brendyTestRemoveTree($r); }
 },
];
