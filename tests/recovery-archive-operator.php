<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
require __DIR__.'/recovery-archive.class.php';
// Never run inside the webroot or with the CMS service account.
$mode=$argv[1]??''; $dir=$argv[2]??'';
if (in_array($mode,['initialize','advance'],true) && $argc===5) {
    $secret=$argv[3]; $source=$argv[4];
    if (realpath($secret)!==$secret || (@fileperms($secret)&0777)!==0600 || !is_file($secret) || is_link($secret)) exit(2);
    $key=Filesystem::readFileBounded($secret,256,1);
    $data=$source==='-'?stream_get_contents(STDIN,RecoveryLedger::LIMIT+1):Filesystem::readFileBounded($source,RecoveryLedger::LIMIT,1);
    exit(is_string($key) && is_string($data) && RecoveryArchive::publish($dir,$data,trim($key),$mode==='initialize')?0:4);
}
if ($mode==='verify' && ($argc===4 || $argc===5)) {
    $cp=$argc===5?Filesystem::readFileBounded($argv[4],4096,1):null;
    if ($cp===false) exit(4);
    $m=RecoveryArchive::verify($dir,$argv[3],$cp); if ($m===false) exit(4);
    echo RecoveryLedger::encode($m)."\n"; exit(0);
}
fwrite(STDERR,"Usage: initialize|advance /absolute/private/archive /absolute/secret.hex /absolute/proposal.json-or-dash; verify /absolute/private/archive public-key-hex [checkpoint-file]\n"); exit(2);
