<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BRENDY', true);
define('DS', DIRECTORY_SEPARATOR);
define('CHARSET', 'UTF-8');
define('DIR_PERMISSIONS', 0755);
define('DEBUG_MODE', false);
define('DEBUG_TYPE', 'INFO');
define('LOG_SEP', ' | ');
define('LOG_TYPE_INFO', 'INFO');
define('LOG_TYPE_WARN', 'WARN');
define('LOG_TYPE_ERROR', 'ERROR');
// The production installer requires mbstring. The CI/sandbox smoke runner may
// execute without it, so provide ASCII-only shims for tests that exercise
// filename allocation; Unicode behavior remains covered by production runtime.
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null) { return strtolower((string)$string); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null) {
        return $length === null ? substr((string)$string, $start) : substr((string)$string, $start, $length);
    }
}
require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'release.php';
require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'pomocnici' . DS . 'log.class.php';
require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'pomocnici' . DS . 'filesystem.class.php';
require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'pomocnici' . DS . 'restore-journal.class.php';
require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'pomocnici' . DS . 'text.class.php';
require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'pomocnici' . DS . 'paginator.class.php';
require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'apstraktno' . DS . 'brendy-json-database.class.php';
require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'pomocnici' . DS . 'theme.class.php';

function brendyTestAssert($condition, $message)
{
    if ($condition !== true) throw new RuntimeException($message);
}

function brendyTestTempDir($prefix)
{
    $base = rtrim(sys_get_temp_dir(), DS);
    $path = $base . DS . $prefix . '-' . bin2hex(random_bytes(8));
    if (!@mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Unable to create test directory.');
    return $path;
}

function brendyTestRemoveTree($path)
{
    if (!is_string($path) || $path === '' || (!file_exists($path) && !is_link($path))) return;
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    $items = @scandir($path);
    if (is_array($items)) foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        brendyTestRemoveTree($path . DS . $item);
    }
    @rmdir($path);
}
