<?php
if (PHP_SAPI!=='cli' || !defined('BRENDY')) { http_response_code(404); exit; }
require_once __DIR__.'/recovery-anchor-freshness.class.php';
/** Verifier-side single-use state. The private directory must be protected
 * independently from the CMS. No reset/reissue of a missing challenge. */
final class RecoveryNonceStore
{
    private static function hex($s,int $n): bool { return is_string($s) && preg_match('/^[a-f0-9]{'.$n.'}$/D',$s)===1; }
    private static function lock(string $dir) {
        if (realpath($dir)!==$dir || !is_dir($dir) || is_link($dir) || (@fileperms($dir)&0777)!==0700) return false;
        return Filesystem::acquireLock($dir.DS.'nonce.lock',LOCK_EX|LOCK_NB,0600);
    }
    private static function path(string $dir,string $nonce): string { return $dir.DS.'nonce-'.$nonce.'.json'; }
    public static function issue(string $dir,string $public,array $expected,int $now,int $ttl=60) {
        if (!self::hex($public,64) || !self::hex($expected['installation']??null,64) || !self::hex($expected['prefix']??null,64) || !is_int($expected['seq']??null) || $expected['seq']<0 || $now<=0 || $ttl<1 || $ttl>300) return false;
        $lock=self::lock($dir); if ($lock===false) return false;
        try {
            $nonce=bin2hex(random_bytes(32)); $path=self::path($dir,$nonce);
            if (RestoreJournal::identity($path)!==null) return false;
            $record=['schema'=>1,'nonce'=>$nonce,'public'=>$public,'installation'=>$expected['installation'],'seq'=>$expected['seq'],'prefix'=>$expected['prefix'],'issued'=>$now,'expires'=>$now+$ttl,'used'=>null];
            return Filesystem::atomicWrite($path,RecoveryLedger::encode($record),0600)?$nonce:false;
        } finally { Filesystem::releaseLock($lock); }
    }
    public static function consume(string $dir,string $nonce,string $response,int $now) {
        if (!self::hex($nonce,64) || $now<=0 || strlen($response)>4096) return false;
        $lock=self::lock($dir); if ($lock===false) return false;
        try {
            $path=self::path($dir,$nonce); $id=RestoreJournal::identity($path);
            if (!is_array($id) || $id[2]!==0100000 || (@fileperms($path)&0777)!==0600) return false;
            $raw=Filesystem::readFileBounded($path,4096,1); if (!is_string($raw)) return false;
            $r=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($r) || array_keys($r)!==['schema','nonce','public','installation','seq','prefix','issued','expires','used'] || $r['schema']!==1 || $r['nonce']!==$nonce || $r['used']!==null || !self::hex($r['public'],64) || !self::hex($r['installation'],64) || !self::hex($r['prefix'],64) || !is_int($r['seq']) || $r['seq']<0 || !is_int($r['issued']) || !is_int($r['expires']) || $r['expires']<=$r['issued'] || $r['expires']-$r['issued']>300 || $now<$r['issued'] || $now>$r['expires']) return false;
            $cp=RecoveryAnchorFreshness::verify($response,$r['public'],$nonce,$r['installation'],$r['seq'],$now,$r['expires']-$r['issued'],$r['prefix']);
            if ($cp===false || RestoreJournal::identity($path)!==$id || Filesystem::readFileBounded($path,4096,1)!==$raw) return false;
            // Commit consumption before returning success. A killed process
            // after this write must not accept the same signed response again.
            $r['used']=$now;
            return Filesystem::atomicWrite($path,RecoveryLedger::encode($r),0600)?$cp:false;
        } catch (Throwable $e) { return false; }
        finally { Filesystem::releaseLock($lock); }
    }
}
