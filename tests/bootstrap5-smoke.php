<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return array(
 'vendor files match supplied archive manifest' => static function () {
  $root=dirname(__DIR__); $manifest=json_decode(file_get_contents(__DIR__.'/vendor-manifest.json'),true,512,JSON_THROW_ON_ERROR);
  brendyTestAssert(count($manifest['files'])>=24,'Incomplete vendor manifest');
  foreach($manifest['files'] as $path=>$entry) {
   brendyTestAssert(is_file($root.'/'.$path) && !is_link($root.'/'.$path) && hash_file('sha256',$root.'/'.$path)===$entry['sha256'],'Vendor file differs: '.$path);
  }
 },
 'TinyMCE runtime version and configured plugins' => static function () {
  $root=dirname(__DIR__).'/jezgra/urednik/';
  brendyTestAssert(BRENDY_EDITOR_VERSION==='8.10.2' && strpos(file_get_contents($root.'tinymce/tinymce.min.js'),'TinyMCE version 8.10.2')!==false,'Stale TinyMCE version');
  $plugins=array_map('basename',glob($root.'tinymce/plugins/*',GLOB_ONLYDIR)); sort($plugins);
  brendyTestAssert($plugins===array('advlist','autolink','charmap','code','codesample','fullscreen','image','link','lists','pagebreak','searchreplace','table','wordcount'),'Unexpected editor plugins');
  brendyTestAssert(is_file($root.'tinymce/langs/hr.js'),'Croatian locale missing');
 },
 'editor save uses live status and native submit' => static function () {
  $root=dirname(__DIR__).'/jezgra/administracija/pogledi/';
  foreach(array('new-content.php','edit-content.php') as $file) {
   $s=file_get_contents($root.$file);
   brendyTestAssert(strpos($s,'.attr("data-switch")')!==false && strpos($s,'requestSubmit()')!==false && strpos($s,'.data("switch")')===false,'Editor submit contract: '.$file);
  }
 },
 'Bootstrap 5 physical vendor version' => static function () {
  $root=dirname(__DIR__); foreach (array('/jezgra/css/bootstrap.min.css','/jezgra/js/bootstrap.bundle.min.js') as $p) {
   brendyTestAssert(preg_match('/Bootstrap\s+v5\.3\.9\b/',file_get_contents($root.$p))===1,'Wrong vendor version: '.$p);
  }
 },
 'native Bootstrap 5 admin helpers' => static function () {
  require_once dirname(__DIR__).'/jezgra/administracija/teme/brendy-admin/init.php';
  $file=BrendyAdminUi::formInputFile(array('id'=>'asset','label'=>'<test>','name'=>'file'));
  brendyTestAssert(strpos($file,'class="form-control"')!==false && strpos($file,'for="asset"')!==false && strpos($file,'&lt;test&gt;')!==false,'File field incorrect');
  $modal=BrendyAdminUi::modal(array('modalId'=>'demo'));
  brendyTestAssert(strpos($modal,'data-bs-dismiss="modal"')!==false,'Modal dismiss incorrect');
 },
 'legacy Bootstrap data attributes removed from bundled PHP views' => static function () {
  $root=dirname(__DIR__); foreach (array('jezgra/administracija','dodaci','teme') as $dir) {
   $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS));
   foreach($it as $f) if($f->isFile() && $f->getExtension()==='php') {
    brendyTestAssert(!preg_match('/\bdata-(?:toggle|dismiss|target)\s*=/',file_get_contents($f->getPathname())),'Legacy attribute: '.$f->getPathname());
   }
  }
 },
 'old Select2 skin removed and new one present' => static function () {
  $root=dirname(__DIR__).'/jezgra/css/'; brendyTestAssert(!is_file($root.'select2-bootstrap4.min.css') && is_file($root.'select2-brendy5.min.css'),'Select2 skin incorrect');
 },
 'editor media plugin absent from distribution and configuration' => static function () {
  $root=dirname(__DIR__); brendyTestAssert(!is_dir($root.'/jezgra/urednik/tinymce/plugins/media'),'Media plugin present');
  brendyTestAssert(!preg_match('/[\x27\x22]media[\x27\x22]\s*,/',file_get_contents($root.'/jezgra/urednik/urednik.js')),'Media plugin enabled');
 }
);
