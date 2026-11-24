<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return array(
 'Prism 1.30.0 embedded without optional network plugins' => static function () {
  $dir=dirname(__DIR__).'/jezgra/urednik/tinymce/';
  $s=file_get_contents($dir.'plugins/codesample/plugin.min.js');
  brendyTestAssert(str_contains($s,'PrismJS 1.30.0') && !str_contains($s,'data-autoloader-path') && !str_contains($s,'data-src-status'), 'Unexpected Prism bundle');
  brendyTestAssert(is_file($dir.'Prism-LICENSE.txt'), 'Missing Prism license');
 },
 'Recursive delete with trailing slash never follows directory symlink' => static function () {
  $dir=brendyTestTempDir('brendy-delete-link');
  try {
   mkdir($dir.'/target');file_put_contents($dir.'/target/keep','keep');
   if (!@symlink($dir.'/target',$dir.'/link')) throw new RuntimeException('Cannot create symlink');
   brendyTestAssert(Filesystem::deleteRecursive($dir.'/link/'), 'Link cleanup failed');
   brendyTestAssert(!is_link($dir.'/link') && file_get_contents($dir.'/target/keep')==='keep', 'Link target deleted');
   brendyTestAssert(!Filesystem::deleteRecursive($dir.'/target/.') && !Filesystem::deleteRecursive($dir.'/target/..'), 'Dot path accepted');
   brendyTestAssert(file_exists($dir.'/target/keep'), 'Dot path changed contents');
  } finally { brendyTestRemoveTree($dir); }
 },
 'Recursive delete retains root on request and removes only nested entries' => static function () {
  $dir=brendyTestTempDir('brendy-delete-tree');
  try {
   mkdir($dir.'/root');mkdir($dir.'/root/nested');file_put_contents($dir.'/root/nested/file','x');file_put_contents($dir.'/outside','keep');
   if (!@symlink($dir.'/outside',$dir.'/root/link')) throw new RuntimeException('Cannot create symlink');
   brendyTestAssert(Filesystem::deleteRecursive($dir.'/root/',false), 'Nested cleanup failed');
   brendyTestAssert(scandir($dir.'/root')===array('.','..') && file_get_contents($dir.'/outside')==='keep','Unexpected contents');
  } finally { brendyTestRemoveTree($dir); }
 },
 'Error and preview SEO omit unrelated social metadata' => static function () {
  $old=$GLOBALS['url']??null;$preview=$GLOBALS['BRENDY_PREVIEW_MODE']??null;
  try {
   $GLOBALS['url']=new class {public function notFound(){return true;}};
   $GLOBALS['BRENDY_PREVIEW_MODE']=false;
   $m=new ReflectionMethod(Theme::class,'renderSeoHead');
   brendyTestAssert($m->invoke(null,'private')==='<meta name="robots" content="noindex,follow">'.PHP_EOL,'Error metadata leaked');
   $GLOBALS['BRENDY_PREVIEW_MODE']=true;
   brendyTestAssert($m->invoke(null,'private')==='<meta name="robots" content="noindex,nofollow,noarchive">'.PHP_EOL,'Preview metadata leaked');
  } finally {$GLOBALS['url']=$old;$GLOBALS['BRENDY_PREVIEW_MODE']=$preview;}
 },
);
