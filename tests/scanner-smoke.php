<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'functions.php';

return array(
    'extension scanner rejects fully-qualified blocked calls' => static function () {
        $dir = brendyTestTempDir('brendy-scanner-fq');
        try {
            $safe = $dir . DS . 'safe.php';
            $exec = $dir . DS . 'exec.php';
            $reflection = $dir . DS . 'reflection.php';
            file_put_contents($safe, "<?php defined('BRENDY') or die('Brendy CMS.'); echo 'safe';\n");
            file_put_contents($exec, "<?php defined('BRENDY') or die('Brendy CMS.'); \\exec('id');\n");
            file_put_contents($reflection, "<?php defined('BRENDY') or die('Brendy CMS.'); new \\ReflectionFunction('exec');\n");
            brendyTestAssert(brendyValidateExtensionPhpSecurity($safe) === true, 'Scanner rejected a safe guarded source file.');
            brendyTestAssert(brendyValidateExtensionPhpSecurity($exec) === false, 'Fully-qualified exec() bypassed the extension scanner.');
            brendyTestAssert(brendyValidateExtensionPhpSecurity($reflection) === false, 'Fully-qualified ReflectionFunction bypassed the extension scanner.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'extension scanner rejects blocked import aliases' => static function () {
        $dir = brendyTestTempDir('brendy-scanner-alias');
        try {
            $file = $dir . DS . 'alias.php';
            file_put_contents($file, "<?php defined('BRENDY') or die('Brendy CMS.'); use function exec as run; run('id');\n");
            brendyTestAssert(brendyValidateExtensionPhpSecurity($file) === false, 'Blocked function import alias bypassed the extension scanner.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'extension scanner rejects direct dynamic blocked callables' => static function () {
        $dir = brendyTestTempDir('brendy-scanner-dynamic-direct');
        try {
            $safe = $dir . DS . 'safe.php';
            $direct = $dir . DS . 'direct.php';
            $parenthesized = $dir . DS . 'parenthesized.php';
            file_put_contents($safe, <<<'PHP'
<?php defined('BRENDY') or die('Brendy CMS.'); $fn = function ($value) { return $value; }; $fn('ok');
PHP
            );
            file_put_contents($direct, "<?php defined('BRENDY') or die('Brendy CMS.'); 'exec'('id');\n");
            file_put_contents($parenthesized, "<?php defined('BRENDY') or die('Brendy CMS.'); ('exec')('id');\n");
            brendyTestAssert(brendyValidateExtensionPhpSecurity($safe) === true,
                'Scanner rejected an ordinary local closure callback.');
            brendyTestAssert(brendyValidateExtensionPhpSecurity($direct) === false,
                'Direct string callable exec() bypassed the extension scanner.');
            brendyTestAssert(brendyValidateExtensionPhpSecurity($parenthesized) === false,
                'Parenthesized string callable exec() bypassed the extension scanner.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'extension scanner tracks blocked callable variables across control-flow blocks' => static function () {
        $dir = brendyTestTempDir('brendy-scanner-dynamic-variable');
        try {
            $assigned = $dir . DS . 'assigned.php';
            $branch = $dir . DS . 'branch.php';
            $propagated = $dir . DS . 'propagated.php';
            $separateScopes = $dir . DS . 'separate-scopes.php';
            file_put_contents($assigned, <<<'PHP'
<?php defined('BRENDY') or die('Brendy CMS.'); $fn = 'exec'; $fn('id');
PHP
            );
            file_put_contents($branch, <<<'PHP'
<?php defined('BRENDY') or die('Brendy CMS.'); if (true) { $fn = 'exec'; } $fn('id');
PHP
            );
            file_put_contents($propagated, <<<'PHP'
<?php defined('BRENDY') or die('Brendy CMS.'); $fn = 'exec'; $other = $fn; $other('id');
PHP
            );
            file_put_contents($separateScopes, <<<'PHP'
<?php defined('BRENDY') or die('Brendy CMS.'); function unsafeNameOnly() { $fn = 'exec'; } function safeCallback() { $fn = function ($value) { return $value; }; return $fn('ok'); }
PHP
            );
            brendyTestAssert(brendyValidateExtensionPhpSecurity($assigned) === false,
                'Statically assigned blocked callable variable bypassed the extension scanner.');
            brendyTestAssert(brendyValidateExtensionPhpSecurity($branch) === false,
                'Blocked callable taint was lost when a control-flow block closed.');
            brendyTestAssert(brendyValidateExtensionPhpSecurity($propagated) === false,
                'Blocked callable taint propagation through a variable bypassed the scanner.');
            brendyTestAssert(brendyValidateExtensionPhpSecurity($separateScopes) === true,
                'Blocked callable taint leaked into a separate function scope.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'extension scanner cache is bound to build and policy' => static function () {
        $key = str_repeat("K", 32);
        $entries = array(
            str_repeat('a', 64) => array(
                'treeHash' => str_repeat('b', 64),
                'files' => 1,
                'bytes' => 123,
                'checkedAt' => time()
            )
        );
        $context = brendyExtensionSecurityCacheContext();
        $signature = brendyExtensionSecurityCacheSignature($entries, $key);
        brendyTestAssert(is_string($signature) && strlen($signature) === 64, 'Unable to sign extension scanner cache fixture.');
        $payload = array(
            'version' => $context['version'],
            'core' => $context['core'],
            'build' => $context['build'],
            'policy' => $context['policy'],
            'entries' => $entries,
            'hmac' => $signature
        );
        $validated = brendyValidateExtensionSecurityCachePayload($payload, $key);
        brendyTestAssert(is_array($validated) && count($validated) === 1, 'Current-build scanner cache was rejected.');

        $legacy = $payload;
        $legacy['version'] = 1;
        unset($legacy['build']);
        brendyTestAssert(brendyValidateExtensionSecurityCachePayload($legacy, $key) === false,
            'Legacy scanner cache generation remained trusted after policy hardening.');

        $staleBuild = $payload;
        $staleBuild['build'] = max(0, $context['build'] - 1);
        brendyTestAssert(brendyValidateExtensionSecurityCachePayload($staleBuild, $key) === false,
            'Scanner cache from a different Brendy build remained trusted.');

        $stalePolicy = $payload;
        $stalePolicy['policy'] = max(0, $context['policy'] - 1);
        brendyTestAssert(brendyValidateExtensionSecurityCachePayload($stalePolicy, $key) === false,
            'Scanner cache from an older source-policy revision remained trusted.');
    },
    'generated cache treats equal dependency mtimes as stale' => static function () {
        $dir = brendyTestTempDir('brendy-generated-mtime');
        try {
            $generated = $dir . DS . 'cache.json';
            $dependency = $dir . DS . 'pages.php';
            file_put_contents($generated, '{}');
            file_put_contents($dependency, '{}');
            $stamp = max(1000000000, time() - 60);
            touch($generated, $stamp);
            touch($dependency, $stamp);
            clearstatcache(true, $generated);
            clearstatcache(true, $dependency);
            brendyTestAssert(generatedFileNeedsRefresh($generated, array($dependency)) === true,
                'Equal-second dependency update was incorrectly treated as an ordered fresh cache.');

            touch($generated, $stamp + 1);
            clearstatcache(true, $generated);
            brendyTestAssert(generatedFileNeedsRefresh($generated, array($dependency)) === false,
                'A strictly newer generated artifact was incorrectly marked stale.');
        } finally { brendyTestRemoveTree($dir); }
    },
    'bundled extensions pass the full-tree runtime scanner' => static function () {
        $root = dirname(__DIR__);
        $directories = array_merge(
            glob($root . DS . 'dodaci' . DS . '*', GLOB_ONLYDIR) ?: array(),
            glob($root . DS . 'teme' . DS . '*', GLOB_ONLYDIR) ?: array()
        );
        sort($directories, SORT_STRING);
        brendyTestAssert(count($directories) === 14, 'Expected 14 bundled extension directories.');
        $phpFiles = 0;
        foreach ($directories as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $item) {
                if ($item->isFile() && preg_match('/\.(?:php[0-9]*|pht|phtml|phar|inc|module|install)$/Di', $item->getFilename()) === 1) $phpFiles++;
            }
            brendyTestAssert(brendyValidateExtensionPhpTreeSecurity($directory) === true,
                'Bundled extension failed full-tree scanner: ' . basename($directory));
        }
        brendyTestAssert($phpFiles >= 30, 'Unexpectedly small bundled executable PHP set.');
    },

    'extension lifecycle lock is reentrant and excludes parallel mutation' => static function () {
        $dir = brendyTestTempDir('brendy-extension-lifecycle-lock');
        if (defined('PATH_TMP')) return;
        define('PATH_TMP', $dir . DS);
        try {
            brendyTestAssert(brendyAcquireExtensionMutationLock() === true, 'Unable to acquire the extension lifecycle lock.');
            brendyTestAssert(brendyAcquireExtensionMutationLock() === true, 'Extension lifecycle lock is not request-reentrant.');
            $parallel = Filesystem::acquireLock(PATH_TMP . 'extension-mutation.lock', LOCK_EX | LOCK_NB, 0600);
            brendyTestAssert($parallel === false, 'Parallel extension mutation lock unexpectedly succeeded.');
            brendyTestAssert(brendyReleaseExtensionMutationLock() === true, 'Nested extension lifecycle release failed.');
            $stillBlocked = Filesystem::acquireLock(PATH_TMP . 'extension-mutation.lock', LOCK_EX | LOCK_NB, 0600);
            brendyTestAssert($stillBlocked === false, 'Nested release prematurely unlocked extension lifecycle state.');
            brendyTestAssert(brendyReleaseExtensionMutationLock() === true, 'Final extension lifecycle release failed.');
            $after = Filesystem::acquireLock(PATH_TMP . 'extension-mutation.lock', LOCK_EX | LOCK_NB, 0600);
            brendyTestAssert(is_resource($after), 'Extension lifecycle lock was not released after the outermost scope.');
            Filesystem::releaseLock($after);
        } finally {
            while (isset($GLOBALS['_brendyExtensionMutationLockDepth']) && (int)$GLOBALS['_brendyExtensionMutationLockDepth'] > 0) {
                brendyReleaseExtensionMutationLock();
            }
            brendyTestRemoveTree($dir);
        }
    },


);
