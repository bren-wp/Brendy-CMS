<?php
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/bootstrap.php';
// Test fixture only. A fresh private empty directory is mandatory for seed.
$mode=$argv[1]??''; $r=$argv[2]??'';
if (realpath($r)!==$r || !is_dir($r) || (@fileperms($r)&0777)!==0700 || !in_array($mode,['seed','exercise'],true)) exit(2);
$start=hrtime(true); $l=new RecoveryLedger($r.'/tmp');
if ($mode==='seed') {
    if (count(scandir($r))!==2) exit(2);
    mkdir($r.'/tmp',0700); mkdir($r.'/databases',0700); $stage='restore-'.str_repeat('a',32); mkdir($r.'/tmp/'.$stage.'/sadrzaj/databases',0700,true);
    file_put_contents($r.'/databases/site.php','old'); file_put_contents($r.'/tmp/'.$stage.'/sadrzaj/databases/site.php','new');
    brendyTestAssert($l->initialize(),'init');
    $state=['schema'=>1,'active'=>null,'history'=>[]];
    for ($i=1;$i<100000;$i++) { $id=sprintf('%032x',$i); $state['history'][]=['schema'=>1,'seq'=>$i,'id'=>$id,'stage'=>'restore-'.$id,'result'=>'rolled-back','time'=>1700000000,'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',$id)]; }
    brendyTestAssert($l->save($state),'seed');
    echo RecoveryLedger::encode(['seedRecords'=>99999,'ms'=>(hrtime(true)-$start)/1e6,'peakBytes'=>memory_get_peak_usage(false)])."\n"; exit;
}
$measure=[];
$run=static function(string $name,callable $call) use (&$measure) {
    gc_collect_cycles(); memory_reset_peak_usage(); $base=memory_get_usage(false); $start=hrtime(true); $result=$call();
    $measure[$name]=['ms'=>(hrtime(true)-$start)/1e6,'additionalPeakBytes'=>memory_get_peak_usage(false)-$base]; return $result;
};
$q=$run('query99999',fn()=>$l->query(1,[sprintf('%032x',50000)],50000)); brendyTestAssert($q!==false && $q['total']===99999 && $q['sequence']['seq']===50000,'query'); unset($q);
brendyTestAssert($run('indexRebuild',fn()=>$l->rebuildLookup()),'index rebuild');
$receipt=$run('indexedLookup',fn()=>$l->lookup(sprintf('%032x',50000))); brendyTestAssert($receipt['seq']===50000,'lookup');
$j=new RestoreJournal($r); $stage='restore-'.str_repeat('a',32);
$p=$run('prepare',fn()=>$j->prepare($stage)); brendyTestAssert(is_array($p),'prepare');
brendyTestAssert($run('applyCommitRecover',fn()=>$j->apply($p) && $j->commit($p) && $j->recover()==='committed'),'commit');
$q=$run('query100000',fn()=>$l->query()); brendyTestAssert($q!==false && $q['total']===100000 && $q['last']['id']===$p['id'] && file_get_contents($r.'/databases/site.php')==='new','boundary'); unset($q);
brendyTestAssert($run('mirror',fn()=>$l->mirrorStatus())==='in-sync','mirror');
$receipt=$run('indexedNew',fn()=>$l->lookup($p['id'])); brendyTestAssert($receipt['seq']===100000,'last lookup');
$nextStage='restore-'.str_repeat('b',32); mkdir($r.'/tmp/'.$nextStage.'/sadrzaj/databases',0700,true); file_put_contents($r.'/tmp/'.$nextStage.'/sadrzaj/databases/site.php','next');
brendyTestAssert($j->prepare($nextStage)===false,'cap');
echo RecoveryLedger::encode(['records'=>100000,'memoryLimit'=>ini_get('memory_limit'),'measurements'=>$measure])."\n";
