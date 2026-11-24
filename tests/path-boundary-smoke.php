<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return array(
 'Directory helpers reject a symlink with terminal separator' => static function () {
  $dir=brendyTestTempDir('brendy-path-link');
  try {
   mkdir($dir.'/target'); file_put_contents($dir.'/target/keep','keep');
   if (!symlink($dir.'/target',$dir.'/link')) throw new RuntimeException('Cannot create test symlink');
   $link=$dir.'/link/';
   brendyTestAssert(!Filesystem::directoryExists($link) && !Filesystem::mkdir($link,true), 'Link accepted as directory');
   brendyTestAssert(Filesystem::getSize($link)===false && !Filesystem::mv($link,$dir.'/moved'), 'Link followed');
   brendyTestAssert(is_link($dir.'/link') && file_get_contents($dir.'/target/keep')==='keep' && !file_exists($dir.'/moved'), 'Target modified');
   unlink($dir.'/target/keep');
   brendyTestAssert(!Filesystem::rmdir($link) && is_dir($dir.'/target'), 'Link target removed');
  } finally { brendyTestRemoveTree($dir); }
 },
 'Directory helpers preserve legitimate trailing separator operations' => static function () {
  $dir=brendyTestTempDir('brendy-path-real');
  try {
   mkdir($dir.'/source');file_put_contents($dir.'/source/keep','keep');
   brendyTestAssert(Filesystem::directoryExists($dir.'/source/') && Filesystem::getSize($dir.'/source/')===4,'Real directory rejected');
   brendyTestAssert(!Filesystem::mv($dir.'/source/.',$dir.'/wrong'), 'Terminal dot accepted');
   brendyTestAssert(Filesystem::mv($dir.'/source/',$dir.'/target/'), 'Valid directory move failed');
   brendyTestAssert(file_get_contents($dir.'/target/keep')==='keep' && !is_dir($dir.'/source'),'Move postcondition failed');
   unlink($dir.'/target/keep');brendyTestAssert(Filesystem::rmdir($dir.'/target/'),'Valid rmdir failed');
  } finally {brendyTestRemoveTree($dir);}
 },
 'Directory size rejects special file entries without opening them' => static function () {
  $dir=brendyTestTempDir('brendy-path-fifo');
  try {
   if (!function_exists('posix_mkfifo')) throw new RuntimeException('Requires POSIX FIFO support');
   brendyTestAssert(posix_mkfifo($dir.'/pipe',0600),'FIFO creation failed');
   brendyTestAssert(Filesystem::getSize($dir)===false,'Special file accepted');
  } finally { @unlink($dir.'/pipe');brendyTestRemoveTree($dir); }
 },
);
