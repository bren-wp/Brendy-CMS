<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return array(
    'extension metadata matches release' => static function () {
        $root = dirname(__DIR__);
        $files = array_merge(glob($root . DS . 'dodaci' . DS . '*' . DS . 'metadata.json') ?: array(), glob($root . DS . 'teme' . DS . '*' . DS . 'metadata.json') ?: array());
        brendyTestAssert(count($files) === 14, 'Expected 14 bundled extension metadata files.');
        foreach ($files as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            brendyTestAssert(is_array($data), 'Invalid metadata JSON: ' . basename(dirname($file)));
            brendyTestAssert(($data['brendyRelease'] ?? null) === BRENDY_VERSION, 'Stale brendyRelease metadata: ' . basename(dirname($file)));
            brendyTestAssert(isset($data['compatible']) && is_string($data['compatible']) && $data['compatible'] === '2.0', 'Unexpected Extension API compatibility: ' . basename(dirname($file)));
        }
    },
    'ajax mutation guards remain present' => static function () {
        $files = glob(dirname(__DIR__) . DS . 'jezgra' . DS . 'ajax' . DS . '*.php') ?: array();
        brendyTestAssert(count($files) === 11, 'Unexpected AJAX endpoint count.');
        foreach ($files as $file) {
            $source = (string)file_get_contents($file);
            brendyTestAssert(strpos($source, 'requireAjaxMethod') !== false, 'Missing AJAX method guard: ' . basename($file));
            brendyTestAssert(strpos($source, 'requireAjaxRole') !== false, 'Missing AJAX role guard: ' . basename($file));
        }
    },
    'json files parse' => static function () {
        $root = dirname(__DIR__);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile() || strtolower($file->getExtension()) !== 'json') continue;
            $count++;
            json_decode((string)file_get_contents($file->getPathname()), true);
            brendyTestAssert(json_last_error() === JSON_ERROR_NONE, 'Invalid JSON: ' . $file->getPathname());
        }
        brendyTestAssert($count >= 30, 'Expected bundled JSON files were not found.');
    },
    'developer tests are web denied' => static function () {
        $root = dirname(__DIR__);
        $rules = (string)file_get_contents($root . DS . '.htaccess');
        brendyTestAssert(strpos($rules, 'RewriteRule ^tests(?:/|$)') !== false, 'Root .htaccess does not deny /tests/.');
        brendyTestAssert(strpos($rules, 'DEPLOYMENT') !== false && strpos($rules, 'README') !== false && strpos($rules, 'API') !== false && strpos($rules, 'RAZVOJ-TEMA-I-DODATAKA') !== false, 'Internal documentation is not fully denied by root .htaccess.');
        brendyTestAssert(is_file(__DIR__ . DS . '.htaccess'), 'tests/.htaccess defense-in-depth file is missing.');
    },
    'theme strict optional text contract' => static function () {
        brendyTestAssert(Theme::hasText('0') === true, 'Theme::hasText must preserve the valid text value "0".');
        brendyTestAssert(Theme::hasText('   ') === false, 'Whitespace-only optional text must remain empty.');
    },
    'public mobile navigation contract' => static function () {
        $root = dirname(__DIR__);
        $sharedCss = (string)file_get_contents($root . DS . 'jezgra' . DS . 'css' . DS . 'brendy-theme.css');
        brendyTestAssert(strpos($sharedCss, '[data-brendy-collapse-target]') !== false, 'Shared public collapse target contract is missing.');
        brendyTestAssert(strpos($sharedCss, 'min-width: 44px') !== false && strpos($sharedCss, '100dvh') !== false, 'Shared public mobile touch/viewport hardening is missing.');
        foreach (array('alternativa', 'blog', 'fokus', 'novosti', 'okus') as $theme) {
            $navigation = (string)file_get_contents($root . DS . 'teme' . DS . $theme . DS . 'php' . DS . 'navigacija.php');
            brendyTestAssert(strpos($navigation, 'data-brendy-collapse-target') !== false, 'Theme navigation lacks shared collapse contract: ' . $theme);
        }
    },
    'bounded core read contract' => static function () {
        $root = dirname(__DIR__);
        $db = (string)file_get_contents($root . DS . 'jezgra' . DS . 'apstraktno' . DS . 'brendy-json-database.class.php');
        brendyTestAssert(substr_count($db, 'Filesystem::readFileBounded') >= 3, 'Core JSON database is not consistently using bounded descriptor reads.');
        $functions = (string)file_get_contents($root . DS . 'jezgra' . DS . 'functions.php');
        brendyTestAssert(strpos($functions, 'function brendyParsePhpSourceFile') !== false, 'Shared bounded PHP source parser is missing.');
        brendyTestAssert(strpos($functions, 'Filesystem::readFileBounded($filename') !== false, 'Shared PHP source parser is not using bounded reads.');
    },
    'media manager resolves only requested page' => static function () {
        $root = dirname(__DIR__);
        $functions = (string)file_get_contents($root . DS . 'jezgra' . DS . 'functions.php');
        brendyTestAssert(strpos($functions, 'function mediaManagerImagePage') !== false, 'Paged Media Manager helper is missing.');
        $slicePos = strpos($functions, 'array_slice($files, $offset, $itemsPerPage)');
        $resolvePos = strpos($functions, 'mediaManagerResolveImageItems($pageFiles');
        brendyTestAssert($slicePos !== false && $resolvePos !== false && $slicePos < $resolvePos, 'Media Manager must slice before resolving thumbnails.');
        $ajax = (string)file_get_contents($root . DS . 'jezgra' . DS . 'ajax' . DS . 'list-images.php');
        brendyTestAssert(strpos($ajax, 'mediaManagerImagePage') !== false, 'AJAX Media Manager still uses the legacy full-list path.');
    },
    'theme decorative icons remain accessibility hidden' => static function () {
        $root = dirname(__DIR__) . DS . 'teme';
        foreach (glob($root . DS . '*' . DS . 'php' . DS . '*.php') ?: array() as $file) {
            $source = (string)file_get_contents($file);
            preg_match_all('/<i\b[^>]*class=["\'][^"\']*\bbi(?:\s|\-)[^"\']*["\'][^>]*>/i', $source, $matches);
            foreach ($matches[0] as $tag) {
                brendyTestAssert(stripos($tag, 'aria-hidden=') !== false || stripos($tag, 'aria-label=') !== false,
                    'Decorative Bootstrap icon lacks an accessibility contract: ' . $file);
            }
        }
    },
    'search and comments bounded admin contracts' => static function () {
        $root = dirname(__DIR__);
        $search = (string)file_get_contents($root . DS . 'dodaci' . DS . 'search' . DS . 'plugin.php');
        brendyTestAssert(strpos($search, 'array_slice($list, $offset, $itemsPerPage)') !== false, 'Search results are not using a bounded page slice.');
        $comments = (string)file_get_contents($root . DS . 'dodaci' . DS . 'comments' . DS . 'plugin.php');
        brendyTestAssert(substr_count($comments, "array_slice(\$db['comments'], 0, 2000, true)") >= 2, 'Comments admin scans must bound persisted comment arrays.');
        brendyTestAssert(strpos($comments, '$commentStatusCountsCache') !== false, 'Comments moderation status-count cache is missing.');
    },

    'core paginator window is bounded and deterministic' => static function () {
        brendyTestAssert(class_exists('Paginator', false), 'Paginator helper was not loaded by the test bootstrap.');
        brendyTestAssert(Paginator::window(5, 10, 2) === array(1, 3, 4, 5, 6, 7, 10), 'Unexpected paginator window around current page.');
        brendyTestAssert(Paginator::window(99, 3, 2) === array(1, 2, 3), 'Paginator did not clamp an out-of-range current page.');
        brendyTestAssert(Paginator::window(1, 1000001, 2) === array(), 'Paginator accepted an unsafe page count.');
    },
    'installer writes completion marker last' => static function () {
        $source = (string)file_get_contents(dirname(__DIR__) . DS . 'install.php');
        $integrity = strpos($source, "writeJsonDatabaseAtomic(DB_CORE_INTEGRITY");
        $marker = strpos($source, "writeJsonDatabaseAtomic(PATH_DATABASES . 'site.php'");
        brendyTestAssert($integrity !== false && $marker !== false && $integrity < $marker, 'Installer site.php marker is not the final required database artifact.');
        brendyTestAssert(strpos($source, '!is_int($size) || $size < 2 || $size > 2097152') !== false, 'Installer language size check is not fail-closed.');
    },
    'core integrity avoids pathname hash primitives' => static function () {
        $root = dirname(__DIR__) . DS . 'jezgra';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
            $source = (string)file_get_contents($file->getPathname());
            brendyTestAssert(strpos($source, 'hash_file(') === false, 'Raw hash_file() remains in runtime core: ' . $file->getPathname());
            brendyTestAssert(strpos($source, 'md5_file(') === false, 'Raw md5_file() remains in runtime core: ' . $file->getPathname());
        }
    },
    'taxonomy canonical list logic stays centralized' => static function () {
        $root = dirname(__DIR__) . DS . 'jezgra';
        $base = (string)file_get_contents($root . DS . 'apstraktno' . DS . 'brendy-list-database.class.php');
        $categories = (string)file_get_contents($root . DS . 'categories.class.php');
        $tags = (string)file_get_contents($root . DS . 'tags.class.php');
        brendyTestAssert(strpos($base, 'function canonicalPublicList') !== false, 'Shared canonical taxonomy list helper is missing.');
        brendyTestAssert(strpos($categories, 'canonicalPublicList($key, \'category\')') !== false, 'Categories are not using the shared canonical list helper.');
        brendyTestAssert(strpos($tags, 'canonicalPublicList($key, \'tags\')') !== false, 'Tags are not using the shared canonical list helper.');
    },


    'release documentation set is present and current' => static function () {
        $root = dirname(__DIR__);
        foreach (array('README.md', 'API.md', 'NADOGRADNJA.md', 'STRUKTURA.md', 'RAZVOJ-TEMA-I-DODATAKA.md', 'CONTRIBUTING.md', 'DEPLOYMENT.md', 'SECURITY.md', 'PROMJENE.md') as $file) {
            $path = $root . DS . $file;
            brendyTestAssert(is_file($path) && !is_link($path), 'Missing release documentation: ' . $file);
            $content = (string)file_get_contents($path);
            brendyTestAssert(strlen($content) > 100, 'Release documentation is unexpectedly empty: ' . $file);
        }
        $readme = (string)file_get_contents($root . DS . 'README.md');
        brendyTestAssert(strpos($readme, 'Brendy CMS ' . BRENDY_VERSION) !== false, 'README release version is stale.');
        $api = (string)file_get_contents($root . DS . 'API.md');
        brendyTestAssert(strpos($api, 'API dodatak:** ' . json_decode((string)file_get_contents($root . DS . 'dodaci' . DS . 'api' . DS . 'metadata.json'), true)['version']) !== false, 'API documentation plugin version is stale.');
    },
    'api capability metadata covers routed read resources' => static function () {
        $source = (string)file_get_contents(dirname(__DIR__) . DS . 'dodaci' . DS . 'api' . DS . 'plugin.php');
        foreach (array('/api/tags/{key}', '/api/categories/{key}', '/api/users/{username}') as $path) {
            brendyTestAssert(strpos($source, "'path' => '" . $path . "'") !== false, 'API capability metadata misses route: ' . $path);
        }
    },
    'generated XML uses descriptor snapshot contract' => static function () {
        $functions = (string)file_get_contents(dirname(__DIR__) . DS . 'jezgra' . DS . 'functions.php');
        $start = strpos($functions, 'function serveGeneratedXmlFile');
        $end = strpos($functions, 'function sendJsonResponse', $start === false ? 0 : $start);
        brendyTestAssert($start !== false && $end !== false && $end > $start, 'Generated XML responder was not found.');
        $body = substr($functions, $start, $end - $start);
        brendyTestAssert(strpos($body, 'Filesystem::readFileSnapshotBounded') !== false, 'Generated XML does not use one descriptor snapshot.');
        brendyTestAssert(strpos($body, '@readfile(') === false && strpos($body, 'readfile($file)') === false, 'Generated XML reopened the pathname with readfile().');
    },

    'uploaded-file commit remains staged and no-clobber' => static function () {
        $functions = (string)file_get_contents(dirname(__DIR__) . DS . 'jezgra' . DS . 'functions.php');
        $start = strpos($functions, 'function moveUploadedFileSafely');
        $end = strpos($functions, '// Add a new category to the system', $start === false ? 0 : $start);
        brendyTestAssert($start !== false && $end !== false && $end > $start, 'Safe upload helper was not found.');
        $body = substr($functions, $start, $end - $start);
        brendyTestAssert(strpos($body, 'Filesystem::temporaryFile') !== false, 'Upload helper does not stage inside the destination filesystem.');
        brendyTestAssert(strpos($body, 'Filesystem::mv($staging, $destination)') !== false, 'Upload helper does not use the no-clobber filesystem promotion path.');
        brendyTestAssert(strpos($body, 'return move_uploaded_file($temporaryFile, $destination)') === false, 'Upload helper can still overwrite the final destination directly.');
    },
    'image validator rejects path-like original names' => static function () {
        $functions = (string)file_get_contents(dirname(__DIR__) . DS . 'jezgra' . DS . 'functions.php');
        $start = strpos($functions, 'function validateUploadedImage');
        $end = strpos($functions, 'function brendySanitizeContentHtml', $start === false ? 0 : $start);
        brendyTestAssert($start !== false && $end !== false && $end > $start, 'Image upload validator was not found.');
        $body = substr($functions, $start, $end - $start);
        brendyTestAssert(strpos($body, 'basename($name) !== $name') !== false, 'Image validator does not reject path-like original names.');
        brendyTestAssert(strpos($body, "strpos(\$name, '/') !== false") !== false && strpos($body, "strpos(\$name, '\\\\') !== false") !== false, 'Image validator does not reject both path separator styles.');
    },
    'language databases enforce the 2 MiB store limit' => static function () {
        $root = dirname(__DIR__);
        $language = (string)file_get_contents($root . DS . 'jezgra' . DS . 'language.class.php');
        $functions = (string)file_get_contents($root . DS . 'jezgra' . DS . 'functions.php');
        $installer = (string)file_get_contents($root . DS . 'install.php');
        brendyTestAssert(substr_count($language, 'new BrendyJsonDatabase($filename, false, 2097152)') >= 2, 'Runtime language loader does not enforce the 2 MiB database limit.');
        brendyTestAssert(strpos($language, 'new BrendyJsonDatabase($file, false, 2097152)') !== false, 'Runtime language discovery does not enforce the 2 MiB database limit.');
        brendyTestAssert(strpos($functions, "new BrendyJsonDatabase(PATH_LANGUAGES . \$args['language'] . '.json', false, 2097152)") !== false, 'Language settings validation does not enforce the 2 MiB database limit.');
        brendyTestAssert(strpos($installer, 'new BrendyJsonDatabase($file, false, 2097152)') !== false, 'Installer language discovery does not enforce the 2 MiB database limit.');
    },
    'restricted downloads stay on one descriptor' => static function () {
        $functions = (string)file_get_contents(dirname(__DIR__) . DS . 'jezgra' . DS . 'functions.php');
        $start = strpos($functions, 'function downloadRestrictedFile');
        $end = strpos($functions, 'function brendySanitizeContentHtml', $start === false ? 0 : $start);
        brendyTestAssert($start !== false && $end !== false && $end > $start, 'Restricted download helper was not found.');
        $body = substr($functions, $start, $end - $start);
        brendyTestAssert(strpos($body, 'Filesystem::openedFileMatchesPath') !== false, 'Restricted download does not bind metadata to its descriptor.');
        brendyTestAssert(strpos($body, '@fread($handle') !== false, 'Restricted download does not stream from its opened descriptor.');
        brendyTestAssert(strpos($body, 'readfile(') === false, 'Restricted download still reopens the pathname through readfile().');
    },

    'attachment validator uses descriptor snapshots' => static function () {
        $functions = (string)file_get_contents(dirname(__DIR__) . DS . 'jezgra' . DS . 'functions.php');
        $start = strpos($functions, 'function validateUploadedAttachment');
        $end = strpos($functions, 'function validateUploadedImage', $start === false ? 0 : $start);
        brendyTestAssert($start !== false && $end !== false && $end > $start, 'Attachment validator was not found.');
        $body = substr($functions, $start, $end - $start);
        brendyTestAssert(strpos($body, 'Filesystem::readFilePrefixSnapshotBounded') !== false, 'Large attachment magic-byte validation is not descriptor-bound.');
        brendyTestAssert(strpos($body, 'Filesystem::readFileSnapshotBounded($file, 8388608, 1)') !== false, 'JSON attachment validation is not using one bounded full snapshot.');
        brendyTestAssert(strpos($body, '@fopen($file') === false && strpos($body, '@fread($handle') === false, 'Attachment validator still performs an ad-hoc pathname reopen.');
    },
    'api attachment upload uses bounded unique commit' => static function () {
        $source = (string)file_get_contents(dirname(__DIR__) . DS . 'dodaci' . DS . 'api' . DS . 'plugin.php');
        brendyTestAssert(strpos($source, 'moveUploadedFileUniquely($_FILES[\'file\'][\'tmp_name\'], $filename, $uploadDirectory, 16)') !== false,
            'API attachment upload is not using the bounded unique commit helper.');
    },

    'system health reuses the full-tree extension scanner' => static function () {
        $source = (string)file_get_contents(dirname(__DIR__) . DS . 'jezgra' . DS . 'administracija' . DS . 'pogledi' . DS . 'system-health.php');
        brendyTestAssert(strpos($source, 'brendyValidateExtensionPhpTreeSecurity($extensionDirectory') !== false,
            'System Health is not using the same full-tree extension scanner as runtime activation.');
        brendyTestAssert(strpos($source, "Scanned extension packages") !== false,
            'System Health extension scanner detail was not updated for package-level scans.');
    },
    'extension lifecycle entry points are serialized' => static function () {
        $source = (string)file_get_contents(dirname(__DIR__) . DS . 'jezgra' . DS . 'functions.php');
        foreach (array('installBrendyExtensionArchive', 'deleteBrendyExtension', 'activateTheme', 'activatePlugin', 'deactivatePlugin', 'removeBrendyPluginState') as $function) {
            $start = strpos($source, 'function ' . $function . '(');
            brendyTestAssert($start !== false, 'Missing extension lifecycle entry point: ' . $function);
            $body = substr($source, $start, 700);
            brendyTestAssert(strpos($body, 'brendyAcquireExtensionMutationLock()') !== false,
                'Extension lifecycle entry point is not serialized: ' . $function);
        }
        brendyTestAssert(strpos($source, "DateTimeImmutable::createFromFormat('!Y-m-d', \$metadata['releaseDate']);\n  \$releaseDate = DateTimeImmutable::createFromFormat") === false,
            'Duplicate metadata release-date parse returned.');
        brendyTestAssert(strpos($source, "\$generatedMtime = @filemtime(\$file);\n  \$generatedMtime = @filemtime(\$file);") === false,
            'Duplicate generated artifact filemtime() call returned.');
    },

    'browser asset versioning uses one metadata token helper' => static function () {
        $root = dirname(__DIR__);
        $site = (string)file_get_contents($root . DS . 'jezgra' . DS . 'site.class.php');
        $user = (string)file_get_contents($root . DS . 'jezgra' . DS . 'user.class.php');
        $profile = (string)file_get_contents($root . DS . 'jezgra' . DS . 'ajax' . DS . 'profile-picture-upload.php');
        brendyTestAssert(strpos($site, 'Filesystem::fileVersionToken($path') !== false, 'Site logo URL is not using shared metadata versioning.');
        brendyTestAssert(strpos($user, 'Filesystem::fileVersionToken($path') !== false, 'Profile picture URL is not using shared metadata versioning.');
        brendyTestAssert(strpos($profile, 'Filesystem::fileVersionToken($profilePath') !== false, 'Profile upload response is not using shared metadata versioning.');
    },


    'theme companion plugins resolve by directory and rollback stays coherent' => static function () {
        $previousPlugins = $GLOBALS['plugins'] ?? null;
        $previousCatalog = $GLOBALS['pluginsCatalog'] ?? null;
        try {
            $fixture = new class {
                public function directoryName() { return 'companion-test'; }
                public function runtimeReady() { return true; }
                public function installed() { return true; }
            };
            $GLOBALS['plugins'] = array('all' => array('BrendyPluginCompanionTest' => $fixture));
            $GLOBALS['pluginsCatalog'] = array();
            brendyTestAssert(brendyPluginClassNameForDirectory('companion-test', false) === 'BrendyPluginCompanionTest',
                'Companion plugin class was not resolved from its directory slug.');
            brendyTestAssert(getPluginByDirectory('companion-test') === $fixture,
                'Active companion plugin could not be retrieved by theme directory.');

            $pluginRule = (string)file_get_contents(dirname(__DIR__) . DS . 'jezgra' . DS . 'pokretanje' . DS . 'pravila' . DS . '60.plugins.php');
            $themeRule = (string)file_get_contents(dirname(__DIR__) . DS . 'jezgra' . DS . 'pokretanje' . DS . 'pravila' . DS . '99.themes.php');
            $functions = (string)file_get_contents(dirname(__DIR__) . DS . 'jezgra' . DS . 'functions.php');
            brendyTestAssert(strpos($pluginRule, 'brendyPluginClassNameForDirectory($themeDirectory, true)') !== false,
                'Inactive theme companion load target still assumes directory == PHP class.');
            brendyTestAssert(strpos($themeRule, 'getPluginByDirectory($site->theme())') !== false,
                'Theme runtime is not resolving its active companion by directory.');
            brendyTestAssert(strpos($functions, '$currentPluginClass = brendyPluginClassNameForDirectory($currentTheme, false);') !== false,
                'Theme activation does not resolve the current companion class by directory.');
            brendyTestAssert(strpos($functions, 'if (!$themeRolledBack)') !== false &&
                strpos($functions, 'preserving the new companion plugin state') !== false,
                'Theme rollback does not preserve the new companion when the theme setting itself cannot roll back.');
        } finally {
            if ($previousPlugins === null) unset($GLOBALS['plugins']); else $GLOBALS['plugins'] = $previousPlugins;
            if ($previousCatalog === null) unset($GLOBALS['pluginsCatalog']); else $GLOBALS['pluginsCatalog'] = $previousCatalog;
        }
    },


    'theme activation validates default language before lifecycle commit' => static function () {
        $dir = brendyTestTempDir('brendy-theme-language');
        try {
            $valid = $dir . DS . 'valid.json';
            $invalid = $dir . DS . 'invalid.json';
            $missingData = $dir . DS . 'missing-theme-data.json';
            file_put_contents($valid, json_encode(array('theme-data' => array('name' => 'Fixture', 'description' => 'OK'))));
            file_put_contents($invalid, '{bad json');
            file_put_contents($missingData, json_encode(array('hello' => 'world')));
            brendyTestAssert(is_array(brendyReadThemeLanguageFile($valid)), 'Valid theme language file was rejected.');
            brendyTestAssert(brendyReadThemeLanguageFile($invalid) === false, 'Malformed theme language JSON was accepted.');
            brendyTestAssert(brendyReadThemeLanguageFile($missingData) === false, 'Theme language file without theme-data was accepted.');

            $source = (string)file_get_contents(dirname(__DIR__) . DS . 'jezgra' . DS . 'functions.php');
            $languageCheck = strpos($source, 'brendyReadThemeLanguageFile($defaultLanguageFile) === false');
            $installHook = strpos($source, '$installFile = $themeRoot . \'install.php\';');
            brendyTestAssert($languageCheck !== false && $installHook !== false && $languageCheck < $installHook,
                'Theme default language validation must run before the executable install hook.');
        } finally { brendyTestRemoveTree($dir); }
    },

    'theme installer explicit failure prevents lifecycle commit' => static function () {
        $root = dirname(__DIR__);
        $source = (string)file_get_contents($root . DS . 'jezgra' . DS . 'functions.php');
        $hookPos = strpos($source, '$installResult = include_once($installFile);');
        $rejectPos = strpos($source, 'if ($installResult === false)', $hookPos !== false ? $hookPos : 0);
        $commitPos = strpos($source, '$site->set(array(\'theme\' => $themeDirectory))', $hookPos !== false ? $hookPos : 0);
        brendyTestAssert($hookPos !== false && $rejectPos !== false && $commitPos !== false && $hookPos < $rejectPos && $rejectPos < $commitPos,
            'A failed theme install.php result is not rejected before theme commit.');
        $dir = brendyTestTempDir('brendy-theme-hook');
        try {
            $reject = $dir . DS . 'reject.php';
            $accept = $dir . DS . 'accept.php';
            file_put_contents($reject, "<?php return false;\n");
            file_put_contents($accept, "<?php return true;\n");
            brendyTestAssert((include $reject) === false, 'PHP include does not signal an explicitly failed theme hook.');
            brendyTestAssert((include $accept) === true, 'PHP include does not preserve explicit successful hook output.');
        } finally { brendyTestRemoveTree($dir); }
    },

);
