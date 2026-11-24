<?php
if (PHP_SAPI!=='cli') exit;
return [
 'Authenticated UUID trie splits crowded buckets, rejects tampering and updates derived path' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize(),'init');
        $s=$l->read(); $s['history']=streamingTestHistory(257);
        // Deliberately concentrate IDs in one hash bucket.
        $i=0;
        foreach ($s['history'] as &$receipt) {
            do { $id=sprintf('%032x',++$i); } while (!str_starts_with(hash('sha256',$id),'ab'));
            $receipt['id']=$id; $receipt['stage']='restore-'.$id;
        }
        unset($receipt);
        brendyTestAssert($l->save($s) && $l->rebuildLookup(),'seed');
        $tmp=$r.'/tmp/'; $id=$s['history'][256]['id'];
        $root=$l->open(file_get_contents($tmp.'restore-ledger-lookup-bucket-ab.json'),'ledger-lookup-bucket-v1');
        brendyTestAssert($root['schema']===2 && count($root['children'])>1 && $l->lookup($id)===$s['history'][256],'split/lookup');
        $prefix=substr(hash('sha256',$id),0,3);
        $path=$tmp.RecoveryLedger::LOOKUP_DIRECTORY.'/ab/'.substr($prefix,-1).'.json';
        brendyTestAssert(is_file($path) && (@fileperms(dirname($path))&0777)===0700,'hierarchical private node');
        $legacy=$tmp.'restore-ledger-lookup-bucket-'.$prefix.'.json';
        $original=file_get_contents($path);
        brendyTestAssert(rename($path,$legacy) && $l->lookup($id)===$s['history'][256],'legacy flat fallback');
        brendyTestAssert($l->rebuildLookup() && file_get_contents($path)===$original,'cache migration');
        unlink($legacy);
        $old=file_get_contents($path); Filesystem::atomicWrite($path,'tampered',0600);
        brendyTestAssert($l->lookup($id)===$s['history'][256] && $l->query()!==false,'tamper fallback');
        Filesystem::atomicWrite($path,$old,0600);
        $p=$j->prepare($stage); brendyTestAssert(is_array($p),'prepare');
        brendyTestAssert($j->apply($p) && $j->commit($p) && $j->recover()==='committed','restore');
        brendyTestAssert($l->lookup($p['id'])===$l->query()['last'] && $l->lookup($id)===$s['history'][256],'mutation');
        brendyTestAssert($l->rotateKey() && $l->lookup($id)===$s['history'][256],'rotation');
    } finally { brendyTestRemoveTree($r); }
 },
];
