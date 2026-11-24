<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function journalFixture(): array {
    $root = realpath(brendyTestTempDir('restore-journal'));
    $stage = 'restore-' . str_repeat('a',32);
    $incoming = $root . '/tmp/' . $stage . '/sadrzaj';
    mkdir($incoming . '/databases',0700,true); mkdir($root . '/databases');
    file_put_contents($root.'/databases/site.php','old'); file_put_contents($incoming.'/databases/site.php','new');
    file_put_contents($root.'/only-old','old file'); file_put_contents($incoming.'/only-new','new file');
    return [$root,$stage,$incoming,new RestoreJournal($root)];
}
function journalAssertOld(string $root): void {
    brendyTestAssert(file_get_contents($root.'/databases/site.php')==='old' && file_get_contents($root.'/only-old')==='old file' && !file_exists($root.'/only-new'),'Old tree not recovered');
}
function journalAssertNew(string $root): void {
    brendyTestAssert(file_get_contents($root.'/databases/site.php')==='new' && file_get_contents($root.'/only-new')==='new file' && !file_exists($root.'/only-old'),'New tree not preserved');
}
return [
 'Restore journal rolls back every interrupted forward rename boundary' => static function () {
    for ($cut=0;$cut<=4;$cut++) {
        [$r,$stage,$incoming,$j]=journalFixture();
        try {
            $p=$j->prepare($stage); brendyTestAssert(is_array($p),'Prepare failed');
            $old=$r.'/tmp/backup-restore-rollback-'.$p['id'];
            $moves=[[$r.'/databases',$old.'/databases'],[$r.'/only-old',$old.'/only-old'],[$incoming.'/databases',$r.'/databases'],[$incoming.'/only-new',$r.'/only-new']];
            for ($i=0;$i<$cut;$i++) brendyTestAssert(rename(...$moves[$i]),'Fixture rename failed');
            brendyTestAssert((new RestoreJournal($r))->recover()==='rolled-back','Recovery failed at boundary '.$cut);
            journalAssertOld($r); brendyTestAssert(!$j->pending() && $j->recover()==='none','Recovery is not idempotent');
        } finally { brendyTestRemoveTree($r); }
    }
 },
 'Restore journal survives actual SIGKILL between rename operations' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        brendyTestAssert(function_exists('pcntl_fork') && function_exists('posix_kill'),'Fork/SIGKILL unavailable');
        $pid=pcntl_fork(); brendyTestAssert($pid>=0,'Fork failed');
        if ($pid===0) {
            $p=$j->prepare($stage); if (!is_array($p)) exit(2);
            $old=$r.'/tmp/backup-restore-rollback-'.$p['id'];
            if (!rename($r.'/databases',$old.'/databases')) exit(3);
            posix_kill(getmypid(),SIGKILL); exit(4);
        }
        pcntl_waitpid($pid,$status);
        brendyTestAssert(pcntl_wifsignaled($status) && pcntl_wtermsig($status)===SIGKILL,'Child was not killed');
        brendyTestAssert(!is_file($r.'/databases/site.php') && $j->pending(),'Crash fixture failed');
        brendyTestAssert($j->recover()==='rolled-back','Crash recovery failed'); journalAssertOld($r);
    } finally { brendyTestRemoveTree($r); }
 },
 'Restore journal resumes interrupted rollback' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p),'Apply failed');
        $old=$r.'/tmp/backup-restore-rollback-'.$p['id'];
        rename($r.'/only-new',$incoming.'/only-new'); rename($r.'/databases',$incoming.'/databases'); rename($old.'/only-old',$r.'/only-old');
        brendyTestAssert($j->recover()==='rolled-back','Resumed rollback failed'); journalAssertOld($r);
    } finally { brendyTestRemoveTree($r); }
 },
 'Restore journal committed marker survives restart' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p) && $j->commit($p),'Commit failed');
        brendyTestAssert((new RestoreJournal($r))->recover()==='committed','Committed recovery failed'); journalAssertNew($r);
        brendyTestAssert(file_get_contents($r.'/tmp/backup-restore-rollback-'.$p['id'].'/databases/site.php')==='old','Recovery discarded old data');
    } finally { brendyTestRemoveTree($r); }
 },
 'Restore journal preserves committed restore after actual SIGKILL' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try {
        $pid=pcntl_fork(); brendyTestAssert($pid>=0,'Fork failed');
        if ($pid===0) {
            $p=$j->prepare($stage); if (!is_array($p) || !$j->apply($p) || !$j->commit($p)) exit(2);
            posix_kill(getmypid(),SIGKILL); exit(3);
        }
        pcntl_waitpid($pid,$status);
        brendyTestAssert(pcntl_wifsignaled($status) && pcntl_wtermsig($status)===SIGKILL,'Child was not killed');
        brendyTestAssert($j->recover()==='committed','Committed crash recovery failed'); journalAssertNew($r);
    } finally { brendyTestRemoveTree($r); }
 },
 'Restore journal refuses unexpected entry without altering other paths' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->apply($p),'Apply failed');
        rename($r.'/databases',$r.'/replaced'); mkdir($r.'/databases'); file_put_contents($r.'/databases/site.php','unexpected');
        brendyTestAssert($j->recover()==='blocked' && $j->pending(),'Conflict not blocked');
        brendyTestAssert(file_get_contents($r.'/databases/site.php')==='unexpected' && file_get_contents($r.'/only-new')==='new file','Conflict mutated live tree');
    } finally { brendyTestRemoveTree($r); }
 },
 'Restore journal rejects corrupted traversal plan and symlink' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p),'Prepare failed');
        $path=$r.'/tmp/'.RestoreJournal::FILE; $p['stage']='../../other'; file_put_contents($path,json_encode($p));
        brendyTestAssert($j->recover()==='blocked','Traversal accepted'); journalAssertOld($r);
        unlink($path); symlink($r.'/only-old',$path); brendyTestAssert($j->recover()==='blocked','Symlink accepted'); journalAssertOld($r);
    } finally { brendyTestRemoveTree($r); }
 },
 'Restore journal rejects replaced staging parent and hardlinked file' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { link($r.'/only-old',$r.'/hardlink'); brendyTestAssert($j->prepare($stage)===false,'Hard link accepted'); unlink($r.'/hardlink');
        $p=$j->prepare($stage); brendyTestAssert(is_array($p),'Prepare failed');
        rename(dirname($incoming),dirname($incoming).'-old'); mkdir(dirname($incoming)); rename(dirname($incoming).'-old/sadrzaj',$incoming);
        brendyTestAssert($j->recover()==='blocked','Replaced parent accepted'); journalAssertOld($r);
    } finally { brendyTestRemoveTree($r); }
 },
 'Restore journal rejects a second transaction and uses private permissions' => static function () {
    [$r,$stage,$incoming,$j]=journalFixture();
    try { $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->prepare($stage)===false,'Second transaction accepted');
        brendyTestAssert((fileperms($r.'/tmp/'.RestoreJournal::FILE)&0777)===0600,'Journal not private');
        brendyTestAssert($j->recover()==='rolled-back','Rollback failed'); journalAssertOld($r);
    } finally { brendyTestRemoveTree($r); }
 },
];
