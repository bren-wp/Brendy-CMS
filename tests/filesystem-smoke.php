<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return array(
    'filesystem atomic write round-trip' => static function () {
        $dir = brendyTestTempDir('brendy-atomic');
        try {
            $file = $dir . DS . 'payload.json';
            $payload = str_repeat('Brendy-', 320000);
            brendyTestAssert(Filesystem::atomicWrite($file, $payload, 0600), 'atomicWrite() failed.');
            $read = @file_get_contents($file);
            brendyTestAssert(is_string($read) && hash_equals(hash('sha256', $payload), hash('sha256', $read)), 'Atomic payload mismatch.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'filesystem lock contention' => static function () {
        $dir = brendyTestTempDir('brendy-lock');
        try {
            $path = $dir . DS . 'operation.lock';
            $first = Filesystem::acquireLock($path, LOCK_EX, 0600);
            brendyTestAssert(is_resource($first), 'First lock acquisition failed.');
            $second = Filesystem::acquireLock($path, LOCK_EX | LOCK_NB, 0600);
            brendyTestAssert($second === false, 'Non-blocking contention was not rejected.');
            brendyTestAssert(Filesystem::releaseLock($first), 'Lock release failed.');
            clearstatcache(true, $path);
            $mode = @fileperms($path);
            brendyTestAssert(is_int($mode) && (($mode & 0777) === 0600), 'Lock permissions are not 0600.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'filesystem size and symlink handling' => static function () {
        $dir = brendyTestTempDir('brendy-size');
        try {
            @mkdir($dir . DS . 'nested', 0700);
            file_put_contents($dir . DS . 'a.bin', str_repeat('a', 1234));
            file_put_contents($dir . DS . 'nested' . DS . 'b.bin', str_repeat('b', 4321));
            $expected = 5555;
            if (function_exists('symlink')) @symlink($dir . DS . 'a.bin', $dir . DS . 'nested' . DS . 'linked.bin');
            brendyTestAssert(Filesystem::getSize($dir) === $expected, 'Recursive size is incorrect or counted a symlink.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'filesystem date sorting is deterministic' => static function () {
        $dir = brendyTestTempDir('brendy-sort');
        try {
            file_put_contents($dir . DS . 'older.zip', 'old');
            file_put_contents($dir . DS . 'newer.zip', 'new');
            @touch($dir . DS . 'older.zip', 1000000000);
            @touch($dir . DS . 'newer.zip', 1100000000);
            $files = Filesystem::listFiles($dir . DS, '*', 'zip', true);
            brendyTestAssert(count($files) === 2 && basename($files[0]) === 'newer.zip', 'File date sorting is incorrect.');
            @mkdir($dir . DS . 'older-dir', 0700);
            @mkdir($dir . DS . 'newer-dir', 0700);
            @touch($dir . DS . 'older-dir', 1000000000);
            @touch($dir . DS . 'newer-dir', 1100000000);
            $dirs = Filesystem::listDirectories($dir . DS, '*', true);
            brendyTestAssert(count($dirs) === 2 && basename($dirs[0]) === 'newer-dir', 'Directory date sorting is incorrect.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'filesystem staged publication' => static function () {
        $dir = brendyTestTempDir('brendy-stage');
        try {
            $stage = Filesystem::temporaryFile($dir, '.stage-', 0600);
            brendyTestAssert(is_string($stage) && dirname($stage) === realpath($dir), 'temporaryFile() escaped target directory.');
            file_put_contents($stage, 'published');
            $dest = $dir . DS . 'final.txt';
            brendyTestAssert(Filesystem::publishStagedFile($stage, $dest, 0640), 'publishStagedFile() failed.');
            brendyTestAssert(@file_get_contents($dest) === 'published' && !file_exists($stage), 'Staged publication is not atomic/complete.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'filesystem bounded read fails closed' => static function () {
        $dir = brendyTestTempDir('brendy-bounded-read');
        try {
            $file = $dir . DS . 'payload.txt';
            file_put_contents($file, '0123456789');
            brendyTestAssert(Filesystem::readFileBounded($file, 10, 1) === '0123456789', 'Bounded read did not return the exact payload.');
            brendyTestAssert(Filesystem::readFileBounded($file, 9, 1) === false, 'Oversized bounded read was accepted.');
            brendyTestAssert(Filesystem::readFileBounded($file, 20, 11) === false, 'Undersized bounded read was accepted.');
            $snapshot = Filesystem::readFileSnapshotBounded($file, 20, 1);
            brendyTestAssert(is_array($snapshot) && ($snapshot['data'] ?? null) === '0123456789' && ($snapshot['size'] ?? null) === 10, 'Bounded snapshot did not bind data and size.');
            brendyTestAssert(isset($snapshot['mtime']) && is_int($snapshot['mtime']) && $snapshot['mtime'] > 0, 'Bounded snapshot metadata is incomplete.');
            if (function_exists('symlink')) {
                $link = $dir . DS . 'payload-link.txt';
                if (@symlink($file, $link)) brendyTestAssert(Filesystem::readFileBounded($link, 20, 1) === false, 'Symlink bounded read was accepted.');
            }
        } finally { brendyTestRemoveTree($dir); }
    },
    'filesystem rejects symlink lock path' => static function () {
        if (!function_exists('symlink')) return;
        $dir = brendyTestTempDir('brendy-symlink');
        try {
            $target = $dir . DS . 'target';
            file_put_contents($target, 'x');
            $link = $dir . DS . 'link.lock';
            if (!@symlink($target, $link)) return;
            brendyTestAssert(Filesystem::acquireLock($link, LOCK_EX | LOCK_NB, 0600) === false, 'Symlink lock path was accepted.');
        } finally { brendyTestRemoveTree($dir); }
    },

    'filesystem bounded hashes and copy stay exact' => static function () {
        $dir = brendyTestTempDir('brendy-hash-copy');
        try {
            $payload = "Brendy CMS\n" . str_repeat('0123456789abcdef', 8192);
            $source = $dir . DS . 'source.bin';
            $copy = $dir . DS . 'copy.bin';
            file_put_contents($source, $payload);
            $size = strlen($payload);
            $digests = Filesystem::hashFileDigestsBounded($source, array('md5', 'sha256'), $size, 1, $size);
            brendyTestAssert(is_array($digests), 'Bounded multi-hash failed.');
            brendyTestAssert(isset($digests['md5'], $digests['sha256']), 'Bounded multi-hash returned incomplete algorithms.');
            brendyTestAssert(hash_equals(hash('md5', $payload), $digests['md5']), 'Bounded MD5 mismatch.');
            brendyTestAssert(hash_equals(hash('sha256', $payload), $digests['sha256']), 'Bounded SHA-256 mismatch.');
            brendyTestAssert(Filesystem::hashFileBounded($source, 'sha256', $size, 1, $size + 1) === false, 'Wrong expected hash size was accepted.');
            brendyTestAssert(Filesystem::copyFileSafely($source, $copy, 0600), 'Safe file copy failed.');
            $copied = Filesystem::readFileBounded($copy, $size, $size);
            brendyTestAssert(is_string($copied) && hash_equals(hash('sha256', $payload), hash('sha256', $copied)), 'Safe copy payload mismatch.');
            clearstatcache(true, $copy);
            $mode = @fileperms($copy);
            brendyTestAssert(is_int($mode) && (($mode & 0777) === 0600), 'Safe copy permissions are not 0600.');
            if (function_exists('symlink')) {
                $link = $dir . DS . 'source-link.bin';
                if (@symlink($source, $link)) {
                    brendyTestAssert(Filesystem::hashFileBounded($link, 'sha256', $size, 1) === false, 'Symlink bounded hash was accepted.');
                    brendyTestAssert(Filesystem::copyFileSafely($link, $dir . DS . 'link-copy.bin') === false, 'Symlink source copy was accepted.');
                }
            }
        } finally { brendyTestRemoveTree($dir); }
    },

    'filesystem opened descriptor identity is enforced' => static function () {
        $dir = brendyTestTempDir('brendy-opened-descriptor');
        try {
            $file = $dir . DS . 'payload.bin';
            file_put_contents($file, 'original');
            $handle = fopen($file, 'rb');
            brendyTestAssert(is_resource($handle), 'Unable to open descriptor test file.');
            brendyTestAssert(Filesystem::openedFileMatchesPath($handle, $file, true), 'Opened descriptor did not match its pathname.');
            $replacement = $dir . DS . 'replacement.bin';
            file_put_contents($replacement, 'replaced');
            if (@rename($replacement, $file)) {
                brendyTestAssert(Filesystem::openedFileMatchesPath($handle, $file, true) === false, 'Replaced pathname still matched the old descriptor.');
            }
            fclose($handle);
        } finally { brendyTestRemoveTree($dir); }
    },

    'filesystem prefix snapshot stays bounded' => static function () {
        $dir = brendyTestTempDir('brendy-prefix-snapshot');
        try {
            $file = $dir . DS . 'payload.bin';
            $payload = str_repeat('abcdef0123456789', 4096);
            file_put_contents($file, $payload);
            $snapshot = Filesystem::readFilePrefixSnapshotBounded($file, 512, strlen($payload), 1);
            brendyTestAssert(is_array($snapshot), 'Prefix snapshot failed.');
            brendyTestAssert(($snapshot['size'] ?? null) === strlen($payload), 'Prefix snapshot reported the wrong complete size.');
            brendyTestAssert(($snapshot['data'] ?? null) === substr($payload, 0, 512), 'Prefix snapshot returned the wrong prefix bytes.');
            brendyTestAssert(Filesystem::readFilePrefixSnapshotBounded($file, 512, strlen($payload) - 1, 1) === false, 'Prefix snapshot accepted an oversized file.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'filesystem unique move retries collisions' => static function () {
        $dir = brendyTestTempDir('brendy-unique-move');
        try {
            file_put_contents($dir . DS . 'photo.jpg', 'existing');
            file_put_contents($dir . DS . 'photo_0.jpg', 'existing-zero');
            $source = $dir . DS . 'stage.bin';
            file_put_contents($source, 'new-image');
            $moved = Filesystem::moveToUniqueFilename($source, 'photo.jpg', $dir, 8);
            brendyTestAssert(is_string($moved) && basename($moved) === 'photo_1.jpg', 'Unique move did not choose the next collision-free filename.');
            brendyTestAssert(Filesystem::readFileBounded($moved, 64, 1) === 'new-image', 'Unique move payload mismatch.');
            brendyTestAssert(!file_exists($source), 'Unique move left its staging source behind.');
            brendyTestAssert(Filesystem::readFileBounded($dir . DS . 'photo.jpg', 64, 1) === 'existing', 'Unique move overwrote an existing file.');
        } finally { brendyTestRemoveTree($dir); }
    },

    'filesystem cache version changes on atomic replacement' => static function () {
        $dir = brendyTestTempDir('brendy-version-token');
        try {
            $file = $dir . DS . 'avatar.png';
            file_put_contents($file, 'AAAA');
            @touch($file, 1700000000);
            $first = Filesystem::fileVersionToken($file, 'fallback');
            brendyTestAssert(is_string($first) && $first !== '' && $first !== 'fallback', 'Initial file version token was not generated.');
            $stage = Filesystem::temporaryFile($dir, '.avatar-', 0600);
            brendyTestAssert(is_string($stage), 'Unable to allocate replacement staging file.');
            file_put_contents($stage, 'BBBB');
            @touch($stage, 1700000000);
            brendyTestAssert(Filesystem::publishStagedFile($stage, $file, 0644), 'Atomic replacement for version-token test failed.');
            @touch($file, 1700000000);
            $second = Filesystem::fileVersionToken($file, 'fallback');
            brendyTestAssert(is_string($second) && $second !== '' && $second !== 'fallback', 'Replacement file version token was not generated.');
            $stat = @lstat($file);
            if (is_array($stat) && isset($stat['ino']) && is_int($stat['ino']) && $stat['ino'] > 0) {
                brendyTestAssert(!hash_equals($first, $second), 'Same-second/same-size atomic replacement did not invalidate the cache version token.');
            }
            if (function_exists('symlink')) {
                $link = $dir . DS . 'avatar-link.png';
                if (@symlink($file, $link)) brendyTestAssert(Filesystem::fileVersionToken($link, 'fallback') === 'fallback', 'Symlink version token was accepted.');
            }
        } finally { brendyTestRemoveTree($dir); }
    },

    'staged and copied file permissions are verified' => static function () {
        $dir = brendyTestTempDir('brendy-mode-contract');
        try {
            $stage = Filesystem::temporaryFile($dir, '.stage-', 0600);
            brendyTestAssert(is_string($stage) && is_file($stage), 'Unable to allocate private staging file.');
            clearstatcache(true, $stage);
            brendyTestAssert((@fileperms($stage) & 0777) === 0600, 'Private staging mode was not enforced.');
            file_put_contents($stage, 'mode-test');
            $dest = $dir . DS . 'published.txt';
            brendyTestAssert(Filesystem::publishStagedFile($stage, $dest, 0640), 'Unable to publish staged file with explicit mode.');
            clearstatcache(true, $dest);
            brendyTestAssert((@fileperms($dest) & 0777) === 0640, 'Published file mode was not enforced.');
            $copy = $dir . DS . 'copy.txt';
            brendyTestAssert(Filesystem::copyFileSafely($dest, $copy, 0600), 'Unable to copy file with explicit mode.');
            clearstatcache(true, $copy);
            brendyTestAssert((@fileperms($copy) & 0777) === 0600, 'Copied file mode was not enforced.');
            brendyTestAssert(Filesystem::readFileBounded($copy, 16, 1) === 'mode-test', 'Copied file contents were altered.');
        } finally { brendyTestRemoveTree($dir); }
    },

);
