<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BRENDY', true);
require dirname(__DIR__) . '/jezgra/pomocnici/recovery-uuid-spool.class.php';
$spool = new RecoveryUuidSpool();
try {
    if ($spool->add('invalid')) throw new RuntimeException('Malformed UUID accepted');
    for ($i = 1; $i <= 100000; $i++) {
        if (!$spool->add(sprintf('%032x', $i))) throw new RuntimeException('Spool write failed');
    }
    if (!$spool->verify()) throw new RuntimeException('Unique UUID scan failed');
    if (!$spool->add(sprintf('%032x', 1)) || $spool->verify()) throw new RuntimeException('Duplicate accepted');
    echo "[PASS] 100000 unique UUIDs and distant duplicate detection\n";
} finally {
    $spool->close();
}
