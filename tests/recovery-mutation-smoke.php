<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
return [
    'Streaming mutation rolls over tail without modifying immutable shards; rejects stale and replayed plans' => static function () {
        [$r,$stage,$incoming,$j]=journalFixture();
        try {
            $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize(),'init');
            $s=$l->read(); $s['history']=streamingTestHistory(511); brendyTestAssert($l->save($s),'seed');
            $tmp=$r.'/tmp/'; $before=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v2');
            $first=$tmp.'restore-ledger-shard-'.$before['shards'][0].'.json'; $identity=RestoreJournal::identity($first);
            brendyTestAssert($l->rebuildLookup(),'build index');
            $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $p['seq']===512,'prepare');
            brendyTestAssert(!$l->mutate(null,$p) && !$l->mutate($p,null),'stale or receiptless mutation accepted');
            brendyTestAssert($j->apply($p) && $j->commit($p) && $j->recover()==='committed','commit');
            $after=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v2');
            brendyTestAssert($after['records']===512 && count($after['shards'])===2 && $after['tail']===[] && $after['shards'][0]===$before['shards'][0] && RestoreJournal::identity($first)===$identity,'shard was rewritten or rollover wrong');
            $q=$l->query(); brendyTestAssert($q['total']===512 && $l->lookup($p['id'])===$q['last'] && !$l->mutate(null,$p),'lookup/replay wrong');
            $map=$l->open(file_get_contents($tmp.RecoveryLedger::LOOKUP),'ledger-lookup-v2');
            brendyTestAssert($map['generation']===hash_file('sha256',$tmp.RecoveryLedger::FILE) && $map['records']===512 && isset($map['buckets'][substr(hash('sha256',$p['id']),0,2)]),'index not updated');
        } finally { brendyTestRemoveTree($r); }
    },
    'Derived index rejects stale or forged cache, preserves full integrity query and rotation compatibility' => static function () {
        [$r,$stage,$incoming,$j]=journalFixture();
        try {
            $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize(),'init');
            $s=$l->read(); $s['history']=streamingTestHistory(513); brendyTestAssert($l->save($s) && $l->rebuildLookup(),'seed/index');
            $tmp=$r.'/tmp/'; $cache=$tmp.RecoveryLedger::LOOKUP; $id=$s['history'][256]['id']; $old=file_get_contents($cache);
            brendyTestAssert($l->lookup($id)===$s['history'][256] && $l->lookup(str_repeat('f',32))===null,'lookup');
            Filesystem::atomicWrite($cache,'tampered',0600);
            brendyTestAssert($l->lookup($id)===$s['history'][256],'forged cache fallback');
            Filesystem::atomicWrite($cache,$old,0600);
            brendyTestAssert($l->rotateKey() && $l->lookup($id)===$s['history'][256] && $l->rebuildLookup(),'rotation fallback');
            $index=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v2'); $digest=$index['shards'][0];
            foreach (['','copy-'] as $copy) Filesystem::atomicWrite($tmp.'restore-ledger-shard-'.$copy.$digest.'.json','broken',0600);
            // Explicit fast lookup is scoped to the selected shard; global query is fail-closed.
            brendyTestAssert($l->lookup($id)===$s['history'][256] && $l->query()===false && $j->pending() && !$l->rebuildLookup(),'global checks bypassed');
            brendyTestAssert($l->lookup($s['history'][0]['id'])===false && $l->lookup(str_repeat('f',32))===false,'missing/corrupt fallback');
        } finally { brendyTestRemoveTree($r); }
    },
    'Streaming archive is compatible with anchor proposal and emits nothing for corrupt history' => static function () {
        [$r,$stage,$incoming,$j]=journalFixture();
        try {
            $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize(),'init');
            $s=$l->read(); $s['history']=streamingTestHistory(1025); brendyTestAssert($l->save($s),'seed');
            $out=fopen('php://temp','w+b'); brendyTestAssert($l->exportHistory($out),'export'); rewind($out);
            $data=json_decode(stream_get_contents($out),true,64,JSON_THROW_ON_ERROR); fclose($out);
            brendyTestAssert($data===['installation'=>$l->checkpoint($s)['installation'],'history'=>$s['history']],'export mismatch');
            $tmp=$r.'/tmp/'; $idx=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v2'); $digest=end($idx['shards']);
            foreach (['','copy-'] as $copy) Filesystem::atomicWrite($tmp.'restore-ledger-shard-'.$copy.$digest.'.json','broken',0600);
            $out=fopen('php://temp','w+b'); brendyTestAssert(!$l->exportHistory($out) && ftell($out)===0,'partial export'); fclose($out);
        } finally { brendyTestRemoveTree($r); }
    },
];
