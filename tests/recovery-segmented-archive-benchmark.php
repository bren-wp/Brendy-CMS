<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php'; require __DIR__.'/recovery-segmented-archive.class.php';
$n=(int)($argv[1]??100000); if ($n<1 || $n>RecoverySegmentedArchive::MAX_RECORDS) exit(2);
$dir=brendyTestTempDir('segarchive-bench'); $f=tmpfile();
try {
    fwrite($f,RecoveryLedger::encode(['schema'=>2,'installation'=>str_repeat('a',64)])."\n");
    for ($i=1;$i<=$n;$i++) { $id=sprintf('%032x',$i); $r=['schema'=>1,'seq'=>$i,'id'=>$id,'stage'=>'restore-'.$id,'result'=>'rolled-back','time'=>1700000000,'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',$id)]; $line=RecoveryLedger::encode($r)."\n"; if (fwrite($f,$line)!==strlen($line)) throw new RuntimeException('spool write'); }
    fwrite($f,RecoveryLedger::encode(['end'=>$n])."\n"); rewind($f);
    $pair=sodium_crypto_sign_keypair(); $key=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
    memory_reset_peak_usage(); $base=memory_get_usage(); $t=hrtime(true); $ok=RecoverySegmentedArchive::publish($dir,$f,$key,true); $publish=['ok'=>$ok,'ms'=>(hrtime(true)-$t)/1e6,'extra_peak_bytes'=>memory_get_peak_usage()-$base];
    memory_reset_peak_usage(); $base=memory_get_usage(); $t=hrtime(true); $m=RecoverySegmentedArchive::verify($dir,$pub); $verify=['ok'=>is_array($m)&&$m['seq']===$n,'ms'=>(hrtime(true)-$t)/1e6,'extra_peak_bytes'=>memory_get_peak_usage()-$base];
    echo json_encode(['records'=>$n,'segments'=>is_array($m)?count($m['segments']):null,'publish'=>$publish,'verify'=>$verify,'proposal_bytes'=>fstat($f)['size']],JSON_PRETTY_PRINT)."\n";
    $status=$publish['ok']&&$verify['ok']?0:1;
} finally { fclose($f); brendyTestRemoveTree($dir); }
exit($status);
