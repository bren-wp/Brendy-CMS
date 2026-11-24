<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/recovery-archive.class.php';
return [
    'Sharded UUID cache verifies bucket bytes, preserves unchanged buckets and falls back on corruption' => static function () {
        [$r,$stage,$incoming,$j]=journalFixture();
        try {
            $l=new RecoveryLedger($r.'/tmp'); brendyTestAssert($l->initialize(),'init');
            $s=$l->read(); $s['history']=streamingTestHistory(513); brendyTestAssert($l->save($s) && $l->rebuildLookup(),'seed');
            $tmp=$r.'/tmp/'; $manifest=$l->open(file_get_contents($tmp.RecoveryLedger::LOOKUP),'ledger-lookup-v2');
            $id=$s['history'][256]['id']; $bucket=substr(hash('sha256',$id),0,2); $path=$tmp.'restore-ledger-lookup-bucket-'.$bucket.'.json';
            brendyTestAssert(count($manifest['buckets'])>1 && $l->lookup($id)===$s['history'][256],'lookup');
            $raw=file_get_contents($path); Filesystem::atomicWrite($path,'tampered',0600); brendyTestAssert($l->lookup($id)===$s['history'][256],'fallback');
            Filesystem::atomicWrite($path,$raw,0600);
            $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p) && $j->commit($p) && $j->recover()==='committed','restore');
            $after=$l->open(file_get_contents($tmp.RecoveryLedger::LOOKUP),'ledger-lookup-v2'); $changed=substr(hash('sha256',$p['id']),0,2);
            foreach ($manifest['buckets'] as $b=>$digest) if ((string)$b!==$changed) brendyTestAssert($after['buckets'][$b]===$digest,'unrelated bucket rewritten');
            brendyTestAssert($after['records']===514 && $l->lookup($p['id'])===$l->query()['last'],'incremental');
            brendyTestAssert($l->rotateKey() && $l->lookup($id)===$s['history'][256],'rotation');
        } finally { brendyTestRemoveTree($r); }
    },
    'Independent signed archive rejects corruption, forks, replay and wrong keys; retains old blob' => static function () {
        $dir=brendyTestTempDir('archive');
        try {
            $pair=sodium_crypto_sign_keypair(); $secret=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
            $p=['installation'=>str_repeat('a',64),'history'=>streamingTestHistory(256)]; $data=RecoveryLedger::encode($p);
            brendyTestAssert(RecoveryArchive::publish($dir,$data,$secret,true),'initialize');
            $old=RecoveryArchive::verify($dir,$pub); $oldRaw=file_get_contents($dir.'/'.RecoveryArchive::MANIFEST);
            brendyTestAssert($old['seq']===256 && !RecoveryArchive::publish($dir,$data,$secret,true),'initialize replay');
            $p['history']=streamingTestHistory(257); $new=RecoveryLedger::encode($p); $cp=RecoveryLedger::signAnchor($p,null,$secret);
            brendyTestAssert(RecoveryArchive::publish($dir,$new,$secret,false) && RecoveryArchive::verify($dir,$pub,$cp)['seq']===257,'advance');
            brendyTestAssert(!RecoveryArchive::publish($dir,$data,$secret,false),'rollback');
            $fork=$p; $fork['history'][0]['time']++; brendyTestAssert(!RecoveryArchive::publish($dir,RecoveryLedger::encode($fork),$secret,false),'fork');
            $bad=$p; $bad['history'][256]['id']=$bad['history'][0]['id']; brendyTestAssert(!RecoveryArchive::publish($dir,RecoveryLedger::encode($bad),$secret,false),'duplicate');
            $manifest=$dir.'/'.RecoveryArchive::MANIFEST; $fresh=file_get_contents($manifest);
            Filesystem::atomicWrite($manifest,$oldRaw,0600); brendyTestAssert(RecoveryArchive::verify($dir,$pub,$cp)===false,'independent checkpoint rollback');
            Filesystem::atomicWrite($manifest,$fresh,0600); $current=RecoveryArchive::verify($dir,$pub); $blob=$dir.'/archive-'.$current['sha256'].'.json';
            brendyTestAssert(RecoveryArchive::verify($dir,str_repeat('0',64))===false && is_file($dir.'/archive-'.$old['sha256'].'.json'),'wrong key or old blob missing');
            Filesystem::atomicWrite($blob,'changed',0600); brendyTestAssert(RecoveryArchive::verify($dir,$pub)===false,'tamper');
        } finally { brendyTestRemoveTree($dir); }
    },
];
