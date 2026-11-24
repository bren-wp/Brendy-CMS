<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) { fwrite(STDERR,"Requires pcntl on a Unix test host.\n"); exit(2); }
$dir=brendyTestTempDir('brendy-concurrent'); $children=[]; $failed=false;
try {
    $path=$dir.'/payload'; $counter=$dir.'/counter'; $lock=$dir.'/operation.lock';
    $payloads=array_map(static fn($i)=>str_repeat(chr(65+$i),65536),range(0,3));
    brendyTestAssert(Filesystem::atomicWrite($path,$payloads[0],0600) && Filesystem::atomicWrite($counter,'0',0600),'Initial state failed');
    for($i=0;$i<4;$i++) {
        $pid=pcntl_fork();
        if ($pid===-1) throw new RuntimeException('Fork failed');
        if ($pid===0) {
            try {
                for($j=0;$j<20;$j++) {
                    $h=Filesystem::acquireLock($lock);
                    if (!is_resource($h)) throw new RuntimeException('Lock failed');
                    try {
                        $n=Filesystem::readFileBounded($counter,16,1);
                        if (!is_string($n)||!ctype_digit($n)||!Filesystem::atomicWrite($counter,(string)((int)$n+1),0600)||!Filesystem::atomicWrite($path,$payloads[$i],0600)) throw new RuntimeException('Write failed');
                    } finally { Filesystem::releaseLock($h); }
                    // Unlocked reads may fail closed during replacement, but may not return torn bytes.
                    $read=Filesystem::readFileBounded($path,65536,65536);
                    if ($read!==false&&!in_array($read,$payloads,true)) throw new RuntimeException('Torn payload');
                }
                exit(0);
            } catch(Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
        }
        $children[]=$pid;
    }
    foreach($children as $pid) { pcntl_waitpid($pid,$status); if (!pcntl_wifexited($status)||pcntl_wexitstatus($status)!==0) $failed=true; }
    $children=[];
    brendyTestAssert(!$failed,'One or more concurrent writers failed');
    brendyTestAssert(Filesystem::readFileBounded($counter,16,1)==='80','Lost update under cooperative lock');
    echo "[PASS] 80 cooperative locked updates, four child processes\n";
    brendyTestAssert(in_array(Filesystem::readFileBounded($path,65536,65536),$payloads,true),'Invalid final payload');
    echo "[PASS] Concurrent reader/writer snapshots preserve complete payloads\n";
} catch(Throwable $e) { $failed=true; fwrite(STDERR,$e->getMessage().PHP_EOL); }
finally { foreach($children as $pid) pcntl_waitpid($pid,$status); brendyTestRemoveTree($dir); }
exit($failed?1:0);
