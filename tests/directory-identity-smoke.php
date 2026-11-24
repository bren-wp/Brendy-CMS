<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return array(
    'Directory rollback preserves a replaced empty directory' => static function () {
        $root = realpath(brendyTestTempDir('brendy-dir-replacement'));
        try {
            $child = $root . DS . 'child'; mkdir($child);
            $identity = new ReflectionMethod(Filesystem::class, 'directoryIdentity');
            $rollback = new ReflectionMethod(Filesystem::class, 'rollbackDirectoryOperation');
            $map = array($root => $identity->invoke(null, $root), $child => $identity->invoke(null, $child));
            rename($child, $root . DS . 'old'); mkdir($child);
            $rollback->invoke(null, array(), array($child => true), $map);
            brendyTestAssert(is_dir($child) && is_dir($root . DS . 'old'), 'Replacement directory removed');
        } finally { brendyTestRemoveTree($root); }
    },
    'Directory rollback rejects an unchanged child moved into a replacement ancestor' => static function () {
        $root = realpath(brendyTestTempDir('brendy-dir-ancestor'));
        try {
            $parent = $root . DS . 'parent'; $child = $parent . DS . 'child'; $file = $child . DS . 'file';
            mkdir($parent); mkdir($child); file_put_contents($file, 'owned');
            $identity = new ReflectionMethod(Filesystem::class, 'directoryIdentity');
            $map = array(); foreach (array($root, $parent, $child) as $p) $map[$p] = $identity->invoke(null, $p);
            $fileStat = lstat($file); rename($parent, $root . DS . 'old'); mkdir($parent);
            rename($root . DS . 'old' . DS . 'child', $child);
            $rollback = new ReflectionMethod(Filesystem::class, 'rollbackDirectoryOperation');
            $rollback->invoke(null, array($file => $fileStat), array($parent => true, $child => true), $map);
            brendyTestAssert(file_get_contents($file) === 'owned', 'File in replaced ancestor removed');
        } finally { brendyTestRemoveTree($root); }
    },
    'Directory rollback removes owned children but preserves existing parent' => static function () {
        $root = realpath(brendyTestTempDir('brendy-dir-owned'));
        try {
            $child = $root . DS . 'child'; $nested = $child . DS . 'nested'; mkdir($child); mkdir($nested);
            $file = $nested . DS . 'file'; file_put_contents($file, 'owned');
            $identity = new ReflectionMethod(Filesystem::class, 'directoryIdentity');
            $map = array(); foreach (array($root, $child, $nested) as $p) $map[$p] = $identity->invoke(null, $p);
            $rollback = new ReflectionMethod(Filesystem::class, 'rollbackDirectoryOperation');
            $rollback->invoke(null, array($file => lstat($file)), array($child => true, $nested => true), $map);
            brendyTestAssert(is_dir($root) && !file_exists($child), 'Owned tree not cleaned');
        } finally { brendyTestRemoveTree($root); }
    },
    'Extraction directory helper refuses previously tracked replacement' => static function () {
        $root = realpath(brendyTestTempDir('brendy-zip-dir'));
        try {
            $identity = new ReflectionMethod(Filesystem::class, 'directoryIdentity');
            $ensure = new ReflectionMethod(Filesystem::class, 'ensureExtractDirectory');
            $created = array(); $map = array($root => $identity->invoke(null, $root));
            brendyTestAssert($ensure->invokeArgs(null, array($root, 'one' . DS . 'two', &$created, &$map)), 'Creation failed');
            rename($root . DS . 'one', $root . DS . 'old'); mkdir($root . DS . 'one');
            brendyTestAssert(!$ensure->invokeArgs(null, array($root, 'one' . DS . 'new', &$created, &$map)), 'Replacement accepted');
            brendyTestAssert(!file_exists($root . DS . 'one' . DS . 'new'), 'Wrote into replacement');
        } finally { brendyTestRemoveTree($root); }
    },
    'Recursive copy rolls back on collision without changing existing data' => static function () {
        $root = realpath(brendyTestTempDir('brendy-copy-collision'));
        try {
            $src = $root . DS . 'src'; $dst = $root . DS . 'dst'; mkdir($src); mkdir($dst);
            mkdir($src . DS . 'nested'); file_put_contents($src . DS . 'nested' . DS . 'new', 'new');
            file_put_contents($src . DS . 'collision', 'source'); file_put_contents($dst . DS . 'collision', 'existing');
            brendyTestAssert(!Filesystem::copyRecursive($src, $dst), 'Collision accepted');
            brendyTestAssert(file_get_contents($dst . DS . 'collision') === 'existing' && !file_exists($dst . DS . 'nested'), 'Rollback did not preserve destination');
            unlink($dst . DS . 'collision');
            brendyTestAssert(Filesystem::copyRecursive($src, $dst) && file_get_contents($dst . DS . 'nested' . DS . 'new') === 'new', 'Valid copy failed');
        } finally { brendyTestRemoveTree($root); }
    },
);
