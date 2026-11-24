<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/jezgra/apstraktno/brendy-plugin.class.php';
foreach (array('search','comments','gallery','api','navigation','backup-manager','categories','tags','sitemap') as $extension) {
    require_once $root . '/dodaci/' . $extension . '/plugin.php';
}
return array(
    'search visible text excludes hidden source and separates HTML blocks' => static function () {
        $html = '<p>One</p><p>Two &amp; three</p><noscript>hidden</noscript><template>hidden</template><script>hidden';
        brendyTestAssert(Text::visibleHtmlText($html) === 'One Two & three', 'Search visible text is incorrect.');
        brendyTestAssert(Text::visibleHtmlText('&lt;code&gt;&nbsp;sample') === '<code> sample', 'Visible literal markup or non-breaking space is lost.');
    },
    'search highlights raw text without splitting HTML entities' => static function () {
        $plugin = (new ReflectionClass(BrendyPluginSearch::class))->newInstanceWithoutConstructor();
        $plugin->init(); $plugin->db = array();
        brendyTestAssert($plugin->highlightTerms('A &amp; B', 'amp') === 'A &amp; B', 'An escaped HTML entity was highlighted.');
        brendyTestAssert($plugin->highlightTerms('A &amp; B', '&') === 'A <mark class="search-highlight">&amp;</mark> B', 'Ampersand match is broken.');
        brendyTestAssert($plugin->highlightTerms('&lt;img&gt;', 'img') === '&lt;<mark class="search-highlight">img</mark>&gt;', 'Raw HTML escaped incorrectly.');
    },
    'search metadata uses the requested query not the route name' => static function () {
        $old = $GLOBALS['url'] ?? null; $oldGet = $_GET;
        try {
            $method = new ReflectionMethod(Theme::class, 'searchTermText');
            $GLOBALS['url'] = new class { public function slug() { return 'search/'; } };
            $_GET = array('q' => 'digital CMS');
            brendyTestAssert($method->invoke(null) === 'digital CMS', 'Search title used route name.');
            $GLOBALS['url'] = new class { public function slug() { return 'search/other'; } };
            brendyTestAssert($method->invoke(null) === 'other', 'Path search term lost.');
        } finally { $GLOBALS['url'] = $old; $_GET = $oldGet; }
    },
    'search cache invalidates when the plugin or core is updated' => static function () {
        $source = file_get_contents(dirname(__DIR__) . '/dodaci/search/plugin.php');
        brendyTestAssert(strpos($source, "\$this->filenameDb, __FILE__, PATH_KERNEL . 'release.php'") !== false, 'Release dependency missing.');
    },
    'navigation refuses descendants of private pages before object construction' => static function () {
        $old = $GLOBALS['pages'] ?? null;
        try {
            $GLOBALS['pages'] = new class {
                public function isPubliclyAccessible($key) { return false; }
                public function exists($key) { return true; }
                public function getPageDB($key) { throw new RuntimeException('Private record accessed.'); }
            };
            $plugin = new class extends BrendyPlugin { public function __construct() {} public function render() { return $this->sidebarPageTree(array('private/child'), array('published')); } };
            brendyTestAssert($plugin->render() === '', 'Private child entered navigation.');
        } finally { $GLOBALS['pages'] = $old; }
    },
    'comments auto-link safely handles query strings and HTML' => static function () {
        $plugin = (new ReflectionClass(BrendyPluginComments::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BrendyPluginComments::class, 'formatContent');
        $output = $method->invoke($plugin, 'Visit https://example.test/?a=1&b=2. <script>');
        brendyTestAssert(strpos($output, 'href="https://example.test/?a=1&amp;b=2"') !== false, 'Query URL was corrupted.');
        brendyTestAssert(strpos($output, '&lt;script&gt;') !== false && strpos($output, '<script>') === false, 'Unsafe comment markup.');
        $output = $method->invoke($plugin, 'https://user@example.test/');
        brendyTestAssert(strpos($output, '<a ') === false, 'Credential-bearing URL was linked.');
    },
    'upload rejects executable extension variants and double extensions' => static function () {
        foreach (array('x.php74','x.php84.jpg','x.PHP123.png','x.svg.jpg','x.phtml.png') as $file) {
            brendyTestAssert(brendyHasDangerousExecutableFilename($file), 'Dangerous filename allowed: ' . $file);
        }
        brendyTestAssert(!brendyHasDangerousExecutableFilename('holiday-2026.jpg'), 'Safe image rejected.');
    },
    'gallery rejects dangerous legacy image names and removes duplicate entries' => static function () {
        $previous = $GLOBALS['ALLOWED_IMG_EXTENSION'] ?? null;
        try {
            $GLOBALS['ALLOWED_IMG_EXTENSION'] = array('jpg','png');
            $plugin = (new ReflectionClass(BrendyPluginGallery::class))->newInstanceWithoutConstructor();
            $plugin->db = array('galleries' => json_encode(array('photos' => array('name'=>'Photos','images'=>array('safe.jpg','safe.jpg','x.php74.jpg','../x.jpg')))));
            $method = new ReflectionMethod(BrendyPluginGallery::class, 'getGalleries');
            $galleries = $method->invoke($plugin);
            brendyTestAssert($galleries['photos']['images'] === array('safe.jpg'), 'Unsafe or duplicate gallery entries retained.');
        } finally { $GLOBALS['ALLOWED_IMG_EXTENSION'] = $previous; }
    },
    'API request parser rejects over-deep JSON and accepts ordinary input' => static function () {
        $plugin = (new ReflectionClass(BrendyPluginApi::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BrendyPluginApi::class, 'cleanInputs');
        brendyTestAssert($method->invoke($plugin, '{"title":"Cats & Dogs"}') === array('title'=>'Cats & Dogs'), 'API input changed.');
        $deep = '1'; for ($i = 0; $i < 12; $i++) $deep = '{"x":' . $deep . '}';
        brendyTestAssert($method->invoke($plugin, $deep) === array(), 'Over-deep request accepted.');
    },
);
