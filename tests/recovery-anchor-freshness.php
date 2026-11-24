<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php'; require __DIR__.'/recovery-nonce-store.class.php';
$mode=$argv[1]??'';
if ($mode==='challenge' && $argc===2) { echo bin2hex(random_bytes(32))."\n"; exit(0); }
if ($mode==='initialize' && $argc===3) exit(RecoveryNonceStore::initialize($argv[2])?0:4);
if ($mode==='prune' && $argc===3) {
    $count=RecoveryNonceStore::prune($argv[2],time());
    if ($count===false) exit(4); echo $count."\n"; exit(0);
}
if ($mode==='issue' && $argc===5) {
    [$script,$mode,$dir,$public,$expectedPath]=$argv;
    // The expected checkpoint is the verifier's independently pinned exact head.
    if (realpath($expectedPath)!==$expectedPath || is_link($expectedPath)) exit(2);
    $raw=Filesystem::readFileBounded($expectedPath,4096,1); $cp=is_string($raw)?RecoveryLedger::verifyAnchor($raw,$public):false;
    $nonce=$cp!==false?RecoveryNonceStore::issue($dir,$public,$cp,time()):false;
    if ($nonce===false) exit(4); echo $nonce."\n"; exit(0);
}
if ($mode==='consume' && $argc===4) {
    $raw=stream_get_contents(STDIN,4097); $cp=is_string($raw)?RecoveryNonceStore::consume($argv[2],$argv[3],$raw,time()):false;
    if ($cp===false) exit(4); echo RecoveryLedger::encode($cp)."\n"; exit(0);
}
if ($mode==='respond' && $argc===5) {
    [$script,$mode,$anchor,$secretPath,$nonce]=$argv;
    if (realpath($anchor)!==$anchor || is_link($anchor) || realpath($secretPath)!==$secretPath || is_link($secretPath) || (@fileperms($secretPath)&0777)!==0600) exit(2);
    $lock=Filesystem::acquireLock($anchor.'.lock',LOCK_EX|LOCK_NB,0600); if ($lock===false) exit(3);
    try { $raw=Filesystem::readFileBounded($anchor,4096,1); $key=Filesystem::readFileBounded($secretPath,256,1); $response=is_string($raw)&&is_string($key)?RecoveryAnchorFreshness::respond($raw,$nonce,trim($key),time()):false; }
    finally { Filesystem::releaseLock($lock); }
    if ($response===false) exit(4); echo $response."\n"; exit(0);
}
if ($mode==='verify' && ($argc===6 || $argc===7)) {
    [$script,$mode,$public,$nonce,$installation,$seq]=$argv;
    if (!ctype_digit($seq) || (string)(int)$seq!==$seq) exit(2);
    $raw=stream_get_contents(STDIN,4097); $cp=is_string($raw)?RecoveryAnchorFreshness::verify($raw,$public,$nonce,$installation,(int)$seq,time(),60,$argv[6]??null):false;
    if ($cp===false) exit(4); echo RecoveryLedger::encode($cp)."\n"; exit(0);
}
fwrite(STDERR,"Usage: initialize /private/nonce-dir | prune /private/nonce-dir | challenge | issue /private/nonce-dir public-key /absolute/expected-signed-checkpoint.json | consume /private/nonce-dir nonce < response.json | respond /absolute/anchor.json /absolute/secret.hex nonce | verify public-key nonce installation minimum-seq [exact-prefix] < response.json\n"); exit(2);
