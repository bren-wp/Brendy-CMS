<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require $argv[1]??__DIR__.'/bootstrap.php';
$r=brendyTestTempDir('ledger-writer-bench');
try {
    mkdir($r.'/tmp',0700); $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize(),'init');
    $s=$l->read(); for($i=1;$i<=16385;$i++) $s['history'][]=['schema'=>1,'seq'=>$i,'id'=>sprintf('%032x',$i),'stage'=>'restore-'.sprintf('%032x',$i),'result'=>'rolled-back','time'=>1700000000,'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',(string)$i)];
    brendyTestAssert($l->save($s),'seed'); unset($s);
    $results=[]; $mode=method_exists($l,'mutate')?'streaming':'legacy';
    for($i=0;$i<5;$i++) {
        $q=$l->query(); $seq=$q['total']+1; $id=sprintf('%032x',$seq);
        $plan=['seq'=>$seq,'id'=>$id,'stage'=>'restore-'.$id,'state'=>'prepared','head'=>RecoveryLedger::digest($q['last']),'marker'=>hash('sha256',$id)];
        $receipt=['schema'=>1,'seq'=>$seq,'id'=>$id,'stage'=>$plan['stage'],'result'=>'rolled-back','time'=>1700000000,'databaseVerified'=>true,'contentVerified'=>true,'marker'=>$plan['marker']]; unset($q);
        gc_collect_cycles();memory_reset_peak_usage();$before=memory_get_usage(false);$start=hrtime(true);
        if ($mode==='streaming') brendyTestAssert($l->mutate(null,$plan),'prepare');
        else { $s=$l->read();$s['active']=$plan;brendyTestAssert($l->save($s),'prepare');unset($s); }
        $results['prepare'][]=['ms'=>(hrtime(true)-$start)/1e6,'incrementalPeakBytes'=>memory_get_peak_usage(false)-$before];
        gc_collect_cycles();memory_reset_peak_usage();$before=memory_get_usage(false);$start=hrtime(true);
        if ($mode==='streaming') brendyTestAssert($l->mutate($plan,null,$receipt),'finish');
        else { $s=$l->read();$s['history'][]=$receipt;$s['active']=null;brendyTestAssert($l->save($s),'finish');unset($s); }
        $results['finish'][]=['ms'=>(hrtime(true)-$start)/1e6,'incrementalPeakBytes'=>memory_get_peak_usage(false)-$before];
    }
    if ($mode==='streaming') brendyTestAssert($l->rebuildLookup(),'index');
    foreach (['full','indexed'] as $type) {
        if ($type==='indexed' && $mode!=='streaming') continue;
        for($i=0;$i<5;$i++) {
            $id=sprintf('%032x',257);gc_collect_cycles();memory_reset_peak_usage();$before=memory_get_usage(false);$start=hrtime(true);
            if ($type==='indexed') $receipt=$l->lookup($id); else {$q=$l->query(null,[$id]);$receipt=$q['matches'][$id];unset($q);}
            brendyTestAssert($receipt['seq']===257,'lookup');
            $results[$type][]=['ms'=>(hrtime(true)-$start)/1e6,'incrementalPeakBytes'=>memory_get_peak_usage(false)-$before];
        }
    }
    echo json_encode(['version'=>BRENDY_VERSION,'php'=>PHP_VERSION,'initialRecords'=>16385,'mode'=>$mode,'measurements'=>$results],JSON_PRETTY_PRINT)."\n";
} finally {brendyTestRemoveTree($r);}
