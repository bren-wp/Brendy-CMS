<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'pomocnici' . DS . 'dom.class.php';
if (!function_exists('mb_strlen')) {
    // ASCII-only fixtures below; actual Unicode normalization requires mbstring.
    function mb_strlen($value, $encoding = null) { return strlen($value); }
}
return array(
    'SEO plain text excludes hidden markup and preserves word boundaries' => static function () {
        brendyTestAssert(Theme::seoText('<p>First</p><p>Second</p><script>hidden()</script><style>.x{}</style>') === 'First Second', 'Hidden markup or merged words in SEO text.');
        brendyTestAssert(Theme::seoText('&lt;b&gt;Hello&lt;/b&gt;') === 'Hello', 'Encoded markup remained in SEO text.');
    },
    'SEO description falls back to content when manual description is blank' => static function () {
        $page = new class { public function description() { return '  '; } public function contentRaw() { return '<p>Useful description</p>'; } };
        brendyTestAssert(Theme::seoPageDescriptionText($page) === 'Useful description', 'Blank manual description prevented fallback.');
    },
    'SEO fixed home canonical and noindex apply at root and alias' => static function () {
        if (!defined('DOMAIN_BASE')) define('DOMAIN_BASE', 'https://example.test/sub/');
        $old = array();
        foreach (array('site','pages','page','url','WHERE_AM_I') as $key) $old[$key] = array(array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null);
        try {
            $GLOBALS['site'] = new class { public function homepage() { return 'start'; } };
            $GLOBALS['pages'] = new class { public function isPubliclyAccessible($key) { return true; } };
            $GLOBALS['page'] = new class {
                public function key() { return 'start'; }
                public function permalink($absolute = true) { return DOMAIN_BASE . 'start'; }
                public function noindex() { return true; }
                public function nofollow() { return false; }
                public function noarchive() { return false; }
            };
            $GLOBALS['url'] = new class { public function notFound() { return false; } };
            foreach (array('home','page') as $route) {
                $GLOBALS['WHERE_AM_I'] = $route;
                brendyTestAssert(Theme::canonicalUrl() === DOMAIN_BASE, 'Homepage canonical differs at ' . $route);
                brendyTestAssert(Theme::robotsDirectives() === array('noindex'), 'Homepage robots differs at ' . $route);
            }
            $other = new class { public function key() { return 'članak'; } public function permalink($absolute = true) { return DOMAIN_BASE . 'članak'; } };
            brendyTestAssert(Theme::canonicalPageUrl($other) === DOMAIN_BASE . '%C4%8Dlanak', 'Unicode page lost its canonical URL.');
            $GLOBALS['WHERE_AM_I'] = 'search';
            brendyTestAssert(Theme::canonicalUrl() === '' && Theme::robotsDirectives() === array('noindex','follow'), 'Search became indexable.');
        } finally {
            foreach ($old as $key => $value) { if ($value[0]) $GLOBALS[$key] = $value[1]; else unset($GLOBALS[$key]); }
        }
    },
    'SEO preferred image does not require parsing HTML' => static function () {
        brendyTestAssert(Theme::socialImageUrl('https://example.test/cover.jpg', '<img src="ignored.jpg">') === 'https://example.test/cover.jpg', 'Preferred image not selected.');
    },
    'network blocks non-global IP ranges' => static function () {
        foreach (array('127.0.0.1','100.64.0.1','169.254.169.254','::ffff:127.0.0.1','::1') as $ip) {
            brendyTestAssert(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false, 'Non-global IP accepted.');
        }
        $tcp = file_get_contents(dirname(__DIR__) . '/jezgra/pomocnici/tcp.class.php');
        brendyTestAssert(substr_count($tcp, "CURLOPT_PROXY, ''") === 2, 'Proxy bypass protection missing from request or download.');
    },
    'DOM image helper is fail-closed without invalid input' => static function () {
        brendyTestAssert(DOM::getFirstImage('') === false && DOM::getFirstImage(array()) === false, 'Invalid DOM input was accepted.');
        if (class_exists('DOMDocument')) {
            $previous = libxml_use_internal_errors(false);
            try {
                brendyTestAssert(DOM::getFirstImage('<img src=""><img src="a.jpg">') === 'a.jpg', 'Image selection failed.');
                brendyTestAssert(libxml_use_internal_errors() === false, 'DOM helper leaked global error state.');
            } finally { libxml_use_internal_errors($previous); }
        }
    },
    'API query credential rejection covers all methods' => static function () {
        $source = file_get_contents(dirname(__DIR__) . '/dodaci/api/plugin.php');
        brendyTestAssert(strpos($source, 'array_key_exists(\'token\', $_GET)') !== false && strpos($source, 'array_key_exists(\'authentication\', $_GET)') !== false, 'API credentials in query not guarded for POST/PUT.');
    },
    'Apache private storage and installer template share protection' => static function () {
        $root = dirname(__DIR__);
        $rule = 'RewriteRule ^(?:sadrzaj|brendy-content)(?:$|/(?!uploads(?:/|$))) - [R=404,L,NC]';
        foreach (array('.htaccess','install.php') as $file) {
            brendyTestAssert(strpos(file_get_contents($root . DS . $file), $rule) !== false, 'Private storage rule missing from ' . $file);
        }
    },
);
