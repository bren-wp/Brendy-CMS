<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return array(
    'Debug logs do not retain query parameter values or controls' => static function () {
        $method = new ReflectionMethod(Log::class, 'requestPathForLog');
        brendyTestAssert($method->invoke(null, '/search?q=private&%74oken=secret#hidden') === '/search', 'Query value leaked into log');
        brendyTestAssert($method->invoke(null, "/x\r\n\x1b") === '/x   ', 'Control character retained in log');
        brendyTestAssert($method->invoke(null, array()) === '', 'Array-valued request URI accepted');
    },
    'Bounded read stability detects observable content and link changes' => static function () {
        $method = new ReflectionMethod(Filesystem::class, 'unchangedReadSnapshot');
        $dir = brendyTestTempDir('brendy-snapshot');
        try {
            $path = $dir . '/file'; file_put_contents($path, 'abc'); $before = stat($path);
            brendyTestAssert($method->invoke(null, $before, $before), 'Stable metadata rejected');
            foreach (array('ino','size','mtime','ctime','nlink') as $key) {
                $changed = $before; $changed[$key]++;
                brendyTestAssert(!$method->invoke(null, $before, $changed), 'Changed metadata accepted: ' . $key);
            }
            brendyTestAssert(Filesystem::readFileBounded($path, 10) === 'abc', 'Regular read broken');
            brendyTestAssert(Filesystem::copyFileSafely($path, $dir . '/copy', 0600), 'Regular copy broken');
        } finally { brendyTestRemoveTree($dir); }
    },
    'API form input rejects invalid UTF-8, NUL and control keys' => static function () {
        $plugin = (new ReflectionClass(BrendyPluginApi::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BrendyPluginApi::class, 'cleanInputs');
        foreach (array(array('title'=>"\xff"),array("bad\nkey"=>'ok'),array('text'=>"x\0y"),array("\xff"=>'ok')) as $input) {
            brendyTestAssert($method->invoke($plugin, $input) === array(), 'Malformed API input accepted');
        }
        $good = array('title'=>'Članak', 'content'=>"Line 1\nLine 2");
        brendyTestAssert($method->invoke($plugin, $good) === $good, 'Valid form values lost');
        brendyTestAssert($method->invoke($plugin, json_encode($good)) === $good, 'Valid JSON values lost');
    },
    'SEO content fallback preserves escaped literal code and strips hidden HTML' => static function () {
        $page = new class {
            public function description() { return ''; }
            public function contentRaw() { return '<p>Use &lt;code&gt; here &amp; there.</p><script>hidden</script>'; }
        };
        brendyTestAssert(Theme::seoPageDescriptionText($page) === 'Use <code> here & there.', 'Visible escaped code was lost');
    },
    'Search refuses stale terms when rebuilding the cache fails' => static function () {
        $dir = brendyTestTempDir('brendy-stale-search');
        $previousPages = $GLOBALS['pages'] ?? null;
        try {
            if (!defined('DB_PAGES')) define('DB_PAGES', $dir . '/pages.php');
            if (!defined('DB_CATEGORIES')) define('DB_CATEGORIES', $dir . '/categories.php');
            if (!defined('PATH_KERNEL')) define('PATH_KERNEL', dirname(__DIR__) . '/jezgra/');
            $plugin = new class($dir) extends BrendyPluginSearch {
                public function __construct(private $directory) { $this->init(); $this->filenameDb = $directory . '/settings.php'; $this->db = array('minChars'=>1); }
                public function workspace() { return $this->directory . '/'; }
                protected function withWorkspaceLock($lockName, $callback) { return false; }
            };
            $GLOBALS['pages'] = new class { public function isPubliclyAccessible($key) { return true; } };
            file_put_contents($dir . '/cache.json', '{"public":{"title":"removed secret"}}');
            touch($dir . '/cache.json', time()-600);
            $method = new ReflectionMethod(BrendyPluginSearch::class, 'search');
            brendyTestAssert($method->invoke($plugin, 'secret') === array(), 'Stale search terms remained queryable');
        } finally { $GLOBALS['pages'] = $previousPages; brendyTestRemoveTree($dir); }
    },
);
