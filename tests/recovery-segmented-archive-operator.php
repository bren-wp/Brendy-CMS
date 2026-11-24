<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php'; require __DIR__.'/recovery-segmented-archive.class.php';
$mode=$argv[1]??''; $dir=$argv[2]??'';
if (in_array($mode,['initialize','advance'],true) && ($argc===5 || $argc===6)) {
    $secret=$argv[3]; $source=$argv[4];
    if (realpath($secret)!==$secret || (@fileperms($secret)&0777)!==0600 || !is_file($secret) || is_link($secret)) exit(2);
    $key=Filesystem::readFileBounded($secret,256,1); $cp=$argc===6?Filesystem::readFileBounded($argv[5],4096,1):null;
    $in=$source==='-'?STDIN:@fopen($source,'rb');
    exit(is_string($key) && $cp!==false && is_resource($in) && RecoverySegmentedArchive::publish($dir,$in,trim($key),$mode==='initialize',$cp)?0:4);
}
if (in_array($mode,['checkpoint-initialize','checkpoint-advance'],true) && $argc===5) {
    $secret=$argv[3]; $anchor=$argv[4];
    if (realpath($secret)!==$secret || (@fileperms($secret)&0777)!==0600 || !is_file($secret) || is_link($secret) || $anchor===$secret) exit(2);
    $key=Filesystem::readFileBounded($secret,256,1);
    exit(is_string($key) && RecoverySegmentedArchive::publishCheckpoint($dir,$anchor,trim($key),$mode==='checkpoint-initialize')?0:4);
}
if ($mode==='retention-plan' && $argc===5) {
    $cp=Filesystem::readFileBounded($argv[4],4096,1); if ($cp===false) exit(4);
    $plan=RecoverySegmentedArchive::retentionPlan($dir,$argv[3],$cp); if ($plan===false) exit(4);
    echo RecoveryLedger::encode($plan+['approval'=>RecoveryLedger::digest($plan)])."\n"; exit(0);
}
if (($mode==='gc-start' && $argc===6) || ($mode==='gc-resume' && $argc===5)) {
    $secret=$argv[3];
    if (realpath($secret)!==$secret || (@fileperms($secret)&0777)!==0600 || !is_file($secret) || is_link($secret)) exit(2);
    $key=Filesystem::readFileBounded($secret,256,1); $cp=Filesystem::readFileBounded($argv[4],4096,1);
    exit(is_string($key) && is_string($cp) && RecoverySegmentedArchive::garbageCollect($dir,trim($key),$cp,$mode==='gc-start'?$argv[5]:null)?0:4);
}
if ($mode==='verify' && ($argc===4 || $argc===5)) {
    $cp=$argc===5?Filesystem::readFileBounded($argv[4],4096,1):null; if ($cp===false) exit(4);
    $m=RecoverySegmentedArchive::verify($dir,$argv[3],$cp); if ($m===false) exit(4);
    echo RecoveryLedger::encode($m)."\n"; exit(0);
}
fwrite(STDERR,"Usage: checkpoint-initialize|checkpoint-advance /private/archive /private/secret.hex /private/checkpoint-dir/anchor.json; initialize|advance /private/archive /private/secret.hex proposal.ndjson-or-dash [checkpoint]; verify /private/archive public-key-hex [checkpoint]; retention-plan /private/archive public-key-hex checkpoint; gc-start /private/archive /private/secret.hex checkpoint approval-digest; gc-resume /private/archive /private/secret.hex checkpoint\n"); exit(2);
