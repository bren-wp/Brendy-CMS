<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('REMEMBER_COOKIE_EXPIRE_IN_DAYS')) define('REMEMBER_COOKIE_EXPIRE_IN_DAYS', 30);
require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'users.class.php';

return array(
    'json database valid load and stale-write protection' => static function () {
        $dir = brendyTestTempDir('brendy-db');
        try {
            $file = $dir . DS . 'db.php';
            $guard = "<?php defined('BRENDY') or die('Brendy CMS.'); ?>\n";
            file_put_contents($file, $guard . '{"value":1}');
            $first = new BrendyJsonDatabase($file);
            $second = new BrendyJsonDatabase($file);
            brendyTestAssert($first->loadedSuccessfully() && $second->loadedSuccessfully(), 'Valid JSON database did not load.');
            brendyTestAssert(($first->db['value'] ?? null) === 1, 'Loaded JSON database value is incorrect.');
            $first->db['value'] = 2;
            brendyTestAssert($first->save(), 'First JSON database save failed.');
            $second->db['value'] = 3;
            brendyTestAssert($second->save() === false, 'Stale JSON database write was not rejected.');
            brendyTestAssert($second->recoverPersistedState(), 'Persisted JSON state could not be recovered.');
            brendyTestAssert(($second->db['value'] ?? null) === 2, 'Recovered JSON state does not match the committed writer.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'json database rejects corrupt and symlink state' => static function () {
        $dir = brendyTestTempDir('brendy-db-invalid');
        try {
            $corrupt = $dir . DS . 'corrupt.php';
            file_put_contents($corrupt, "<?php defined('BRENDY') or die('Brendy CMS.'); ?>\n{");
            $store = new BrendyJsonDatabase($corrupt);
            brendyTestAssert($store->loadedSuccessfully() === false, 'Corrupt JSON database was accepted.');
            if (function_exists('symlink')) {
                $dangling = $dir . DS . 'dangling.php';
                if (@symlink($dir . DS . 'missing-target.php', $dangling)) {
                    $linked = new BrendyJsonDatabase($dangling);
                    brendyTestAssert($linked->loadedSuccessfully() === false, 'Dangling database symlink was treated as a new store.');
                }
            }
        } finally { brendyTestRemoveTree($dir); }
    },
    'json database inspection reports descriptor-consistent bytes' => static function () {
        $dir = brendyTestTempDir('brendy-db-inspect');
        try {
            $file = $dir . DS . 'db.php';
            $raw = "<?php defined('BRENDY') or die('Brendy CMS.'); ?>\n" . '{"alpha":1,"beta":2}';
            file_put_contents($file, $raw);
            $inspection = BrendyJsonDatabase::inspectFile($file, true, 1048576);
            brendyTestAssert(is_array($inspection) && ($inspection['ok'] ?? false) === true, 'Valid JSON database inspection failed.');
            brendyTestAssert(($inspection['bytes'] ?? -1) === strlen($raw), 'Inspection byte count does not match the descriptor snapshot payload.');
            brendyTestAssert(($inspection['entries'] ?? -1) === 2, 'Inspection entry count is incorrect.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'json database honors per-store byte limits' => static function () {
        $dir = brendyTestTempDir('brendy-db-limit');
        try {
            $file = $dir . DS . 'bounded.php';
            $guard = "<?php defined('BRENDY') or die('Brendy CMS.'); ?>\n";
            file_put_contents($file, $guard . json_encode(array('payload' => str_repeat('x', 256))));
            $bounded = new BrendyJsonDatabase($file, true, 128);
            brendyTestAssert($bounded->loadedSuccessfully() === false, 'Per-store load byte limit was ignored.');

            $newFile = $dir . DS . 'new.php';
            $newStore = new BrendyJsonDatabase($newFile, true, 128);
            brendyTestAssert($newStore->loadedSuccessfully(), 'Absent bounded store should initialize as a valid empty store.');
            $newStore->db = array('payload' => str_repeat('y', 256));
            brendyTestAssert($newStore->save() === false, 'Per-store save byte limit was ignored.');
            brendyTestAssert(!file_exists($newFile), 'Rejected oversized database save created a file.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'truncate conflict recovers the committed database state' => static function () {
        $dir = brendyTestTempDir('brendy-truncate-race');
        try {
            $file = $dir . DS . 'records.php';
            file_put_contents($file, "<?php defined('BRENDY') or die('Brendy CMS.'); ?>\n" . '{"entry":"initial"}');
            $first = new BrendyJsonDatabase($file);
            $second = new BrendyJsonDatabase($file);
            $first->db['entry'] = 'newer';
            brendyTestAssert($first->save(), 'Newer database state could not be saved.');
            brendyTestAssert($second->truncate() === false, 'Stale truncate unexpectedly overwrote the newer state.');
            brendyTestAssert(($second->db['entry'] ?? null) === 'newer',
                'Rejected truncate retained a stale in-memory database instead of committed state.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'user security mutation conflict recovers current tokens' => static function () {
        $dir = brendyTestTempDir('brendy-user-token-race');
        try {
            $file = $dir . DS . 'users.php';
            $row = array('enabled' => true, 'password' => 'not-disabled', 'role' => 'author',
                'tokenAuth' => str_repeat('a', 64), 'tokenRemember' => '', 'tokenRememberExpires' => 0);
            file_put_contents($file, "<?php defined('BRENDY') or die('Brendy CMS.'); ?>\n" . json_encode(array('alice' => $row)));
            $reflect = new ReflectionClass(Users::class);
            $parentConstructor = new ReflectionMethod(BrendyJsonDatabase::class, '__construct');
            $first = $reflect->newInstanceWithoutConstructor();
            $second = $reflect->newInstanceWithoutConstructor();
            $parentConstructor->invoke($first, $file);
            $parentConstructor->invoke($second, $file);
            $token = str_repeat('c', 64);
            brendyTestAssert($first->setRememberToken('alice', $token), 'Fresh remember-token save failed.');
            brendyTestAssert($second->disableUser('alice') === false, 'Stale user update overwrote a newer token.');
            brendyTestAssert($second->getByRememberToken($token) === 'alice',
                'Rejected user security mutation kept obsolete in-memory remember token.');
            brendyTestAssert($second->isEnabled('alice') === true,
                'Rejected user security mutation retained uncommitted account-disable state.');
        } finally { brendyTestRemoveTree($dir); }
    },

    'two-factor replay conflict reloads the committed moving counter' => static function () {
        $dir = brendyTestTempDir('brendy-totp-race');
        try {
            $file = $dir . DS . 'users.php';
            $row = array('twoFactorEnabled' => true, 'twoFactorLastCounter' => 4);
            file_put_contents($file, "<?php defined('BRENDY') or die('Brendy CMS.'); ?>\n" . json_encode(array('alice' => $row)));
            $reflect = new ReflectionClass(Users::class);
            $init = new ReflectionMethod(BrendyJsonDatabase::class, '__construct');
            $first = $reflect->newInstanceWithoutConstructor();
            $second = $reflect->newInstanceWithoutConstructor();
            $init->invoke($first, $file);
            $init->invoke($second, $file);
            brendyTestAssert($first->consumeTwoFactorCounter('alice', 5), 'First TOTP counter consumption failed.');
            brendyTestAssert($second->consumeTwoFactorCounter('alice', 5) === false,
                'Concurrent reuse of the same TOTP counter was not rejected.');
            brendyTestAssert(($second->db['alice']['twoFactorLastCounter'] ?? null) === 5,
                'Rejected TOTP replay left a stale moving counter in memory.');
            brendyTestAssert($second->consumeTwoFactorCounter('alice', 5) === false,
                'Recovered TOTP counter unexpectedly permitted a second replay.');
        } finally { brendyTestRemoveTree($dir); }
    },

);
