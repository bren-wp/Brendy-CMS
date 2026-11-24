<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

$files = array('filesystem-smoke.php', 'database-smoke.php', 'release-smoke.php', 'scanner-smoke.php', 'auth-smoke.php', 'seo-security-smoke.php', 'extension-regression.php', 'hardening-smoke.php', 'bootstrap5-smoke.php', 'cloud-smoke.php', 'storage-seo-smoke.php', 'modernization-smoke.php', 'transport-seo-smoke.php', 'next-hardening-smoke.php', 'latest-hardening-smoke.php', 'upgrade-dependencies-smoke.php', 'ownership-regression.php', 'prism-filesystem-smoke.php', 'path-boundary-smoke.php', 'directory-identity-smoke.php', 'operation-plan-smoke.php', 'restore-journal-smoke.php', 'recovery-management-smoke.php', 'authenticated-recovery-smoke.php', 'recovery-redundancy-smoke.php', 'recovery-anchor-gc-smoke.php', 'recovery-streaming-smoke.php', 'recovery-mutation-smoke.php', 'recovery-archive-smoke.php', 'recovery-segmented-archive-smoke.php', 'recovery-archive-gc-proof-smoke.php', 'recovery-anchor-freshness-smoke.php', 'recovery-hierarchy-smoke.php', 'recovery-uuid-integration-smoke.php', 'recovery-nonce-auth-smoke.php', 'recovery-page-retirement-smoke.php');
$tests = array();
foreach ($files as $file) {
    $suite = require __DIR__ . DS . $file;
    if (!is_array($suite)) throw new RuntimeException('Invalid test suite: ' . $file);
    $tests = array_merge($tests, $suite);
}

$passed = 0;
$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        $passed++;
        echo "[PASS] {$name}" . PHP_EOL;
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "[FAIL] {$name}: " . $e->getMessage() . PHP_EOL);
    }
}
echo PHP_EOL . 'Result: ' . $passed . ' passed, ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
