<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
ob_start();
require dirname(__DIR__).'/jezgra/apstraktno/brendy-plugin.class.php';
require dirname(__DIR__).'/dodaci/backup-manager/plugin.php';
require dirname(__DIR__).'/jezgra/pokretanje/restore-gate.php';
require __DIR__.'/restore-journal-smoke.php';
// Real panel and plugin POST action, isolated identity/language/alert adapters.
// This is a component test, not a real login or CSRF HTTP test.
class Alert {
    public static $last;
    public static function set($message,$status) { self::$last=[$message,$status]; }
}
define('ALERT_STATUS_OK','ok'); define('ALERT_STATUS_FAIL','fail');
[$r,$stage,$incoming,$j]=journalFixture();
try {
    define('PATH_CONTENT',$r.'/'); define('PATH_TMP',$r.'/tmp/'); define('BRENDY_CONTENT_DIRECTORY','sadrzaj');
    $GLOBALS['_brendyRestoreGates']['sadrzaj']=['handle'=>Filesystem::acquireLock(PATH_TMP.'restore-request.lock',LOCK_SH,0600),'generation'=>''];
    $language=new class {
        private $entries;
        public function __construct() { $this->entries=json_decode(file_get_contents(dirname(__DIR__).'/dodaci/backup-manager/jezici/hr_HR.json'),true); }
        public function get($key) { return $this->entries[$key]??$key; }
    };
    $login=new class { public $current='admin'; public function role(){return $this->current;} };
    $syslog=new class { public $entries=[]; public function add($entry){$this->entries[]=$entry;} };
    $plugin=(new ReflectionClass(BrendyPluginBackupManager::class))->newInstanceWithoutConstructor();
    $panel=new ReflectionMethod(BrendyPluginBackupManager::class,'recoveryPanel');
    $p=$j->prepare($stage); brendyTestAssert(is_array($p) && $j->recover()==='rolled-back','Fixture recovery failed');
    $empty='restore-'.str_repeat('b',32);mkdir(PATH_TMP.$empty);touch(PATH_TMP.$empty,time()-90000);
    $markup=$panel->invoke($plugin);
    brendyTestAssert(str_contains($markup,'Oporavak podataka') && str_contains($markup,'Prethodno stanje vraćeno') && str_contains($markup,'recovery-cleanup:'.$empty) && !str_contains($markup,'recovery-cleanup:'.$stage),'Panel result/eligibility wrong');
    echo "[PASS] Real panel renders recovery receipt, retained data and eligible empty action\n";
    $token=array_column($j->inventory()['items'],null,'name')[$empty]['token'];
    $_POST=['backup-action'=>'recovery-cleanup:'.$empty,'recovery-token'=>[$empty=>$token]];
    $login->current='editor';
    brendyTestAssert($panel->invoke($plugin)==='' && $plugin->post()===false && is_dir(PATH_TMP.$empty),'Nonadmin allowed');
    echo "[PASS] Nonadmin component denied panel and cleanup action\n";
    $login->current='admin'; $_POST['recovery-token'][$empty]=str_repeat('0',64);
    brendyTestAssert($plugin->post()===false && is_dir(PATH_TMP.$empty),'Invalid selection accepted');
    echo "[PASS] Admin component rejects invalid identity token\n";
    $_POST['recovery-token'][$empty]=$token;
    brendyTestAssert($plugin->post()===true && !is_dir(PATH_TMP.$empty) && is_file($incoming.'/databases/site.php') && count($syslog->entries)===1,'Cleanup action failed');
    echo "[PASS] Actual plugin POST removes only selected empty directory and logs action\n";
    brendyTestAssert(strlen(brendyRestoreGeneration($GLOBALS['_brendyRestoreGates']['sadrzaj']['handle']))===32,'Generation not readable');
    echo "[PASS] Generation token is readable through locked descriptor\n";
    $stage2='restore-'.str_repeat('c',32); mkdir(PATH_TMP.$stage2.'/sadrzaj/databases',0700,true); file_put_contents(PATH_TMP.$stage2.'/sadrzaj/databases/site.php','next');
    $q=$j->prepare($stage2); brendyTestAssert(is_array($q) && $j->apply($q) && $j->commit($q) && $j->recover()==='committed','Commit fixture failed');
    $ledger=new RecoveryLedger(rtrim(PATH_TMP,DS)); $state=$ledger->read(); $state['history'][1]['time']=time()-90000; brendyTestAssert($ledger->save($state),'Age fixture failed');
    $name='backup-restore-rollback-'.$q['id']; $item=array_column($j->inventory()['items'],null,'name')[$name];
    brendyTestAssert($item['verified'] && str_contains($panel->invoke($plugin),'recovery-purge:'.$name),'Purge action not rendered');
    $_POST=['backup-action'=>'recovery-purge:'.$name,'recovery-token'=>[$name=>$item['token']]];
    $login->current='editor'; brendyTestAssert(!$plugin->post() && is_dir(PATH_TMP.$name),'Nonadmin purge allowed');
    $login->current='admin'; brendyTestAssert($plugin->post() && !is_dir(PATH_TMP.$name) && file_get_contents(PATH_CONTENT.'databases/site.php')==='next','Verified admin purge failed');
    echo "[PASS] Actual plugin POST verifies and purges only authenticated committed rollback; nonadmin denied\n";
    ob_end_flush();
} finally { unset($plugin); if (isset($GLOBALS['_brendyRestoreGates']['sadrzaj']['handle'])) Filesystem::releaseLock($GLOBALS['_brendyRestoreGates']['sadrzaj']['handle']);brendyTestRemoveTree($r); }
