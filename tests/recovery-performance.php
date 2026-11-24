<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
// Synthetic, local benchmark; compare the same workload on two release trees.
function ioCounters(): array {
    $raw=@file_get_contents('/proc/self/io');$out=[];
    if (is_string($raw)) foreach (explode("\n",$raw) as $line) if (preg_match('/^([a-z_]+): (\d+)$/D',$line,$m)) $out[$m[1]]=(int)$m[2];
    return $out;
}
function elapsed(callable $fn): array {
    $a=ioCounters();$start=hrtime(true);$fn();$ms=(hrtime(true)-$start)/1e6;$b=ioCounters();
    return ['ms'=>$ms,'wchar'=>isset($a['wchar'],$b['wchar'])?$b['wchar']-$a['wchar']:null,'write_bytes'=>isset($a['write_bytes'],$b['write_bytes'])?$b['write_bytes']-$a['write_bytes']:null];
}
$r=brendyTestTempDir('brendy-recovery-bench');
try {
    mkdir($r.'/tmp',0700); $l=new RecoveryLedger($r.'/tmp');brendyTestAssert($l->initialize(),'Initialize failed');$s=$l->read();
    for ($i=1;$i<=4096;$i++) $s['history'][]=['schema'=>1,'seq'=>$i,'id'=>sprintf('%032x',$i),'stage'=>'restore-'.sprintf('%032x',$i),'result'=>'rolled-back','time'=>1700000000,'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',(string)$i)];
    brendyTestAssert($l->save($s),'Seed failed');$saves=[];$reads=[];
    for ($i=0;$i<5;$i++) {
        $s['history'][count($s['history'])-1]['time']++; // modifies one record in a full shard: use a tail below instead
        if ($i===0) {
            $s['history'][4095]['time']--; $n=4097;
            $s['history'][]=['schema'=>1,'seq'=>$n,'id'=>sprintf('%032x',$n),'stage'=>'restore-'.sprintf('%032x',$n),'result'=>'rolled-back','time'=>1700000000,'databaseVerified'=>true,'contentVerified'=>true,'marker'=>hash('sha256',(string)$n)];
        }
        $saves[]=elapsed(static function() use($l,$s){brendyTestAssert($l->save($s),'Save failed');});
        $reads[]=elapsed(static function() use($l,$s){brendyTestAssert($l->read()===$s,'Read mismatch');});
    }
    $file=$r.'/media.bin';$f=fopen($file,'wb');$chunk=random_bytes(1048576);for($i=0;$i<64;$i++) fwrite($f,$chunk);fclose($f);unset($chunk);
    $expected=hash_file('sha256',$file);$hashes=[];
    for($i=0;$i<5;$i++) $hashes[]=elapsed(static function()use($file,$expected){brendyTestAssert(Filesystem::hashFileBounded($file,'sha256',67108864,0,67108864)===$expected,'Hash mismatch');});
    echo json_encode(['version'=>BRENDY_VERSION,'php'=>PHP_VERSION,'records'=>4097,'mediaBytes'=>67108864,'save'=>$saves,'read'=>$reads,'hash'=>$hashes,'peakMemoryBytes'=>memory_get_peak_usage(true)],JSON_PRETTY_PRINT)."\n";
} finally {brendyTestRemoveTree($r);}
