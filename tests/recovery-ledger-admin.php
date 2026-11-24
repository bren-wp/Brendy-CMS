<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
// Use only on a stopped/maintenance site. Does not promote a mirror or replace a key.
if (($argc!==3 && $argc!==4) || !in_array($argv[1],['status','checkpoint','reconcile','rotate','anchor-proposal','orphan-list','orphan-prune','page-orphan-list','page-orphan-prune','lookup','lookup-indexed','index-rebuild','archive-export','archive-export-v2','index-migrate'],true) || (in_array($argv[1],['lookup','lookup-indexed'],true)!==($argc===4))) {
    fwrite(STDERR,"Usage: php tests/recovery-ledger-admin.php status|checkpoint|reconcile|rotate|anchor-proposal|orphan-list|orphan-prune|page-orphan-list|page-orphan-prune|lookup|lookup-indexed|index-rebuild|index-migrate|archive-export|archive-export-v2 /absolute/content/root [id-or-sequence]\n"); exit(2);
}
$root=realpath($argv[2]);
if ($root===false || $root!==rtrim($argv[2],DS) || is_link($root.DS.'tmp') || !is_dir($root.DS.'tmp')) exit(2);
$tmp=$root.DS.'tmp';
$lock=Filesystem::acquireLock($tmp.DS.'restore-request.lock',LOCK_EX|LOCK_NB,0600);
if ($lock===false) { fwrite(STDERR,"Recovery gate busy.\n"); exit(3); }
try {
    $ledger=new RecoveryLedger($tmp);
    if ($argv[1]==='index-migrate') exit($ledger->migrateHierarchy()?0:4);
    if ($argv[1]==='index-rebuild') exit($ledger->rebuildLookup()?0:4);
    if (in_array($argv[1],['archive-export','archive-export-v2','anchor-proposal'],true)) {
        if ((new RestoreJournal($root))->pending()) exit(4);
        exit($ledger->exportHistory(STDOUT,$argv[1]==='archive-export-v2')?0:4);
    }
    if ($argv[1]==='rotate') {
        if (!$ledger->rotateKey()) { fwrite(STDERR,"Rotation refused or incomplete; preserve all recovery files and inspect status.\n"); exit(7); }
        echo "Recovery key rotated; old key retired from current key ring.\n"; exit(0);
    }
    if (in_array($argv[1],['lookup','lookup-indexed'],true)) {
        $value=$argv[3]; $id=preg_match('/^[a-f0-9]{32}$/D',$value)?$value:null;
        $seq=$id===null && preg_match('/^[1-9][0-9]{0,5}$/D',$value)?(int)$value:null;
        if ($id===null && $seq===null) exit(2);
        if ($argv[1]==='lookup-indexed' && $id!==null) {
            $receipt=$ledger->lookup($id); if ($receipt===false) exit(4);
        } else {
            $q=$ledger->query(null,$id===null?[]:[$id],$seq);
            if ($q===false) exit(4);
            $receipt=$id===null?$q['sequence']:($q['matches'][$id]??null);
        }
        if ($receipt===null) exit(9);
        echo RecoveryLedger::encode($receipt)."\n"; exit(0);
    }
    if ($argv[1]==='status') {
        $q=$ledger->query(); if ($q===false) exit(4);
        echo RecoveryLedger::encode(['mirror'=>$ledger->mirrorStatus(),'keyStatus'=>$ledger->keyStatus(),'records'=>$q['total'],'active'=>$q['active']!==null,'checkpointConfigured'=>(bool)getenv('BRENDY_RECOVERY_CHECKPOINT'),'retirementCertificates'=>$ledger->retirementCount(),'pageRetirementCertificates'=>$ledger->pageRetirementCount()])."\n";
        exit(0);
    }
    if ($argv[1]==='checkpoint') {
        if ((new RestoreJournal($root))->pending()) { fwrite(STDERR,"Finalize restore before exporting checkpoint.\n"); exit(5); }
        $cp=$ledger->checkpointSummary(); if ($cp===false) exit(4);
        echo RecoveryLedger::encode($cp)."\n";
    } elseif (in_array($argv[1],['orphan-list','orphan-prune','page-orphan-list','page-orphan-prune'],true)) {
        $result=str_starts_with($argv[1],'page-')?$ledger->orphanPages($argv[1]==='page-orphan-prune'):$ledger->orphanShards($argv[1]==='orphan-prune');
        if ($result===false) { fwrite(STDERR,"Shard sweep refused or interrupted; inspect state and rerun list.\n"); exit(8); }
        echo RecoveryLedger::encode(['files'=>$result])."\n";
    } else {
        if (!$ledger->reconcileMirror()) exit(6);
        echo "Mirror synchronized from authoritative primary.\n";
    }
} finally { Filesystem::releaseLock($lock); }
