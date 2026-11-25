<?php
if (PHP_SAPI!=='cli') exit;
return [
 'Index page retirement protects live pages and authenticates aged old-key objects' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        $tmp=$r.'/tmp/'; $l=new RecoveryLedger($r.'/tmp');
        brendyTestAssert($l->initialize(),'init');
        $s=$l->read(); $s['history']=streamingTestHistory(513);
        brendyTestAssert($l->save($s) && $l->migrateHierarchy(),'migration');
        $root=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v3');
        $d=$root['pages'][0]; $names=['restore-ledger-page-'.$d.'.json','restore-ledger-page-copy-'.$d.'.json'];
        foreach ($names as $name) touch($tmp.$name,time()-90000);
        brendyTestAssert($l->orphanPages()===[],'live page candidate');
        brendyTestAssert($l->rotateKey() && $l->pageRetirementCount()===1,'page certificate');
        $root=$l->open(file_get_contents($tmp.RecoveryLedger::FILE),'ledger-index-v3');
        $new=$root['pages'][0];
        foreach (['','copy-'] as $prefix) touch($tmp.'restore-ledger-page-'.$prefix.$new.'.json',time()-90000);
        $path=$tmp.RecoveryLedger::RETIREMENT; $proof=file_get_contents($path);
        Filesystem::atomicWrite($path,'tampered',0600);
        brendyTestAssert($l->orphanPages(true)===false && is_file($tmp.$names[0]),'tampered certificate');
        Filesystem::atomicWrite($path,$proof,0600);
        $list=$l->orphanPages(); sort($list); sort($names);
        brendyTestAssert($list===$names,'old page inventory');
        brendyTestAssert($l->rotateKey() && $l->pageRetirementCount()===2,'certificate migration');
        foreach (['','copy-'] as $prefix) touch($tmp.'restore-ledger-page-'.$prefix.$new.'.json',time()-90000);
        $list=$l->orphanPages(true);
        brendyTestAssert(is_array($list) && count($list)===4 && $l->orphanPages(true)===[] && $l->read()===$s && $l->mirrorStatus()==='in-sync','page GC');
    } finally { brendyTestRemoveTree($r); }
 },
];
