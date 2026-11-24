<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return array(
    'move rejects multiply linked source without changing either entry' => static function () {
        $dir = brendyTestTempDir('brendy-move-owner');
        try {
            $source = $dir . '/source'; $alias = $dir . '/alias'; $target = $dir . '/target';
            file_put_contents($source, 'owned');
            if (!link($source, $alias)) throw new RuntimeException('Cannot test hardlinks on this filesystem');
            brendyTestAssert(!Filesystem::mv($source, $target), 'Aliased source was moved');
            brendyTestAssert(file_get_contents($source) === 'owned' && file_get_contents($alias) === 'owned' && !file_exists($target), 'Move altered aliased data');
        } finally { brendyTestRemoveTree($dir); }
    },
    'copy returns only the identity of its created destination' => static function () {
        $dir = brendyTestTempDir('brendy-copy-owner');
        try {
            $source = $dir . '/source'; $target = $dir . '/target';
            file_put_contents($source, 'owned'); $identity = null;
            $method = new ReflectionMethod(Filesystem::class, 'copyFileWithIdentity');
            brendyTestAssert($method->invokeArgs(null, array($source, $target, 0600, &$identity)), 'Copy failed');
            brendyTestAssert(is_array($identity) && $identity['ino'] === lstat($target)['ino'] && $identity['size'] === 5, 'Wrong copied identity');
            brendyTestAssert(!$method->invokeArgs(null, array($source, $target, 0600, &$identity)) && $identity === null, 'Failed copy exposed stale identity');
        } finally { brendyTestRemoveTree($dir); }
    },
    'archive rollback preserves replacement entries' => static function () {
        $dir = brendyTestTempDir('brendy-rollback-owner');
        try {
            $target = $dir . '/target'; $other = $dir . '/other';
            file_put_contents($target, 'original'); $identity = lstat($target);
            rename($target, $other); file_put_contents($target, 'replacement');
            $method = new ReflectionMethod(Filesystem::class, 'rollbackDirectoryOperation');
            $identityMethod = new ReflectionMethod(Filesystem::class, 'directoryIdentity');
            $directories = array($dir => $identityMethod->invoke(null, $dir));
            $method->invoke(null, array($target => $identity), array(), $directories);
            brendyTestAssert(file_get_contents($target) === 'replacement' && file_get_contents($other) === 'original', 'Rollback deleted replacement');
            $method->invoke(null, array($other => $identity), array(), $directories);
            brendyTestAssert(!file_exists($other), 'Owned entry was not removed');
        } finally { brendyTestRemoveTree($dir); }
    },
    'lock creation restores permissive caller umask' => static function () {
        $dir = brendyTestTempDir('brendy-lock-mask'); $old = umask(0000);
        try {
            $handle = Filesystem::acquireLock($dir . '/lock');
            brendyTestAssert(is_resource($handle), 'Lock failed');
            try { brendyTestAssert((fileperms($dir . '/lock') & 0777) === 0600 && umask() === 0000, 'Lock permissions or umask wrong'); }
            finally { Filesystem::releaseLock($handle); }
        } finally { umask($old); brendyTestRemoveTree($dir); }
    },
    '404 title is distinct and safely escaped' => static function () {
        $old = array(); foreach (array('site','url','L') as $key) $old[$key] = $GLOBALS[$key] ?? null;
        try {
            $GLOBALS['site'] = new class { function title() { return 'Site <demo>'; } };
            $GLOBALS['url'] = new class { function notFound() { return true; } };
            $GLOBALS['L'] = new class { function g($key) { return 'Stranica nije pronađena'; } };
            brendyTestAssert(Theme::metaTagTitle() === '<title>Stranica nije pronađena | Site &lt;demo&gt;</title>' . PHP_EOL, 'Invalid 404 title');
        } finally { foreach ($old as $k=>$v) $GLOBALS[$k]=$v; }
    },
);
