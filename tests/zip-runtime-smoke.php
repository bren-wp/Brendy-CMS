<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
if (!extension_loaded('zip')) { fwrite(STDERR, "[SKIP] ext-zip unavailable; actual archive tests not executed.\n"); exit(2); }
$root = realpath(brendyTestTempDir('brendy-zip-runtime'));
try {
    $make = static function ($path, $entries) {
        $zip = new ZipArchive();
        brendyTestAssert($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Cannot create test ZIP');
        foreach ($entries as $name=>$contents) brendyTestAssert($zip->addFromString($name,$contents), 'Cannot add entry');
        brendyTestAssert($zip->close(), 'Cannot close test ZIP');
    };
    $make($root.'/valid.zip', ['a/b.txt'=>'content']);
    brendyTestAssert(Filesystem::unzip($root.'/valid.zip',$root.'/valid') && file_get_contents($root.'/valid/a/b.txt')==='content','Valid extraction failed');
    echo "[PASS] valid ZIP extraction\n";
    $make($root.'/unsafe.zip', ['../outside.txt'=>'no']);
    brendyTestAssert(!Filesystem::unzip($root.'/unsafe.zip',$root.'/unsafe') && !file_exists($root.'/outside.txt'),'Traversal accepted');
    echo "[PASS] ZIP traversal rejection\n";
    mkdir($root.'/collision'); file_put_contents($root.'/collision/existing.txt','preserve');
    $make($root.'/collision.zip', ['a/b.txt'=>'new','existing.txt'=>'replace']);
    brendyTestAssert(!Filesystem::unzip($root.'/collision.zip',$root.'/collision') && !file_exists($root.'/collision/a') && file_get_contents($root.'/collision/existing.txt')==='preserve','Collision rollback failed');
    echo "[PASS] ZIP rollback preserves existing data\n";
    brendyTestAssert(!Filesystem::unzip($root.'/valid.zip',$root.'/limited',2),'ZIP byte limit bypassed');
    echo "[PASS] ZIP byte limit\n";
    brendyTestAssert(Filesystem::zip($root.'/valid',$root.'/backup.zip') && Filesystem::unzip($root.'/backup.zip',$root.'/restored') && file_get_contents($root.'/restored/a/b.txt')==='content','Backup round trip failed');
    echo "[PASS] ZIP backup round trip\n";
    mkdir($root.'/valid/empty');
    brendyTestAssert(Filesystem::zip($root.'/valid/',$root.'/nested.zip') && Filesystem::unzip($root.'/nested.zip',$root.'/nested') && is_dir($root.'/nested/empty'),'Empty directory/trailing slash round trip failed');
    echo "[PASS] ZIP empty directory and trailing separator\n";
    if (method_exists('ZipArchive','isEncryptionMethodSupported') && ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)) {
        brendyTestAssert(Filesystem::zip($root.'/valid',$root.'/encrypted.zip','test-passphrase'),'AES creation failed');
        brendyTestAssert(!Filesystem::unzip($root.'/encrypted.zip',$root.'/badpass','wrong'),'Wrong AES password accepted');
        brendyTestAssert(Filesystem::unzip($root.'/encrypted.zip',$root.'/encrypted','test-passphrase') && file_get_contents($root.'/encrypted/a/b.txt')==='content','AES round trip failed');
        echo "[PASS] AES-256 round trip and wrong password rejection\n";
    } else { echo "[SKIP] AES-256 unsupported by local libzip\n"; }
} finally { brendyTestRemoveTree($root); }
