<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
function streamingTestHistory(int $count): array {
    $history=[];
    for ($i=1;$i<=$count;$i++) $history[]=['schema'=>1,'seq'=>$i,'id'=>sprintf('%032x',$i),'stage'=>'restore-'.sprintf('%032x',$i),'result'=>'rolled-back','time'=>1700000000,'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',(string)$i)];
    return $history;
}
return [
    'Segment query preserves pages, batch IDs, sequence and legacy full read' => static function () {
        [$r,$stage,$incoming,$j]=journalFixture();
        try {
            $l=new RecoveryLedger($r.'/tmp');brendyTestAssert($l->initialize(),'Init failed');$s=$l->read();$s['history']=streamingTestHistory(769);
            brendyTestAssert($l->save($s),'Seed failed');
            $ids=[sprintf('%032x',1),sprintf('%032x',256),sprintf('%032x',769)];$q=$l->query(11,$ids,257);
            brendyTestAssert($q!==false && $q['total']===769 && $q['history']===array_slice($s['history'],219,50) && $q['last']===$s['history'][768] && $q['sequence']===$s['history'][256],'Query incorrect');
            brendyTestAssert(array_values($q['matches'])===[$s['history'][0],$s['history'][255],$s['history'][768]] && $l->read()===$s,'Selected receipts wrong');
            brendyTestAssert($l->query(999,[],770)['sequence']===null && $l->query(null,['bad'])===false && $l->query(null,[],0)===false,'Invalid query accepted');
            $inv=$j->inventory(11);brendyTestAssert($inv['historyTotal']===769 && $inv['history']===array_reverse($q['history']),'Inventory mismatch');
        } finally { brendyTestRemoveTree($r); }
    },
    'Segment query rejects duplicates and corruption outside requested page' => static function () {
        [$r,$stage,$incoming,$j]=journalFixture();
        try {
            $l=new RecoveryLedger($r.'/tmp');brendyTestAssert($l->initialize(),'Init failed');$s=$l->read();$s['history']=streamingTestHistory(513);
            brendyTestAssert($l->save($s),'Seed failed');$tmp=$r.'/tmp/';$index=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v2');$d=$index['shards'][0];
            $first=$tmp.'restore-ledger-shard-'.$d.'.json';$copy=$tmp.'restore-ledger-shard-copy-'.$d.'.json';$raw=file_get_contents($first);
            Filesystem::atomicWrite($first,'corrupt',0600);brendyTestAssert($l->query()!==false,'Exact mirror fallback failed');
            Filesystem::atomicWrite($copy,'corrupt',0600);brendyTestAssert($l->query()===false && $j->pending(),'Corruption outside page escaped');
            Filesystem::atomicWrite($first,$raw,0600);Filesystem::atomicWrite($copy,$raw,0600);
            $bad=$s;$bad['history'][512]['id']=$bad['history'][0]['id'];brendyTestAssert($l->save($bad) && $l->query()===false,'Cross-segment duplicate accepted');
            brendyTestAssert($l->save($s) && $l->query()!==false,'Repair fixture failed');
        } finally { brendyTestRemoveTree($r); }
    },
    'Retirement ledger authorizes exact old-key orphans but not active or tampered files' => static function () {
        [$r,$stage,$incoming,$j]=journalFixture();
        try {
            $l=new RecoveryLedger($r.'/tmp');brendyTestAssert($l->initialize(),'Init failed');$s=$l->read();$s['history']=streamingTestHistory(256);
            brendyTestAssert($l->save($s),'Seed failed');$tmp=$r.'/tmp/';$d=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v2')['shards'][0];
            $names=['restore-ledger-shard-'.$d.'.json','restore-ledger-shard-copy-'.$d.'.json'];$old=file_get_contents($tmp.$names[0]);
            brendyTestAssert($l->rotateKey() && $l->retirementCount()===1 && $l->open($old,'ledger-shard-v2')===false,'Retirement failed');
            $d2=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v2')['shards'][0];
            foreach ([$d,$d2] as $digest) foreach (['','copy-'] as $copy) touch($tmp.'restore-ledger-shard-'.$copy.$digest.'.json',time()-90000);
            $proofPath=$tmp.RecoveryLedger::RETIREMENT;$proof=file_get_contents($proofPath);Filesystem::atomicWrite($proofPath,'tampered',0600);
            brendyTestAssert($l->orphanShards(true)===false && is_file($tmp.$names[0]),'Tampered retirement proof permitted deletion');
            Filesystem::atomicWrite($proofPath,$proof,0600);$list=$l->orphanShards();sort($list);sort($names);brendyTestAssert($list===$names,'Unexpected retired candidates');
            brendyTestAssert($l->rotateKey() && $l->retirementCount()===2,'Certificate migration failed');
            $list=$l->orphanShards(true);sort($list);brendyTestAssert(count($list)===4 && $l->read()===$s && $l->mirrorStatus()==='in-sync' && $l->retirementCount()===2,'Retired sweep damaged active records');
        } finally { brendyTestRemoveTree($r); }
    },
];
