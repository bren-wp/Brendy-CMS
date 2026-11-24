<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
// Run on an independent operator host/account, not as the CMS user.
// The authoritative signed file and its directory must not be CMS-writable.
if ($argc!==5 || !in_array($argv[1],['initialize','advance','advance-stdin'],true)) {
    fwrite(STDERR,"Usage: php tests/recovery-anchor-operator.php initialize|advance|advance-stdin /absolute/anchor.json /absolute/secret.hex /absolute/proposal.json-or-dash\n"); exit(2);
}
[$script,$mode,$anchor,$secretPath,$proposalPath]=$argv;
if (dirname($anchor)!==realpath(dirname($anchor)) || is_link($anchor) || realpath($secretPath)!==$secretPath || ($mode==='advance-stdin'?$proposalPath!=='-':realpath($proposalPath)!==$proposalPath) ||
    !is_array(RestoreJournal::identity($secretPath)) || (@fileperms($secretPath)&0777)!==0600 || $anchor===$secretPath || $anchor===$proposalPath) exit(2);
$lock=Filesystem::acquireLock($anchor.'.lock',LOCK_EX|LOCK_NB,0600);
if ($lock===false) exit(3);
try {
    $id=RestoreJournal::identity($anchor);
    if (($mode==='initialize' && $id!==null) || ($mode!=='initialize' && !is_array($id))) exit(4);
    $previous=$id===null?null:Filesystem::readFileBounded($anchor,4096,1);
    $secret=Filesystem::readFileBounded($secretPath,256,1);
    $proposal=$mode==='advance-stdin'?stream_get_contents(STDIN,RecoveryLedger::LIMIT+1):Filesystem::readFileBounded($proposalPath,RecoveryLedger::LIMIT,1);
    if ($previous===false || !is_string($secret) || !is_string($proposal) || strlen($proposal)>RecoveryLedger::LIMIT) exit(4);
    $value=json_decode($proposal,true,64,JSON_THROW_ON_ERROR);
    $signed=is_array($value)?RecoveryLedger::signAnchor($value,$previous,trim($secret)):false;
    if (!is_string($signed) || RestoreJournal::identity($anchor)!==$id || !Filesystem::atomicWrite($anchor,$signed,0644)) exit(5);
    echo "Signed checkpoint published. Keep independent backups and serve only the latest checkpoint.\n";
} catch (Throwable $e) { fwrite(STDERR,"Checkpoint publication refused.\n"); exit(5); }
finally { Filesystem::releaseLock($lock); }
