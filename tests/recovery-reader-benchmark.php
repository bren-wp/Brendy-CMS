<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require $argv[1]??__DIR__.'/bootstrap.php';
$r=brendyTestTempDir('ledger-reader-bench');
try {
    mkdir($r.'/tmp',0700);$l=new RecoveryLedger($r.'/tmp');brendyTestAssert($l->initialize(),'Init failed');$s=$l->read();
    for($i=1;$i<=16385;$i++) $s['history'][]=['schema'=>1,'seq'=>$i,'id'=>sprintf('%032x',$i),'stage'=>'restore-'.sprintf('%032x',$i),'result'=>'rolled-back','time'=>1700000000,'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',(string)$i)];
    brendyTestAssert($l->save($s),'Seed failed');unset($s);
    $measure=[];
    for($i=0;$i<5;$i++) {
        gc_collect_cycles();memory_reset_peak_usage();$before=memory_get_usage(false);$start=hrtime(true);
        if (method_exists($l,'query')) { $q=$l->query(2,[sprintf('%032x',257)],257);brendyTestAssert($q!==false && $q['sequence']['seq']===257 && $q['total']===16385,'Query failed');$page=$q['history'];unset($q); }
        else { $s=$l->read();brendyTestAssert($s!==false && $s['history'][256]['seq']===257 && count($s['history'])===16385,'Full read failed');$page=array_slice($s['history'],16285,50);unset($s); }
        brendyTestAssert(count($page)===50 && $page[0]['seq']===16286,'Page failed');
        $measure[]=['ms'=>(hrtime(true)-$start)/1e6,'incrementalPeakBytes'=>memory_get_peak_usage(false)-$before];unset($page);
    }
    echo json_encode(['version'=>BRENDY_VERSION,'php'=>PHP_VERSION,'records'=>16385,'measurements'=>$measure],JSON_PRETTY_PRINT)."\n";
} finally {brendyTestRemoveTree($r);}
