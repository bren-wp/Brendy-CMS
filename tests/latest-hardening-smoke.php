<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return array(
    'Owned cleanup rejects replaced files and hard links' => static function () {
        $dir = brendyTestTempDir('brendy-owned-cleanup');
        try {
            $path = $dir . '/file'; file_put_contents($path, 'original'); $stat = lstat($path);
            rename($path, $dir . '/old'); file_put_contents($path, 'replacement');
            $method = new ReflectionMethod(Filesystem::class, 'removeOwnedRegularFile');
            brendyTestAssert(!$method->invoke(null, $path, $stat), 'Replacement removed');
            brendyTestAssert(file_get_contents($path) === 'replacement', 'Replacement changed');
            clearstatcache(true, $path); $stat = lstat($path);
            if (@link($path, $dir . '/alias')) {
                brendyTestAssert(!$method->invoke(null, $path, $stat), 'Hard-linked entry removed'); unlink($dir . '/alias');
            }
            brendyTestAssert($method->invoke(null, $path, $stat), 'Owned cleanup failed');
        } finally { brendyTestRemoveTree($dir); }
    },
    'Copy preserves explicit private mode and caller umask' => static function () {
        $dir = brendyTestTempDir('brendy-private-copy'); $old = umask(0000);
        try {
            file_put_contents($dir . '/source', str_repeat('a', 65536));
            brendyTestAssert(Filesystem::copyFileSafely($dir . '/source', $dir . '/dest', 0600), 'Copy failed');
            clearstatcache(true, $dir . '/dest');
            brendyTestAssert((fileperms($dir . '/dest') & 0777) === 0600, 'Copy has wrong permissions');
            brendyTestAssert(umask() === 0000, 'Caller umask changed');
            brendyTestAssert(!Filesystem::copyFileSafely($dir . '/source', $dir . '/dest', 0600), 'Existing file overwritten');
            brendyTestAssert(umask() === 0000, 'Caller umask changed on failure');
        } finally { umask($old); brendyTestRemoveTree($dir); }
    },
    'Lock has verified final requested permissions' => static function () {
        $dir = brendyTestTempDir('brendy-lock-mode');
        try {
            $path = $dir . '/lock'; file_put_contents($path, ''); chmod($path, 0666);
            $handle = Filesystem::acquireLock($path, LOCK_EX, 0600);
            brendyTestAssert(is_resource($handle), 'Lock unavailable');
            try { clearstatcache(true, $path); brendyTestAssert((fileperms($path) & 0777) === 0600, 'Mode incorrect'); }
            finally { Filesystem::releaseLock($handle); }
        } finally { brendyTestRemoveTree($dir); }
    },
    'Bundled admin templates use modern event methods' => static function () {
        $dir = dirname(__DIR__) . '/jezgra/administracija';
        brendyTestAssert(!str_contains(file_get_contents(dirname(__DIR__) . '/install.php'), '$(document).ready('), 'Legacy installer event');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            $s = file_get_contents($f->getPathname());
            foreach (array('$(document).ready(', '$(window).click(', '$("#jsform").submit(', 'appendTo("body").submit(') as $pattern)
                brendyTestAssert(!str_contains($s, $pattern), 'Legacy jQuery event: ' . $f->getFilename());
        }
    },
    'Public head computes description once while preserving public helpers' => static function () {
        $source = file_get_contents(dirname(__DIR__) . '/jezgra/pomocnici/theme.class.php');
        $body = substr($source, strpos($source, 'public static function publicHead('));
        $body = substr($body, 0, strpos($body, 'private static function safeCssClassList'));
        brendyTestAssert(substr_count($body, 'self::seoDescriptionText()') === 1 && str_contains($body, 'self::renderSeoHead($description)'), 'SEO description recomputation');
        $render = new ReflectionMethod(Theme::class, 'renderMetaDescription');
        brendyTestAssert(str_contains($render->invoke(null, 'a "quote"'), '&quot;'), 'Meta description escaping broken');
    },
);
