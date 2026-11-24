<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return [
 'HMAC journal rejects changed payload and unsigned downgrade' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p),'Prepare failed');
        $path=$r.'/tmp/'.RestoreJournal::FILE; $raw=file_get_contents($path); $env=json_decode($raw,true); $env['payload']=str_replace('prepared','committed',$env['payload']);
        Filesystem::atomicWrite($path,json_encode($env),0600); brendyTestAssert($j->recover()==='blocked','Tampered HMAC accepted'); journalAssertOld($r);
        $legacy=$p; $legacy['schema']=1; Filesystem::atomicWrite($path,json_encode($legacy),0600);
        brendyTestAssert($j->recover()==='blocked','Unsigned downgrade accepted');
        Filesystem::atomicWrite($path,$raw,0600); brendyTestAssert($j->recover()==='rolled-back','Authentic retry failed');
    } finally { brendyTestRemoveTree($r); }
 },
 'Ledger detects missing active journal and altered ledger without deleting evidence' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p),'Apply failed');
        $path=$r.'/tmp/'.RestoreJournal::FILE; $raw=file_get_contents($path); unlink($path);
        brendyTestAssert($j->pending() && $j->recover()==='blocked','Missing marker not detected'); journalAssertNew($r);
        Filesystem::atomicWrite($path,$raw,0600);
        $lp=$r.'/tmp/'.RecoveryLedger::FILE; $ledger=file_get_contents($lp); $env=json_decode($ledger,true); $env['payload']=str_replace('prepared','committed',$env['payload']); Filesystem::atomicWrite($lp,json_encode($env),0600);
        brendyTestAssert($j->recover()==='blocked','Ledger tampering accepted');
        Filesystem::atomicWrite($lp,$ledger,0600); brendyTestAssert($j->recover()==='rolled-back','Retry failed'); journalAssertOld($r);
    } finally { brendyTestRemoveTree($r); }
 },
 'Ledger history persists and rejects replay of older authenticated marker' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); $path=$r.'/tmp/'.RestoreJournal::FILE; $first=file_get_contents($path);
        brendyTestAssert($j->recover()==='rolled-back','First rollback failed');
        $stage2='restore-'.str_repeat('b',32); mkdir($r.'/tmp/'.$stage2.'/sadrzaj/databases',0700,true); file_put_contents($r.'/tmp/'.$stage2.'/sadrzaj/databases/site.php','new2');
        $q=$j->prepare($stage2); brendyTestAssert(is_array($q) && $q['seq']===2 && $j->apply($q) && $j->commit($q),'Second commit failed');
        brendyTestAssert($j->recover()==='committed','Finalize failed'); $history=(new RestoreJournal($r))->history();
        brendyTestAssert(count($history)===2 && $history[0]['id']===$p['id'] && $history[1]['id']===$q['id'],'History lost');
        Filesystem::atomicWrite($path,$first,0600); brendyTestAssert($j->recover()==='blocked' && file_get_contents($r.'/databases/site.php')==='new2','Replay changed live state');
    } finally { brendyTestRemoveTree($r); }
 },
 'Finalized lingering journal is idempotent and cannot roll back committed data' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); $path=$r.'/tmp/'.RestoreJournal::FILE; $raw=file_get_contents($path);
        brendyTestAssert($j->apply($p) && $j->commit($p) && $j->recover()==='committed','Commit failed');
        Filesystem::atomicWrite($path,$raw,0600);
        brendyTestAssert($j->recover()==='committed' && count($j->history())===1 && !$j->pending(),'Lingering marker duplicated history'); journalAssertNew($r);
    } finally { brendyTestRemoveTree($r); }
 },
 'Interrupted initial ledger activation adopts untouched authenticated marker only' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize(),'Initialization failed'); $genesis=$l->read();
        $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $l->save($genesis),'Fixture failed');
        brendyTestAssert($j->recover()==='rolled-back' && count($j->history())===1,'Prepare gap failed'); journalAssertOld($r);
    } finally { brendyTestRemoveTree($r); }
 },
 'Committed media and empty directory integrity is checked after restart' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { mkdir($incoming.'/uploads/empty',0700,true); file_put_contents($incoming.'/uploads/photo.jpg','media');
        $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p) && $j->commit($p),'Commit failed');
        file_put_contents($r.'/uploads/photo.jpg','MEDIA'); brendyTestAssert($j->recover()==='blocked','Modified media accepted');
        file_put_contents($r.'/uploads/photo.jpg','media'); rmdir($r.'/uploads/empty'); brendyTestAssert($j->recover()==='blocked','Missing empty directory accepted');
        mkdir($r.'/uploads/empty'); brendyTestAssert($j->recover()==='committed','Restored content failed verification');
    } finally { brendyTestRemoveTree($r); }
 },
 'Trusted postprocessing accepts protection and canonical aliases but not media edits' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $uuid=str_repeat('a',32); mkdir($r.'/uploads/pages/'.$uuid,0700,true); file_put_contents($r.'/uploads/pages/'.$uuid.'/old.jpg','old');
        symlink($r.'/uploads/pages/'.$uuid,$r.'/uploads/pages/old-alias');
        mkdir($incoming.'/uploads/pages/'.$uuid,0700,true); file_put_contents($incoming.'/uploads/pages/'.$uuid.'/new.jpg','new');
        $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p),'Legitimate old alias rejected');
        symlink($r.'/uploads/pages/'.$uuid,$r.'/uploads/pages/new-alias'); file_put_contents($r.'/uploads/.htaccess','protected');
        file_put_contents($r.'/uploads/pages/'.$uuid.'/new.jpg','tampered'); brendyTestAssert(!$j->commit($p,true),'Postprocess blessed media corruption');
        file_put_contents($r.'/uploads/pages/'.$uuid.'/new.jpg','new'); brendyTestAssert($j->commit($p,true) && $j->recover()==='committed','Safe alias/protection update rejected');
        brendyTestAssert(is_link($r.'/uploads/pages/new-alias'),'Alias lost');
    } finally { brendyTestRemoveTree($r); }
 },
 'Restore rejects arbitrary symlinks and hardlinked nested files' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { mkdir($incoming.'/uploads'); symlink('/etc',$incoming.'/uploads/external'); brendyTestAssert($j->prepare($stage)===false,'External link accepted');
        unlink($incoming.'/uploads/external'); file_put_contents($incoming.'/uploads/a','a'); link($incoming.'/uploads/a',$incoming.'/uploads/b'); brendyTestAssert($j->prepare($stage)===false,'Nested hardlink accepted');
    } finally { brendyTestRemoveTree($r); }
 },
 'Verified GC requires aged authenticated receipt and unchanged rollback data' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p) && $j->commit($p) && $j->recover()==='committed','Commit failed');
        $name='backup-restore-rollback-'.$p['id']; $path=$r.'/tmp/'.$name;
        brendyTestAssert(array_column($j->inventory()['items'],null,'name')[$name]['token']===null,'Young rollback offered');
        $l=new RecoveryLedger($r.'/tmp'); $state=$l->read(); $state['history'][0]['time']=time()-90000; brendyTestAssert($l->save($state),'Age fixture failed');
        $item=array_column($j->inventory()['items'],null,'name')[$name]; brendyTestAssert($item['verified'] && is_string($item['token']),'Verified rollback not offered');
        file_put_contents($path.'/only-old','changed'); brendyTestAssert(!$j->cleanupVerified($name,$item['token']) && is_file($path.'/only-old'),'Tampered rollback removed');
        file_put_contents($path.'/only-old','old file'); brendyTestAssert(!$j->cleanupVerified($name,str_repeat('0',64)),'Invalid GC token accepted');
        brendyTestAssert($j->cleanupVerified($name,$item['token']) && !file_exists($path) && count($j->history())===1,'Verified GC failed'); journalAssertNew($r);
    } finally { brendyTestRemoveTree($r); }
 },
 'Ledger key loss fails closed rather than silently regenerating' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p),'Prepare failed'); $key=$r.'/tmp/'.RecoveryLedger::KEY;
        brendyTestAssert((fileperms($key)&0777)===0600,'Key permissions wrong'); unlink($key);
        brendyTestAssert($j->pending() && $j->recover()==='blocked' && $j->prepare($stage)===false && !file_exists($key),'Missing key regenerated'); journalAssertOld($r);
    } finally { brendyTestRemoveTree($r); }
 },
];
