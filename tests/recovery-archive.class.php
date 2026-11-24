<?php
if (PHP_SAPI!=='cli' || !defined('BRENDY')) { http_response_code(404); exit; }
/** Independent operator only. Archive data is immutable; manifest is the commit point.
 * Directory must be private, external to CMS, and protected from rollback. */
final class RecoveryArchive
{
    const MANIFEST='archive-current.json';
    private static function read(string $path,int $limit)
    {
        $id=RestoreJournal::identity($path);
        if (!is_array($id) || $id[2]!==0100000 || (@fileperms($path)&0777)!==0600) return false;
        $raw=Filesystem::readFileBounded($path,$limit,1);
        return is_string($raw) && RestoreJournal::identity($path)===$id?$raw:false;
    }
    private static function directory(string $dir): bool
    { return realpath($dir)===$dir && !is_link($dir) && is_dir($dir) && (@fileperms($dir)&0777)===0700; }
    private static function proposal(string $raw)
    {
        try { $p=json_decode($raw,true,64,JSON_THROW_ON_ERROR); } catch (Throwable $e) { return false; }
        return is_array($p) && RecoveryLedger::validArchiveProposal($p)?$p:false;
    }
    private static function manifest(string $raw,string $public)
    {
        if (!function_exists('sodium_crypto_sign_verify_detached') || !preg_match('/^[a-f0-9]{64}$/D',$public)) return false;
        try {
            $v=json_decode($raw,true,16,JSON_THROW_ON_ERROR); $m=$v['manifest']??null; $sig=$v['signature']??null;
            if (!is_array($m) || array_keys($m)!==['schema','installation','seq','prefix','sha256','bytes'] || $m['schema']!==1 || !is_int($m['seq']) || $m['seq']<0 || $m['seq']>RecoveryLedger::MAX_HISTORY || !is_int($m['bytes']) || $m['bytes']<1 || $m['bytes']>RecoveryLedger::LIMIT || !is_string($sig) || !preg_match('/^[a-f0-9]{128}$/D',$sig)) return false;
            foreach (['installation','prefix','sha256'] as $k) if (!is_string($m[$k]) || !preg_match('/^[a-f0-9]{64}$/D',$m[$k])) return false;
            return sodium_crypto_sign_verify_detached(hex2bin($sig),"brendy-archive-v1\0".RecoveryLedger::encode($m),hex2bin($public))?$m:false;
        } catch (Throwable $e) { return false; }
    }
    private static function blob(string $dir,string $hash): string { return $dir.DS.'archive-'.$hash.'.json'; }
    /** Optional independently retained latest checkpoint prevents accepting an older, valid manifest. */
    public static function verify(string $dir,string $public,?string $checkpoint=null)
    {
        if (!self::directory($dir)) return false;
        $raw=self::read($dir.DS.self::MANIFEST,4096); $m=is_string($raw)?self::manifest($raw,$public):false;
        if ($m===false) return false;
        $data=self::read(self::blob($dir,$m['sha256']),RecoveryLedger::LIMIT);
        if (!is_string($data) || strlen($data)!==$m['bytes'] || !hash_equals($m['sha256'],hash('sha256',$data))) return false;
        $p=self::proposal($data);
        if ($p===false || $p['installation']!==$m['installation'] || count($p['history'])!==$m['seq'] || !hash_equals($m['prefix'],RecoveryLedger::historyPrefix($p['history'],$m['seq']))) return false;
        if ($checkpoint!==null) {
            $cp=RecoveryLedger::verifyAnchor($checkpoint,$public);
            if ($cp===false || $cp['installation']!==$m['installation'] || $cp['seq']>$m['seq'] || !hash_equals($cp['prefix'],RecoveryLedger::historyPrefix($p['history'],$cp['seq']))) return false;
        }
        return self::read($dir.DS.self::MANIFEST,4096)===$raw?$m:false;
    }
    public static function publish(string $dir,string $data,string $secret,bool $initialize): bool
    {
        if (!self::directory($dir) || strlen($data)>RecoveryLedger::LIMIT || !function_exists('sodium_crypto_sign_detached') || !preg_match('/^[a-f0-9]{128}$/D',$secret)) return false;
        $lock=Filesystem::acquireLock($dir.DS.'archive.lock',LOCK_EX|LOCK_NB,0600); if ($lock===false) return false;
        try {
            $key=hex2bin($secret); $public=bin2hex(sodium_crypto_sign_publickey_from_secretkey($key)); $p=self::proposal($data);
            if ($p===false) return false;
            $path=$dir.DS.self::MANIFEST; $id=RestoreJournal::identity($path);
            if ($initialize!==($id===null)) return false;
            // An explicit initialize cannot silently reset a directory containing archive evidence.
            if ($initialize) foreach (new FilesystemIterator($dir,FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry->getFilename()!=='archive.lock' && $entry->getFilename()!=='archive-'.hash('sha256',$data).'.json' && !str_starts_with($entry->getFilename(),'.brendy-write-')) return false;
            }
            $old=$initialize?null:self::verify($dir,$public);
            $seq=count($p['history']);
            if ($old===false || ($old!==null && ($old['installation']!==$p['installation'] || $old['seq']>$seq || !hash_equals($old['prefix'],RecoveryLedger::historyPrefix($p['history'],$old['seq']))))) return false;
            $m=['schema'=>1,'installation'=>$p['installation'],'seq'=>$seq,'prefix'=>RecoveryLedger::historyPrefix($p['history'],$seq),'sha256'=>hash('sha256',$data),'bytes'=>strlen($data)];
            $blob=self::blob($dir,$m['sha256']);
            if (RestoreJournal::identity($blob)===null && !Filesystem::atomicWrite($blob,$data,0600)) return false;
            if (self::read($blob,RecoveryLedger::LIMIT)!==$data) return false;
            $signed=RecoveryLedger::encode(['manifest'=>$m,'signature'=>bin2hex(sodium_crypto_sign_detached("brendy-archive-v1\0".RecoveryLedger::encode($m),$key))]);
            if (self::manifest($signed,$public)!==$m || RestoreJournal::identity($path)!==$id) return false;
            return Filesystem::atomicWrite($path,$signed,0600) && self::verify($dir,$public)===$m;
        } catch (Throwable $e) { return false; }
        finally { Filesystem::releaseLock($lock); }
    }
}
