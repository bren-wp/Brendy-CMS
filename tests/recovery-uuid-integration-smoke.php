<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
return [
 'Large disk-backed query preserves receipts and detects distant duplicate IDs' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        $l=new RecoveryLedger($r.'/tmp');
        brendyTestAssert($l->initialize(),'init');
        $s=$l->read(); $s['history']=streamingTestHistory(4097);
        brendyTestAssert($l->save($s),'seed');
        $q=$l->query(1,[$s['history'][0]['id']],4097);
        brendyTestAssert($q!==false && $q['total']===4097 && $q['last']===$s['history'][4096] && $q['matches'][$s['history'][0]['id']]===$s['history'][0],'query');
        $bad=$s; $bad['history'][4096]['id']=$s['history'][0]['id'];
        brendyTestAssert($l->save($bad) && $l->query()===false,'distant duplicate');
        brendyTestAssert($l->save($s) && $l->query()!==false,'repair');
    } finally { brendyTestRemoveTree($r); }
 },
 'Disk-backed query rejects active ID collision and keeps explicit index compatibility' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize(),'init');
        $s=$l->read(); $s['history']=streamingTestHistory(4097);
        brendyTestAssert($l->save($s),'seed');
        $q=$l->query(null,[],null,false,true);
        brendyTestAssert($q!==false && $q['idIndex'][$s['history'][4096]['id']]===4097,'index contract');
        $bad=$s; $bad['active']=['id'=>$s['history'][0]['id'],'seq'=>4098];
        brendyTestAssert($l->save($bad) && $l->query()===false,'active duplicate');
    } finally { brendyTestRemoveTree($r); }
 },
];
