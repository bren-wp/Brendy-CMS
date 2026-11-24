<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/jezgra/pomocnici/date.class.php';
require_once dirname(__DIR__).'/jezgra/administracija/teme/brendy-admin/init.php';
return array(
 'Native date input normalizes without UTC conversion or lost seconds' => static function () {
    foreach (array('2026-11-25T17:31:42'=>'2026-11-25 17:31:42','2026-11-25T17:31'=>'2026-11-25 17:31:00','2024-02-29 00:00:00'=>'2024-02-29 00:00:00') as $input=>$expected) brendyTestAssert(Date::fromLocalInput($input)===$expected,'Valid date failed');
    foreach (array('2026-02-29T17:30','2026-11-25T25:00','2026-11-25T17:30Z','2026-11-25T17:30:61','0000-01-01T00:00','','2026-11-25T17:30+01:00',array()) as $input) brendyTestAssert(Date::fromLocalInput($input)===false,'Invalid date accepted');
 },
 'Native date input renders original name, seconds and escaped label' => static function () {
    $html=BrendyAdminUi::formInputDateTime(array('name'=>'date','label'=>'<b>','value'=>'2026-11-25 17:31:42'));
    brendyTestAssert(str_contains($html,'type="datetime-local"') && str_contains($html,'step="1"') && str_contains($html,'name="date"') && str_contains($html,'2026-11-25T17:31:42') && !str_contains($html,'<b>'),'Invalid native markup');
 },
 'Staged hard link cannot change unrelated file permissions' => static function () {
    $dir=brendyTestTempDir('brendy-stage-link');
    try { $target=$dir.'/target'; $alias=$dir.'/alias'; $dest=$dir.'/new'; file_put_contents($target,'original'); chmod($target,0644); brendyTestAssert(link($target,$alias),'Cannot create link fixture');
    brendyTestAssert(!Filesystem::publishStagedFile($alias,$dest,0600),'Linked stage accepted'); clearstatcache(true,$target);
    brendyTestAssert((fileperms($target)&0777)===0644 && file_get_contents($target)==='original' && !file_exists($dest),'Linked target changed');
    } finally {brendyTestRemoveTree($dir);}
 },
 'SEO article uses visible headline and connected WebPage breadcrumb' => static function () {
    $keys=array('site','url','WHERE_AM_I','page','content','language'); $old=array(); foreach($keys as $k) $old[$k]=array(array_key_exists($k,$GLOBALS),$GLOBALS[$k]??null);
    try {
        $GLOBALS['site']=new class {public function description(){return 'Site description';} public function title(){return 'Site name';}};
        $GLOBALS['url']=new class {public function notFound(){return false;}};
        $GLOBALS['language']=new class {public function currentLanguageShortVersion(){return 'hr';}};
        $GLOBALS['WHERE_AM_I']='page'; $GLOBALS['page']=new class {public function isStatic(){return false;} public function title(){return 'Article headline';} public function date($f){return '2026-11-24T12:00:00+01:00';} public function dateModified($f){return '2026-11-24T12:01:00+01:00';} public function tags($a){return array();} public function user($k){return 'Author';} public function noindex(){return false;} public function nofollow(){return false;} public function noarchive(){return false;}};
        $m=new ReflectionMethod(Theme::class,'structuredData'); $html=$m->invoke(null,'Article headline - Site name','Description',DOMAIN_BASE.'article','');
        preg_match('~>(.*)</script>~s',$html,$match); $doc=json_decode($match[1]??'',true,512,JSON_THROW_ON_ERROR); $nodes=array_column($doc['@graph'],null,'@type');
        brendyTestAssert($nodes['BlogPosting']['headline']==='Article headline' && !isset($nodes['BlogPosting']['breadcrumb']) && $nodes['WebPage']['breadcrumb']['@id']===$nodes['BreadcrumbList']['@id'] && $nodes['WebPage']['mainEntity']['@id']===$nodes['BlogPosting']['@id'],'Invalid linked article graph');
    } finally {foreach($old as $k=>$v) {if($v[0]) $GLOBALS[$k]=$v[1];else unset($GLOBALS[$k]);}}
 },
 'SEO list omits private noindex and duplicate items' => static function () {
    $old=$GLOBALS['pages']??null; $oldSite=$GLOBALS['site']??null;
    try { $GLOBALS['pages']=new class {public function isPubliclyAccessible($key){return $key!=='private';}}; $GLOBALS['site']=new class {public function homepage(){return '';}};
    $make=static fn($key,$noindex=false)=>new class($key,$noindex) {public function __construct(private $k, private $n){} public function key(){return $this->k;} public function title(){return $this->k;} public function noindex(){return $this->n;} public function permalink($absolute=true){return DOMAIN_BASE.$this->k;}};
    $m=new ReflectionMethod(Theme::class,'structuredItemList'); $r=$m->invoke(null,array($make('visible'),$make('private'),$make('hidden',true),$make('visible')) ,DOMAIN_BASE);
    brendyTestAssert(count($r['itemListElement'])===1 && $r['itemListElement'][0]['url']===DOMAIN_BASE.'visible','SEO list exposed private or duplicate item');
    } finally {$GLOBALS['pages']=$old;$GLOBALS['site']=$oldSite;}
 },
);
