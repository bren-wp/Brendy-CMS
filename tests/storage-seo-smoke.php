<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return array(
    'guarded JSON encodes PHP delimiters without changing data' => static function () {
        $dir = brendyTestTempDir('brendy-guard-json');
        try {
            $file = $dir . DS . 'db.php';
            $db = new BrendyJsonDatabase($file);
            $db->db = array('html' => '<p>članak</p>', 'literal' => '<?php /* inert text */ ?>', 'short' => '<?= "text" ?>');
            brendyTestAssert($db->save(), 'Guarded JSON save failed');
            $bytes = file_get_contents($file);
            $opens = array_filter(token_get_all($bytes), static function ($token) {
                return is_array($token) && in_array($token[0], array(T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO), true);
            });
            brendyTestAssert(count($opens) === 1, 'Data introduced extra PHP opening tokens');
            $read = new BrendyJsonDatabase($file);
            brendyTestAssert($read->loadedSuccessfully() && $read->db === $db->db, 'Roundtrip altered content');
            $plain = new BrendyJsonDatabase($dir . DS . 'plain.json', false);
            $plain->db = $db->db;
            brendyTestAssert($plain->save() && (new BrendyJsonDatabase($plain->file, false))->db === $db->db, 'Plain JSON compatibility lost');
        } finally { brendyTestRemoveTree($dir); }
    },
    'lock rejects multiply linked files without chmod side effects' => static function () {
        $dir = brendyTestTempDir('brendy-lock-hardlink');
        try {
            $target = $dir . DS . 'target';
            $lock = $dir . DS . 'alias.lock';
            file_put_contents($target, 'unchanged'); chmod($target, 0644);
            brendyTestAssert(link($target, $lock), 'Hardlink fixture unavailable');
            brendyTestAssert(Filesystem::acquireLock($lock, LOCK_EX, 0600) === false, 'Multiply linked lock accepted');
            clearstatcache(true, $target);
            brendyTestAssert((fileperms($target) & 0777) === 0644 && file_get_contents($target) === 'unchanged', 'Lock modified linked target');
            unlink($lock);
            $handle = Filesystem::acquireLock($lock);
            brendyTestAssert(is_resource($handle) && Filesystem::releaseLock($handle), 'Ordinary lock failed');
        } finally { brendyTestRemoveTree($dir); }
    },
    'SEO local images reject browser-normalized traversal' => static function () {
        foreach (array('../image.jpg','../image.jpg?v=1','images/%2e%2e/a.jpg','images/%2f/a.jpg','images/%5c/a.jpg','images/%252e/a.jpg','images/%ZZ.jpg','//example.test/a.jpg','?v=1','/images/a.jpg#fragment') as $url) {
            brendyTestAssert(Theme::seoUrl($url, true) === '', 'Invalid local SEO URL accepted: '.$url);
        }
        foreach (array('images/cover.jpg?v=1','/images/%C4%8D.jpg','images/a%20b.jpg') as $url) {
            brendyTestAssert(Theme::seoUrl($url, true) === $url, 'Valid local SEO URL rejected: '.$url);
        }
    },
    'SEO fixed home uses its own social cover' => static function () {
        $old = array();
        foreach (array('site','pages','page','content','WHERE_AM_I') as $key) $old[$key] = array(array_key_exists($key,$GLOBALS), $GLOBALS[$key] ?? null);
        try {
            $GLOBALS['site'] = new class { public function homepage() { return 'start'; } public function seoSocialImage() { throw new RuntimeException('Fallback should not be evaluated for a valid cover'); } };
            $GLOBALS['pages'] = new class { public function isPubliclyAccessible($key) { return true; } };
            $GLOBALS['page'] = new class { public function key() { return 'start'; } public function coverImage($absolute) { return 'https://example.test/home.jpg'; } };
            $GLOBALS['content'] = array(new class { public function coverImage($absolute) { return 'https://example.test/other.jpg'; } });
            $GLOBALS['WHERE_AM_I'] = 'home';
            $method = new ReflectionMethod(Theme::class, 'seoImageUrl');
            brendyTestAssert($method->invoke(null) === 'https://example.test/home.jpg', 'Fixed home cover lost');
        } finally { foreach ($old as $key=>$value) { if ($value[0]) $GLOBALS[$key]=$value[1]; else unset($GLOBALS[$key]); } }
    }
);
