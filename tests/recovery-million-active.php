<?php
// Isolated synthetic benchmark; never seed an existing CMS installation.
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/bootstrap.php';
$mode=$argv[1]??''; $r=$argv[2]??'';
if (realpath($r)!==$r || !is_dir($r) || (@fileperms($r)&0777)!==0700 ||
    !in_array($mode,['seed','exercise'],true) || RecoveryLedger::transactionLimit()!==1000000) exit(2);
$l=new RecoveryLedger($r.'/tmp'); $start=hrtime(true);
if ($mode==='seed') {
    if (count(scandir($r))!==2) exit(2);
    mkdir($r.'/tmp',0700); mkdir($r.'/databases',0700);
    $stage='restore-'.str_repeat('a',32);
    mkdir($r.'/tmp/'.$stage.'/sadrzaj/databases',0700,true);
    file_put_contents($r.'/databases/site.php','old');
    file_put_contents($r.'/tmp/'.$stage.'/sadrzaj/databases/site.php','new');
    brendyTestAssert($l->initialize(),'init');
    $entries=[]; $shards=[]; $pages=[]; $total=999999;
    $publish=static function(string $kind,array $payload,string $domain) use ($l,$r): string {
        $raw=$l->seal($payload,$domain); brendyTestAssert(is_string($raw),'seal');
        $digest=hash('sha256',$raw);
        foreach (['','copy-'] as $copy) brendyTestAssert(Filesystem::atomicWrite($r.'/tmp/restore-ledger-'.$kind.'-'.$copy.$digest.'.json',$raw,0600),'publish');
        return $digest;
    };
    for ($i=1;$i<=$total;$i++) {
        $id=sprintf('%032x',$i);
        $entries[]=['schema'=>1,'seq'=>$i,'id'=>$id,'stage'=>'restore-'.$id,'result'=>'rolled-back','time'=>1700000000,'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',$id)];
        if (count($entries)===RecoveryLedger::SHARD_SIZE) {
            $shards[]=$publish('shard',['schema'=>1,'start'=>$i-count($entries)+1,'entries'=>$entries],'ledger-shard-v2');
            $entries=[];
            if (count($shards)===RecoveryLedger::INDEX_PAGE_SIZE) {
                $pages[]=$publish('page',['schema'=>1,'start'=>count($pages)*RecoveryLedger::INDEX_PAGE_SIZE,'shards'=>$shards],'ledger-page-v3');
                $shards=[];
            }
        }
    }
    if ($shards) $pages[]=$publish('page',['schema'=>1,'start'=>count($pages)*RecoveryLedger::INDEX_PAGE_SIZE,'shards'=>$shards],'ledger-page-v3');
    $root=$l->seal(['schema'=>3,'active'=>null,'records'=>$total,'pages'=>$pages,'tail'=>$entries],'ledger-index-v3');
    foreach ([RecoveryLedger::MIRROR,RecoveryLedger::FILE] as $name) brendyTestAssert(Filesystem::atomicWrite($r.'/tmp/'.$name,$root,0600),'root');
    echo RecoveryLedger::encode(['seedRecords'=>$total,'pages'=>count($pages),'peakBytes'=>memory_get_peak_usage(true),'ms'=>(hrtime(true)-$start)/1e6]).PHP_EOL; exit;
}
$measure=[];
$run=static function(string $name,callable $call) use (&$measure) {
    gc_collect_cycles(); memory_reset_peak_usage(); $base=memory_get_usage(false); $start=hrtime(true);
    $result=$call(); $measure[$name]=['ms'=>(hrtime(true)-$start)/1e6,'additionalPeakBytes'=>memory_get_peak_usage(false)-$base];
    echo RecoveryLedger::encode(['operation'=>$name]+$measure[$name]).PHP_EOL;
    return $result;
};
$id=sprintf('%032x',500000);
$q=$run('query999999',fn()=>$l->query(1,[$id],500000));
brendyTestAssert($q!==false && $q['total']===999999 && $q['sequence']['id']===$id && $q['matches'][$id]===$q['sequence'],'full query'); unset($q);
brendyTestAssert($run('indexRebuild',fn()=>$l->rebuildLookup()),'rebuild');
brendyTestAssert($run('indexedLookup',fn()=>$l->lookup($id))['seq']===500000,'lookup');
$j=new RestoreJournal($r); $stage='restore-'.str_repeat('a',32);
putenv('BRENDY_RECOVERY_LARGE_LEDGER');
brendyTestAssert(RecoveryLedger::transactionLimit()===100000 && $j->prepare($stage)===false,'default limit');
putenv('BRENDY_RECOVERY_LARGE_LEDGER=1');
$p=$run('prepare',fn()=>$j->prepare($stage)); brendyTestAssert(is_array($p),'prepare');
brendyTestAssert($run('applyCommitRecover',fn()=>$j->apply($p) && $j->commit($p) && $j->recover()==='committed'),'restore');
$q=$run('query1000000',fn()=>$l->query()); brendyTestAssert($q!==false && $q['total']===1000000 && $q['last']['id']===$p['id'] && file_get_contents($r.'/databases/site.php')==='new','commit'); unset($q);
brendyTestAssert($run('mirror',fn()=>$l->mirrorStatus())==='in-sync','mirror');
brendyTestAssert($run('indexedNew',fn()=>$l->lookup($p['id']))['seq']===1000000,'new lookup');
$next='restore-'.str_repeat('b',32); mkdir($r.'/tmp/'.$next.'/sadrzaj/databases',0700,true);
file_put_contents($r.'/tmp/'.$next.'/sadrzaj/databases/site.php','next');
brendyTestAssert($j->prepare($next)===false,'hard cap');
brendyTestAssert($run('checkpoint',fn()=>$l->checkpointSummary())['seq']===1000000,'checkpoint');
brendyTestAssert($run('rotate',fn()=>$l->rotateKey()),'rotation');
brendyTestAssert($run('postRotationMirror',fn()=>$l->mirrorStatus())==='in-sync' && $l->lookup($p['id'])['seq']===1000000,'rotation verification');
echo RecoveryLedger::encode(['records'=>1000000,'memoryLimit'=>ini_get('memory_limit'),'measurements'=>$measure,'peakBytes'=>memory_get_peak_usage(true)]).PHP_EOL;
