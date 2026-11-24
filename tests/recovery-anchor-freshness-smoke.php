<?php
if (PHP_SAPI!=='cli') exit;
require_once __DIR__.'/recovery-anchor-freshness.class.php';
return ['Anchor freshness challenge binds nonce, signature, installation, sequence and expiry'=>static function () {
    $pair=sodium_crypto_sign_keypair(); $key=bin2hex(sodium_crypto_sign_secretkey($pair)); $pub=bin2hex(sodium_crypto_sign_publickey($pair));
    $install=str_repeat('a',64); $proposal=['installation'=>$install,'history'=>streamingTestHistory(10)]; $cp=RecoveryLedger::signAnchor($proposal,null,$key); $nonce=bin2hex(random_bytes(32)); $now=1700000000;
    $raw=RecoveryAnchorFreshness::respond($cp,$nonce,$key,$now); $expected=RecoveryLedger::verifyAnchor($cp,$pub);
    brendyTestAssert($raw!==false && RecoveryAnchorFreshness::verify($raw,$pub,$nonce,$install,10,$now+1,60,$expected['prefix'])===$expected,'fresh');
    brendyTestAssert(RecoveryAnchorFreshness::verify($raw,$pub,bin2hex(random_bytes(32)),$install,10,$now)===false,'nonce replay');
    brendyTestAssert(RecoveryAnchorFreshness::verify($raw,$pub,$nonce,$install,10,$now+61)===false,'expired');
    brendyTestAssert(RecoveryAnchorFreshness::verify($raw,$pub,$nonce,$install,10,$now-6)===false,'future');
    brendyTestAssert(RecoveryAnchorFreshness::verify($raw,$pub,$nonce,$install,11,$now)===false,'rollback');
    brendyTestAssert(RecoveryAnchorFreshness::verify($raw,$pub,$nonce,str_repeat('b',64),10,$now)===false,'wrong installation');
    brendyTestAssert(RecoveryAnchorFreshness::verify($raw,$pub,$nonce,$install,10,$now,60,str_repeat('0',64))===false,'fork');
    $bad=json_decode($raw,true); $bad['response']['issuedAt']++; brendyTestAssert(RecoveryAnchorFreshness::verify(RecoveryLedger::encode($bad),$pub,$nonce,$install,10,$now)===false,'signature');
}];
