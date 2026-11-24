<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/jezgra/pomocnici/tcp.class.php';
return array(
 'SSRF rejects translation, private and ambiguous hosts' => static function () {
    $address = new ReflectionMethod(TCP::class, 'isPublicAddress');
    foreach (array('64:ff9b::7f00:1','64:ff9b:1::a00:1','::ffff:127.0.0.1','2002:7f00:1::','127.0.0.1','100.64.0.1','169.254.169.254','fc00::1','::1') as $ip) brendyTestAssert(!$address->invoke(null,$ip),'Non-public IP accepted');
    foreach (array('8.8.8.8','2606:4700:4700::1111') as $ip) brendyTestAssert($address->invoke(null,$ip),'Public IP rejected');
    $resolve = new ReflectionMethod(TCP::class, 'resolvePublicHttpsUrl');
    foreach (array('https://127.1/','https://2130706433/','https://0x7f.0.0.1/','https://[64:ff9b::7f00:1]/','https://localhost/','http://8.8.8.8/','https://8.8.8.8:444/','https://user@8.8.8.8/') as $url) brendyTestAssert($resolve->invoke(null,$url)===false,'Unsafe URL accepted');
    foreach (array('https://8.8.8.8/','https://[2606:4700:4700::1111]/') as $url) brendyTestAssert($resolve->invoke(null,$url)===array('resolve'=>null),'Literal resolution failed');
    $dns = new ReflectionMethod(TCP::class, 'isDnsHostname');
    foreach (array('example.com','www.example.hr','xn--bcher-kva.example') as $host) brendyTestAssert($dns->invoke(null,$host),'DNS host rejected');
 },
 'Staging writer rejects nonempty, linked and missing files' => static function () {
    $dir=brendyTestTempDir('brendy-stage-open');
    try {
        $file=$dir.'/target'; $alias=$dir.'/alias'; file_put_contents($file,'untouched');
        brendyTestAssert(Filesystem::openEmptyStagingFile($file)===false && file_get_contents($file)==='untouched','Nonempty file truncated');
        $empty=$dir.'/empty'; touch($empty); brendyTestAssert(link($empty,$alias),'Hardlink fixture failed');
        brendyTestAssert(Filesystem::openEmptyStagingFile($alias)===false && filesize($empty)===0,'Hard link accepted');
        unlink($alias); symlink($empty,$alias);
        brendyTestAssert(Filesystem::openEmptyStagingFile($alias)===false && Filesystem::openEmptyStagingFile($dir.'/missing')===false,'Symlink or missing accepted');
        $h=Filesystem::openEmptyStagingFile($empty); brendyTestAssert(is_resource($h),'Empty regular file refused'); fwrite($h,'new'); fclose($h);
        brendyTestAssert(file_get_contents($empty)==='new','Write failed');
        brendyTestAssert(Filesystem::atomicWrite($file,'replacement') && file_get_contents($file)==='replacement','Atomic write regression');
    } finally {brendyTestRemoveTree($dir);}
 },
 'Publication rejects a stage replaced after the writer closes' => static function () {
    $dir=brendyTestTempDir('brendy-stage-swap');
    try {
        $stage=$dir.'/stage'; $original=$dir.'/original'; $destination=$dir.'/destination';
        file_put_contents($stage,'new'); file_put_contents($destination,'old');
        $handle=fopen($stage,'rb'); $expected=fstat($handle); fclose($handle);
        rename($stage,$original); file_put_contents($stage,'bad');
        brendyTestAssert(!Filesystem::publishStagedFile($stage,$destination,0600,$expected) && file_get_contents($destination)==='old','Replaced stage accepted');
        brendyTestAssert(Filesystem::publishStagedFile($original,$destination,0600,$expected) && file_get_contents($destination)==='new','Valid stage failed');
    } finally {brendyTestRemoveTree($dir);}
 },
 'Configured homepage has identical structured data at root and alias' => static function () {
    $keys=array('site','url','WHERE_AM_I','page','pages','content','language'); $old=array(); foreach($keys as $k) $old[$k]=array(array_key_exists($k,$GLOBALS),$GLOBALS[$k]??null);
    try {
        $GLOBALS['site']=new class {public function description(){return 'Description';} public function title(){return 'Site';} public function homepage(){return 'start';}};
        $GLOBALS['url']=new class {public function notFound(){return false;}};
        $GLOBALS['pages']=new class {public function isPubliclyAccessible($k){return true;}};
        $GLOBALS['language']=new class {public function currentLanguageShortVersion(){return 'hr';}};
        $GLOBALS['page']=new class {public function key(){return 'start';} public function noindex(){return false;} public function nofollow(){return false;} public function noarchive(){return false;}};
        $GLOBALS['content']=array(); $m=new ReflectionMethod(Theme::class,'structuredData');
        $GLOBALS['WHERE_AM_I']='home'; $first=$m->invoke(null,'Home','Description',DOMAIN_BASE,'');
        $GLOBALS['WHERE_AM_I']='page'; $alias=$m->invoke(null,'Home','Description',DOMAIN_BASE,'');
        brendyTestAssert($first===$alias && str_contains($first,'WebPage') && !str_contains($first,'BreadcrumbList') && !str_contains($first,'BlogPosting'),'Home graph inconsistent');
    } finally {foreach($old as $k=>$v){if($v[0])$GLOBALS[$k]=$v[1];else unset($GLOBALS[$k]);}}
 },
);
