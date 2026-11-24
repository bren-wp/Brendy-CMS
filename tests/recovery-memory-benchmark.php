<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require $argv[1]??__DIR__.'/bootstrap.php';
$r=brendyTestTempDir('ledger-memory');
try {
    mkdir($r.'/tmp',0700);$l=new RecoveryLedger($r.'/tmp');brendyTestAssert($l->initialize(),'Init failed');$s=$l->read();
    for($i=1;$i<=16385;$i++) $s['history'][]=['schema'=>1,'seq'=>$i,'id'=>sprintf('%032x',$i),'stage'=>'restore-'.sprintf('%032x',$i),'result'=>'rolled-back','time'=>1700000000,'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',(string)$i)];
    brendyTestAssert($l->save($s),'Seed failed');unset($s);
    $results=[];
    foreach (['mirror','checkpoint'] as $mode) {
        $state=$mode==='checkpoint'?$l->read():null;
        for($i=0;$i<5;$i++) {
            gc_collect_cycles();memory_reset_peak_usage();$before=memory_get_usage(false);$start=hrtime(true);
            if ($mode==='mirror') brendyTestAssert($l->mirrorStatus()==='in-sync','Mirror failed');
            else { $cp=$l->checkpoint($state);brendyTestAssert($cp['seq']===16385,'Checkpoint failed'); }
            $results[$mode][]=['ms'=>(hrtime(true)-$start)/1e6,'incrementalPeakBytes'=>memory_get_peak_usage(false)-$before];
        }
        unset($state);
    }
    echo json_encode(['version'=>BRENDY_VERSION,'php'=>PHP_VERSION,'records'=>16385,'measurements'=>$results],JSON_PRETTY_PRINT)."\n";
} finally {brendyTestRemoveTree($r);}
