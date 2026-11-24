<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return array(
 'Delete preflight preserves tree containing a special entry' => static function () {
  $r=realpath(brendyTestTempDir('delete-preflight'));
  try { file_put_contents($r.'/keep','keep'); brendyTestAssert(posix_mkfifo($r.'/fifo',0600),'FIFO setup failed');
   brendyTestAssert(!Filesystem::deleteRecursive($r) && file_get_contents($r.'/keep')==='keep','Partial deletion before preflight');
  } finally { @unlink($r.'/fifo'); brendyTestRemoveTree($r); }
 },
 'Delete plan rejects replaced descendant before removing siblings' => static function () {
  $r=realpath(brendyTestTempDir('delete-replaced'));
  try { mkdir($r.'/tree'); mkdir($r.'/tree/sub'); file_put_contents($r.'/tree/sub/a','old'); file_put_contents($r.'/tree/keep','keep');
   $plan=(new ReflectionMethod(Filesystem::class,'snapshotTree'))->invoke(null,$r.'/tree');
   rename($r.'/tree/sub',$r.'/old'); mkdir($r.'/tree/sub'); file_put_contents($r.'/tree/sub/a','replacement');
   brendyTestAssert(!(new ReflectionMethod(Filesystem::class,'deleteTreeSnapshot'))->invoke(null,$r.'/tree',$plan,true),'Replaced descendant accepted');
   brendyTestAssert(file_get_contents($r.'/tree/sub/a')==='replacement' && file_get_contents($r.'/tree/keep')==='keep','Unexpected deletion');
  } finally { brendyTestRemoveTree($r); }
 },
 'Delete plan rejects replaced ancestor with identical child' => static function () {
  $r=realpath(brendyTestTempDir('delete-parent'));
  try { mkdir($r.'/parent'); mkdir($r.'/parent/tree'); file_put_contents($r.'/parent/tree/keep','keep');
   $plan=(new ReflectionMethod(Filesystem::class,'snapshotTree'))->invoke(null,$r.'/parent/tree');
   rename($r.'/parent',$r.'/old'); mkdir($r.'/parent'); rename($r.'/old/tree',$r.'/parent/tree');
   brendyTestAssert(!(new ReflectionMethod(Filesystem::class,'deleteTreeSnapshot'))->invoke(null,$r.'/parent/tree',$plan,true),'Replaced ancestor accepted');
   brendyTestAssert(file_get_contents($r.'/parent/tree/keep')==='keep','Moved tree deleted');
  } finally { brendyTestRemoveTree($r); }
 },
 'Delete plan does not delete a new entry and reports nonempty root' => static function () {
  $r=realpath(brendyTestTempDir('delete-new'));
  try { file_put_contents($r.'/old','old'); $plan=(new ReflectionMethod(Filesystem::class,'snapshotTree'))->invoke(null,$r); file_put_contents($r.'/new','new');
   brendyTestAssert(!(new ReflectionMethod(Filesystem::class,'deleteTreeSnapshot'))->invoke(null,$r,$plan,false) && file_get_contents($r.'/new')==='new','New entry mishandled');
  } finally { brendyTestRemoveTree($r); }
 },
 'Temporary staging rejects traversal prefix and uses exclusive private names' => static function () {
  $r=realpath(brendyTestTempDir('temp-exclusive')); $mask=umask(0);
  try { brendyTestAssert(Filesystem::temporaryFile($r,'../bad')===false,'Traversal prefix accepted');
   $a=Filesystem::temporaryFile($r); $b=Filesystem::temporaryFile($r);
   brendyTestAssert(is_string($a) && is_string($b) && $a!==$b && dirname($a)===$r && (fileperms($a)&0777)===0600 && umask()===0,'Private allocation failed');
  } finally { umask($mask); brendyTestRemoveTree($r); }
 },
);
