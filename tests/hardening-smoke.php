<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'pomocnici' . DS . 'core-integrity.class.php';
return array(
    'extension guard requires immediate exit' => static function () {
        $dir = brendyTestTempDir('brendy-guard');
        try {
            $file = $dir . DS . 'plugin.php';
            file_put_contents($file, "<?php if (!defined('BRENDY')) { exit; } echo 'ok';");
            brendyTestAssert(brendyValidateExtensionPhpSecurity($file), 'Valid guard rejected');
            file_put_contents($file, "<?php if (!defined('BRENDY')) { if (false) exit; } echo 'ok';");
            brendyTestAssert(!brendyValidateExtensionPhpSecurity($file), 'Conditional guard accepted');
        } finally { brendyTestRemoveTree($dir); }
    },
    'runtime extension scanner rejects server controls and compound executable names' => static function () {
        $dir = brendyTestTempDir('brendy-controls');
        try {
            file_put_contents($dir . DS . 'plugin.php', "<?php defined('BRENDY') or die; echo 'ok';");
            brendyTestAssert(brendyValidateExtensionPhpTreeSecurity($dir), 'Clean tree rejected');
            foreach (array('.user.ini', '.htaccess', 'php.ini', 'image.php.jpg', 'tool.phtml') as $name) {
                file_put_contents($dir . DS . $name, 'fixture');
                brendyTestAssert(!brendyValidateExtensionPhpTreeSecurity($dir), 'Unsafe file accepted: ' . $name);
                unlink($dir . DS . $name);
            }
        } finally { brendyTestRemoveTree($dir); }
    },
    'integrity baseline detects and refuses to bless unexpected root scripts' => static function () {
        $dir = brendyTestTempDir('brendy-root-integrity');
        try {
            mkdir($dir . DS . 'jezgra');
            file_put_contents($dir . DS . 'index.php', 'fixture');
            file_put_contents($dir . DS . 'jezgra' . DS . 'core.php', 'fixture');
            $key = str_repeat('a', 64);
            $baseline = CoreIntegrity::createPayload($dir, $dir . DS . 'jezgra', 1, $key);
            brendyTestAssert(is_array($baseline), 'Clean baseline failed');
            file_put_contents($dir . DS . 'unexpected.php', 'fixture');
            $result = CoreIntegrity::verifyPayload($baseline, $dir, $dir . DS . 'jezgra', $key);
            brendyTestAssert(!$result['ok'] && in_array('unexpected.php', $result['unexpected'], true), 'Unexpected script not detected');
            brendyTestAssert(CoreIntegrity::createPayload($dir, $dir . DS . 'jezgra', 2, $key) === false, 'Unexpected script was trusted');
        } finally { brendyTestRemoveTree($dir); }
    },
    'extension scanner rejects compound PHP names and unsafe directories' => static function () {
        $dir = brendyTestTempDir('brendy-extension-names');
        try {
            $php = "<?php defined('BRENDY') or die; echo 'ok';";
            file_put_contents($dir . DS . 'plugin.php', $php);
            file_put_contents($dir . DS . 'helper.class.php', $php);
            brendyTestAssert(brendyValidateExtensionPhpTreeSecurity($dir), 'Ordinary PHP class filename rejected');
            foreach (array('file.phtml.php', 'file.php8.php', 'file.phar.php') as $name) {
                file_put_contents($dir . DS . $name, $php);
                brendyTestAssert(!brendyValidateExtensionPhpTreeSecurity($dir), 'Compound executable accepted: ' . $name);
                unlink($dir . DS . $name);
            }
            mkdir($dir . DS . '.hidden');
            brendyTestAssert(!brendyValidateExtensionPhpTreeSecurity($dir), 'Hidden empty directory accepted');
            rmdir($dir . DS . '.hidden');
            if (function_exists('posix_mkfifo') && posix_mkfifo($dir . DS . 'pipe', 0600)) {
                brendyTestAssert(!brendyValidateExtensionPhpTreeSecurity($dir), 'Special file accepted');
                unlink($dir . DS . 'pipe');
            }
        } finally { brendyTestRemoveTree($dir); }
    },
    'core integrity requires public entrypoint for a fresh baseline' => static function () {
        $dir = brendyTestTempDir('brendy-core-entry');
        try {
            mkdir($dir . DS . 'jezgra');
            mkdir($dir . DS . 'jezgra' . DS . 'empty');
            file_put_contents($dir . DS . 'jezgra' . DS . 'core.php', 'fixture');
            $key = str_repeat('b', 64);
            brendyTestAssert(CoreIntegrity::createPayload($dir, $dir . DS . 'jezgra', 1, $key) === false, 'Missing entrypoint accepted');
            file_put_contents($dir . DS . 'index.php', 'fixture');
            $baseline = CoreIntegrity::createPayload($dir, $dir . DS . 'jezgra', 1, $key);
            brendyTestAssert(is_array($baseline), 'Ordinary empty directory rejected');
            unlink($dir . DS . 'index.php');
            $result = CoreIntegrity::verifyPayload($baseline, $dir . DS, $dir . DS . 'jezgra', $key);
            brendyTestAssert(!$result['ok'] && in_array('index.php', $result['missing'], true), 'Deleted entrypoint not reported');
        } finally { brendyTestRemoveTree($dir); }
    }

);
