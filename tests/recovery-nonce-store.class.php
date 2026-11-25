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
    private const KEY = 'nonce-auth.key';
    private static function key(string $dir) {
        $path=$dir.DS.self::KEY;
        $id=RestoreJournal::identity($path);
        if (!is_array($id) || $id[2]!==0100000 || (@fileperms($path)&0777)!==0600) return false;
        $raw=Filesystem::readFileBounded($path,64,1);
        return self::hex($raw,64) && RestoreJournal::identity($path)===$id?hex2bin($raw):false;
    }
    // Explicit initialization; never replace or silently regenerate a missing key.
    public static function initialize(string $dir): bool {
        $lock=self::lock($dir); if ($lock===false) return false;
        try {
            $names=scandir($dir); if (!is_array($names) || array_diff($names,['.','..','nonce.lock'])) return false;
            return Filesystem::atomicWrite($dir.DS.self::KEY,bin2hex(random_bytes(32)),0600);
        } finally { Filesystem::releaseLock($lock); }
    }
    private static function seal(array $r,string $key): string {
        $payload=RecoveryLedger::encode($r);
        return RecoveryLedger::encode(['payload'=>$payload,'mac'=>hash_hmac('sha256',"brendy-nonce-v1\0".$payload,$key)]);
    }
    private static function open(string $raw,string $key) {
        $env=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($env) || array_keys($env)!==['payload','mac'] || !is_string($env['payload']) || !self::hex($env['mac'],64) || !hash_equals(hash_hmac('sha256',"brendy-nonce-v1\0".$env['payload'],$key),$env['mac'])) return false;
        return json_decode($env['payload'],true,16,JSON_THROW_ON_ERROR);
    }
    public static function issue(string $dir,string $public,array $expected,int $now,int $ttl=60) {
        if (!self::hex($public,64) || !self::hex($expected['installation']??null,64) || !self::hex($expected['prefix']??null,64) || !is_int($expected['seq']??null) || $expected['seq']<0 || $now<=0 || $ttl<1 || $ttl>300) return false;
        $lock=self::lock($dir); if ($lock===false) return false;
        try {
            $key=self::key($dir); if ($key===false) return false;
            $nonce=bin2hex(random_bytes(32)); $path=self::path($dir,$nonce);
            if (RestoreJournal::identity($path)!==null) return false;
            $record=['schema'=>1,'nonce'=>$nonce,'public'=>$public,'installation'=>$expected['installation'],'seq'=>$expected['seq'],'prefix'=>$expected['prefix'],'issued'=>$now,'expires'=>$now+$ttl,'used'=>null];
            return Filesystem::atomicWrite($path,self::seal($record,$key),0600)?$nonce:false;
        } finally { Filesystem::releaseLock($lock); }
    }
    public static function consume(string $dir,string $nonce,string $response,int $now) {
        if (!self::hex($nonce,64) || $now<=0 || strlen($response)>4096) return false;
        $lock=self::lock($dir); if ($lock===false) return false;
        try {
            $path=self::path($dir,$nonce); $id=RestoreJournal::identity($path);
            if (!is_array($id) || $id[2]!==0100000 || (@fileperms($path)&0777)!==0600) return false;
            $raw=Filesystem::readFileBounded($path,4096,1); if (!is_string($raw)) return false;
            $key=self::key($dir); if ($key===false) return false;
            $r=self::open($raw,$key);
            if (!is_array($r) || array_keys($r)!==['schema','nonce','public','installation','seq','prefix','issued','expires','used'] || $r['schema']!==1 || $r['nonce']!==$nonce || $r['used']!==null || !self::hex($r['public'],64) || !self::hex($r['installation'],64) || !self::hex($r['prefix'],64) || !is_int($r['seq']) || $r['seq']<0 || !is_int($r['issued']) || !is_int($r['expires']) || $r['expires']<=$r['issued'] || $r['expires']-$r['issued']>300 || $now<$r['issued'] || $now>$r['expires']) return false;
            $cp=RecoveryAnchorFreshness::verify($response,$r['public'],$nonce,$r['installation'],$r['seq'],$now,$r['expires']-$r['issued'],$r['prefix']);
            if ($cp===false || RestoreJournal::identity($path)!==$id || Filesystem::readFileBounded($path,4096,1)!==$raw) return false;
            // Commit consumption before returning success. A killed process
            // after this write must not accept the same signed response again.
            $r['used']=$now;
            return Filesystem::atomicWrite($path,self::seal($r,$key),0600)?$cp:false;
        } catch (Throwable $e) { return false; }
        finally { Filesystem::releaseLock($lock); }
    }
    // Explicit maintenance operation. Only authenticated expired records may be
    // removed, after a conservative 24-hour grace interval. Missing = refused.
    public static function prune(string $dir,int $now,int $limit=100) {
        if ($now<86401 || $limit<1 || $limit>1000) return false;
        $lock=self::lock($dir); if ($lock===false) return false;
        try {
            $key=self::key($dir); if ($key===false) return false;
            $candidates=[]; $scanned=0;
            foreach (new DirectoryIterator($dir) as $entry) {
                if ($entry->isDot()) continue;
                if (++$scanned>10000) return false;
                $name=$entry->getFilename();
                if (!preg_match('/^nonce-([a-f0-9]{64})\.json$/D',$name,$m)) continue;
                $path=self::path($dir,$m[1]); $id=RestoreJournal::identity($path);
                if (!is_array($id) || $id[2]!==0100000 || (@fileperms($path)&0777)!==0600) return false;
                $raw=Filesystem::readFileBounded($path,4096,1); if (!is_string($raw)) return false;
                $r=self::open($raw,$key);
                if (!is_array($r) || ($r['nonce']??null)!==$m[1] || ($r['schema']??null)!==1 || !is_int($r['issued']??null) || !is_int($r['expires']??null) || $r['expires']<=$r['issued'] || $r['expires']-$r['issued']>300) return false;
                if ($r['expires']<=$now-86400 && count($candidates)<$limit) $candidates[]=[$path,$id,$raw];
            }
            foreach ($candidates as [$path,$id,$raw]) {
                if (RestoreJournal::identity($path)!==$id || Filesystem::readFileBounded($path,4096,1)!==$raw || !@unlink($path)) return false;
            }
            return count($candidates);
        } catch (Throwable $e) { return false; }
        finally { Filesystem::releaseLock($lock); }
    }
}
