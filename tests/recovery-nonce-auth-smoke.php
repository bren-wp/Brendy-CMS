<?php
if (PHP_SAPI!=='cli') exit;
return [
 'Nonce HMAC rejects tampered consumed state and missing verifier key' => static function () {
    $dir=brendyTestTempDir('nonce-auth');
    try {
        $pair=sodium_crypto_sign_keypair(); $sk=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
        $raw=RecoveryLedger::signAnchor(['installation'=>str_repeat('a',64),'history'=>[]],null,$sk);
        $cp=RecoveryLedger::verifyAnchor($raw,$pub); $now=1700000000;
        brendyTestAssert(RecoveryNonceStore::initialize($dir),'init');
        $nonce=RecoveryNonceStore::issue($dir,$pub,$cp,$now);
        $res=RecoveryAnchorFreshness::respond($raw,$nonce,$sk,$now);
        brendyTestAssert(RecoveryNonceStore::consume($dir,$nonce,$res,$now+1)===$cp,'consume');
        $path=$dir.'/nonce-'.$nonce.'.json'; $envelope=json_decode(file_get_contents($path),true);
        $data=json_decode($envelope['payload'],true); $data['used']=null; $envelope['payload']=RecoveryLedger::encode($data);
        Filesystem::atomicWrite($path,RecoveryLedger::encode($envelope),0600);
        brendyTestAssert(RecoveryNonceStore::consume($dir,$nonce,$res,$now+2)===false && RecoveryNonceStore::prune($dir,$now+90000)===false,'tamper');
        unlink($dir.'/nonce-auth.key');
        brendyTestAssert(!RecoveryNonceStore::initialize($dir) && RecoveryNonceStore::issue($dir,$pub,$cp,$now)===false,'missing key reset');
    } finally { brendyTestRemoveTree($dir); }
 },
 'Nonce retention removes only expired authenticated records and rejects replay' => static function () {
    $dir=brendyTestTempDir('nonce-retention');
    try {
        $pair=sodium_crypto_sign_keypair(); $sk=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
        $raw=RecoveryLedger::signAnchor(['installation'=>str_repeat('b',64),'history'=>[]],null,$sk);
        $cp=RecoveryLedger::verifyAnchor($raw,$pub); $now=1700000000;
        brendyTestAssert(RecoveryNonceStore::initialize($dir),'init');
        $old=RecoveryNonceStore::issue($dir,$pub,$cp,$now); $fresh=RecoveryNonceStore::issue($dir,$pub,$cp,$now+90000);
        $res=RecoveryAnchorFreshness::respond($raw,$old,$sk,$now);
        brendyTestAssert(RecoveryNonceStore::prune($dir,$now+60)===0 && RecoveryNonceStore::prune($dir,$now+90000)===1,'retention');
        brendyTestAssert(is_file($dir.'/nonce-'.$fresh.'.json') && RecoveryNonceStore::consume($dir,$old,$res,$now+1)===false,'expired replay');
    } finally { brendyTestRemoveTree($dir); }
 },
];
