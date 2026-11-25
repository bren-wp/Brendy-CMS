<?php
if (PHP_SAPI!=='cli') exit;
define('BRENDY',true);
require dirname(__DIR__).'/jezgra/pomocnici/recovery-uuid-spool.class.php';
$s=new RecoveryUuidSpool(); $start=hrtime(true);
try {
    for ($i=1;$i<=1000000;$i++) if (!$s->add(sprintf('%032x',$i))) throw new RuntimeException('write failed');
    if (!$s->verify()) throw new RuntimeException('unique scan failed');
    if (!$s->add(sprintf('%032x',1)) || $s->verify()) throw new RuntimeException('duplicate missed');
    echo json_encode(['scope'=>'UUID spool only, not active ledger','records'=>1000000,'memoryLimit'=>ini_get('memory_limit'),'peakBytes'=>memory_get_peak_usage(true),'ms'=>(hrtime(true)-$start)/1e6]).PHP_EOL;
} finally { $s->close(); }
