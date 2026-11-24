<?php
if (PHP_SAPI!=='cli' || !defined('BRENDY')) { http_response_code(404); exit; }
/** Operator-side challenge/response. A fresh random nonce must be generated and
 * consumed once by the requesting operator. This is not a network transport. */
final class RecoveryAnchorFreshness
{
    private static function hex($v,int $length): bool { return is_string($v) && preg_match('/^[a-f0-9]{'.$length.'}$/D',$v)===1; }
    public static function respond(string $checkpoint,string $nonce,string $secret,int $now) {
        if (!self::hex($nonce,64) || !self::hex($secret,128) || $now<=0 || !function_exists('sodium_crypto_sign_detached')) return false;
        try {
            $key=hex2bin($secret); $public=bin2hex(sodium_crypto_sign_publickey_from_secretkey($key));
            $cp=RecoveryLedger::verifyAnchor($checkpoint,$public); if ($cp===false) return false;
            $data=['schema'=>1,'nonce'=>$nonce,'issuedAt'=>$now,'checkpoint'=>$cp];
            return RecoveryLedger::encode(['response'=>$data,'signature'=>bin2hex(sodium_crypto_sign_detached("brendy-anchor-freshness-v1\0".RecoveryLedger::encode($data),$key))]);
        } catch (Throwable $e) { return false; }
    }
    public static function verify(string $raw,string $public,string $nonce,string $installation,int $minimumSeq,int $now,int $maxAge=60,?string $expectedPrefix=null) {
        if (strlen($raw)>4096 || !self::hex($public,64) || !self::hex($nonce,64) || !self::hex($installation,64) || $minimumSeq<0 || $now<=0 || $maxAge<1 || $maxAge>300 || ($expectedPrefix!==null && !self::hex($expectedPrefix,64)) || !function_exists('sodium_crypto_sign_verify_detached')) return false;
        try {
            $env=json_decode($raw,true,16,JSON_THROW_ON_ERROR); $data=$env['response']??null; $sig=$env['signature']??null;
            if (!is_array($data) || array_keys($data)!==['schema','nonce','issuedAt','checkpoint'] || $data['schema']!==1 || !self::hex($sig,128) || !is_string($data['nonce']) || !hash_equals($nonce,$data['nonce']) || !is_int($data['issuedAt']) || $data['issuedAt']<$now-$maxAge || $data['issuedAt']>$now+5) return false;
            $cp=$data['checkpoint'];
            if (!is_array($cp) || array_keys($cp)!==['schema','installation','seq','prefix'] || $cp['schema']!==1 || $cp['installation']!==$installation || !is_int($cp['seq']) || $cp['seq']<$minimumSeq || !self::hex($cp['prefix'],64) || ($expectedPrefix!==null && ($cp['seq']!==$minimumSeq || !hash_equals($expectedPrefix,$cp['prefix'])))) return false;
            return sodium_crypto_sign_verify_detached(hex2bin($sig),"brendy-anchor-freshness-v1\0".RecoveryLedger::encode($data),hex2bin($public))?$cp:false;
        } catch (Throwable $e) { return false; }
    }
}
