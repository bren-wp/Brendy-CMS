<?php defined('BRENDY') or die('Brendy CMS.');

class Theme
{
	private static function escapeStoredText($value)
	{
		$flags = ENT_QUOTES | ENT_SUBSTITUTE;
		if (defined('ENT_HTML5')) $flags |= ENT_HTML5;
		$value = is_scalar($value) ? (string)$value : '';
		return htmlspecialchars(html_entity_decode($value, $flags, CHARSET), $flags, CHARSET);
	}

	public static function escape($value)
	{
		return self::escapeStoredText($value);
	}

	// PHP truthiness treats the valid text value "0" as empty. Use one strict
	// rule across bundled themes whenever optional user text is rendered.
	public static function hasText($value)
	{
		return is_scalar($value) && trim((string)$value) !== '';
	}

	private static function safeRelativeAsset($file, $extension = '')
	{
		if (!is_string($file) || $file === '' || strlen($file) > 255 || preg_match('/[\x00-\x1F\x7F]/', $file)) return false;
		if ($file[0] === '/' || strpos($file, '\\') !== false || strpos($file, '//') !== false) return false;
		$segments = explode('/', $file);
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $segment) !== 1) return false;
		}
		if ($extension !== '') {
			$extension = ltrim(strtolower((string)$extension), '.');
			if ($extension === '' || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== $extension) return false;
		}
		return true;
	}

	private static function scriptAttributes($attributes)
	{
		if (!is_string($attributes)) return '';
		$allowed = array('async', 'defer', 'nomodule', 'type="module"', "type='module'");
		$tokens = preg_split('/\s+/', trim($attributes));
		if (!is_array($tokens)) return '';
		$safe = array();
		foreach (array_slice($tokens, 0, 5) as $token) {
			if ($token === '') continue;
			if (!in_array($token, $allowed, true)) return '';
			if (!in_array($token, $safe, true)) $safe[] = $token;
		}
		return implode(' ', $safe);
	}

	public static function socialNetworks()
	{
		global $site;
		$socialNetworks = array(
			'github' => 'Github',
			'gitlab' => 'GitLab',
			'twitter' => 'Twitter',
			'facebook' => 'Facebook',
			'instagram' => 'Instagram',
			'codepen' => 'Codepen',
			'linkedin' => 'Linkedin',
			'xing' => 'Xing',
			'telegram' => 'Telegram',
			'mastodon' => 'Mastodon',
			'vk' => 'VK',
			'dribbble' => 'Dribbble',
			'youtube' => 'Youtube',
			'bluesky' => 'Bluesky'
		);

		foreach ($socialNetworks as $key => $label) {
			$value = $site->{$key}();
			if (!is_string($value) || self::safePublicUrl($value) === '') {
				unset($socialNetworks[$key]);
			}
		}
		return $socialNetworks;
	}

	public static function socialIcon($network)
	{
		$icons = array(
			'github' => 'bi-github', 'gitlab' => 'bi-code-slash', 'twitter' => 'bi-twitter',
			'facebook' => 'bi-facebook', 'instagram' => 'bi-instagram', 'codepen' => 'bi-code-slash',
			'linkedin' => 'bi-linkedin', 'telegram' => 'bi-telegram', 'mastodon' => 'bi-chat-dots',
			'youtube' => 'bi-youtube', 'dribbble' => 'bi-circle', 'bluesky' => 'bi-cloud',
			'xing' => 'bi-people', 'vk' => 'bi-chat-dots'
		);
		return is_string($network) && isset($icons[$network]) ? $icons[$network] : 'bi-link-45deg';
	}

	public static function title()
	{
		global $site;
		return $site->title();
	}

	public static function description()
	{
		global $site;
		return $site->description();
	}

	public static function slogan()
	{
		global $site;
		return $site->slogan();
	}

	public static function footer()
	{
		global $site;
		return $site->footer();
	}

	/**
	 * Shared, theme-neutral design layer used by every bundled Brendy theme.
	 * Values come from strictly validated Site fields before reaching CSS.
	 */
	public static function brendyDesignSystem()
	{
		global $site;

		$accent = $site->accentColor();
		if (!is_string($accent) || preg_match('/^#[0-9A-Fa-f]{6}$/D', $accent) !== 1) $accent = '#2563eb';
		$widths = array('compact' => '860px', 'wide' => '1180px', 'full' => '1440px');
		$contentWidth = $site->contentWidth();
		$width = isset($widths[$contentWidth]) ? $widths[$contentWidth] : $widths['wide'];
		$scales = array('compact' => '0.95', 'comfortable' => '1', 'large' => '1.075');
		$typography = $site->typographyScale();
		$fontScale = isset($scales[$typography]) ? $scales[$typography] : $scales['comfortable'];
		$radius = $site->roundedCards() ? '1rem' : '0.25rem';

		$output = '<meta name="application-name" content="' . self::escape(BRENDY_NAME) . '">' . PHP_EOL;
		$output .= '<meta name="theme-color" content="' . self::escapeStoredText($accent) . '">' . PHP_EOL;
		$output .= '<meta name="color-scheme" content="light dark">' . PHP_EOL;
		$output .= self::cssBootstrapIcons();
		$output .= '<link rel="stylesheet" type="text/css" href="' . DOMAIN_CORE_CSS .
			'brendy-theme.css?version=' . rawurlencode(BRENDY_BUILD) . '">' . PHP_EOL;
		$output .= '<style id="brendy-design-tokens">:root{' .
			'--brendy-accent:' . $accent . ';' .
			'--sadrzaj-width:' . $width . ';' .
			'--brendy-font-scale:' . $fontScale . ';' .
			'--brendy-card-radius:' . $radius .
			'}</style>' . PHP_EOL;
		return $output;
	}

	public static function brendyHtmlAttributes()
	{
		global $site;
		$scheme = $site->colorScheme();
		if (!in_array($scheme, array('auto', 'light', 'dark'), true)) $scheme = 'auto';
		return 'data-brendy-color-scheme="' . self::escapeStoredText($scheme) . '"';
	}

	public static function brendyBodyClass($classes = '')
	{
		global $site;
		$tokens = is_string($classes) ? preg_split('/\s+/', trim($classes)) : array();
		$safe = array('brendy-theme', $site->roundedCards() ? 'brendy-rounded' : 'brendy-square');
		$cardStyle = $site->cardStyle();
		if (!in_array($cardStyle, array('elevated', 'outlined', 'flat'), true)) $cardStyle = 'elevated';
		$animationLevel = $site->animationLevel();
		if (!in_array($animationLevel, array('full', 'subtle', 'off'), true)) $animationLevel = 'subtle';
		$typography = $site->typographyScale();
		if (!in_array($typography, array('compact', 'comfortable', 'large'), true)) $typography = 'comfortable';
		$safe[] = 'brendy-cards-' . $cardStyle;
		$safe[] = 'brendy-motion-' . $animationLevel;
		$safe[] = 'brendy-type-' . $typography;
		if (!$site->showPostMeta()) $safe[] = 'brendy-hide-post-meta';
		if (is_array($tokens)) {
			foreach (array_slice($tokens, 0, 30) as $token) {
				if (is_string($token) && preg_match('/^[A-Za-z][A-Za-z0-9_:.-]{0,95}$/D', $token) === 1) $safe[] = $token;
			}
		}
		return self::escapeStoredText(implode(' ', array_values(array_unique($safe))));
	}

	private static function readingProgressPreferences()
	{
		global $site;
		return array(
			'position' => $site->readingProgressPosition(),
			'height' => $site->readingProgressHeight(),
			'color' => $site->readingProgressColor()
		);
	}

	public static function brendyFrontendScripts()
	{
		global $site, $L;
		$config = array(
			'colorScheme' => $site->colorScheme(),
			'showReadingProgress' => $site->showReadingProgress(),
			'readingProgress' => self::readingProgressPreferences(),
			'showBackToTop' => $site->showBackToTop(),
			'lazyLoadImages' => $site->enableImageLazyLoading(),
			'animationLevel' => $site->animationLevel(),
			'labels' => array(
				'backToTop' => $L->g('Back to top'),
				'useLightTheme' => $L->g('Use light theme'),
				'useDarkTheme' => $L->g('Use dark theme'),
				'share' => $L->g('Share'),
				'copyLink' => $L->g('Copy link'),
				'linkCopied' => $L->g('Link copied'),
				'print' => $L->g('Print'),
				'searchMinimum' => $L->g('Please enter at least %d characters to search')
			)
		);
		$json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		if (!is_string($json)) $json = '{}';
		return '<script type="application/json"' . brendyCspNonceAttribute() . ' id="brendy-frontend-config">' . $json . '</script>' . PHP_EOL .
			'<script defer src="' . DOMAIN_CORE_JS . 'brendy-theme.js?version=' . rawurlencode(BRENDY_BUILD) . '"></script>' . PHP_EOL;
	}

	public static function brendyArticleTools($page)
	{
		global $site, $L;
		if (!is_object($page) || !method_exists($page, 'permalink') ||
			(!$site->showArticleTools() && !$site->showReadingTime())) return '';

		$title = method_exists($page, 'title') ? $page->title() : '';
		$url = $page->permalink(true);
		$output = '<aside class="brendy-article-tools" aria-label="' . self::escapeStoredText($L->g('Article tools')) . '">';
		if ($site->showReadingTime() && method_exists($page, 'readingTime')) {
			$output .= '<span class="brendy-reading-time"><i class="bi bi-clock" aria-hidden="true"></i>' .
				self::escapeStoredText($page->readingTime()) . '</span>';
		}
		if ($site->showArticleTools()) {
			$output .= '<div class="brendy-article-tools__actions">';
			$output .= '<button type="button" data-brendy-share data-share-title="' . self::escapeStoredText($title) .
				'" data-share-url="' . self::escapeStoredText($url) . '"><i class="bi bi-share" aria-hidden="true"></i><span>' .
				self::escapeStoredText($L->g('Share')) . '</span></button>';
			$output .= '<button type="button" data-brendy-print><i class="bi bi-printer" aria-hidden="true"></i><span>' .
				self::escapeStoredText($L->g('Print')) . '</span></button>';
			$output .= '</div>';
		}
		return $output . '</aside>' . PHP_EOL;
	}

	public static function brendyRelatedContent($page, $limit = 3)
	{
		global $site, $L;
		if (!$site->showRelatedContent() || !is_object($page) || !method_exists($page, 'related')) return '';
		$limit = max(1, min(6, (int)$limit));
		try {
			$keys = $page->related();
		} catch (Throwable $e) {
			return '';
		}
		if (!is_array($keys) || empty($keys)) return '';

		$cards = '';
		foreach (array_slice($keys, 0, $limit) as $key) {
			if (!is_string($key) || $key === '') continue;
			try {
				$related = new Page($key);
			} catch (Throwable $e) {
				continue;
			}
			$relatedDescription = $related->description();
			$cards .= '<article class="brendy-related-card brendy-card">' .
				'<div class="brendy-related-card__icon"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></div>' .
				'<div><h3><a href="' . self::escapeStoredText($related->permalink()) . '">' .
				self::escapeStoredText($related->title()) . '</a></h3>' .
				'<p class="brendy-meta"><i class="bi bi-calendar3" aria-hidden="true"></i>' .
				self::escapeStoredText($related->date()) . '</p>' .
				(self::hasText($relatedDescription) ? '<p>' . self::escapeStoredText($relatedDescription) . '</p>' : '') .
				'</div></article>';
		}
		if ($cards === '') return '';
		return '<section class="brendy-related" aria-labelledby="brendy-related-title">' .
			'<div class="brendy-section-heading"><i class="bi bi-link-45deg" aria-hidden="true"></i><h2 id="brendy-related-title">' .
			self::escapeStoredText($L->g('Related pages')) . '</h2></div><div class="brendy-related-grid">' .
			$cards . '</div></section>' . PHP_EOL;
	}

	public static function lang()
	{
		global $language;
		return $language->currentLanguageShortVersion();
	}


	public static function sitemapUrl()
	{
		if (pluginActivated('BrendyPluginSitemap')) {
			return DOMAIN_BASE . 'sitemap.xml';
		}
		return false;
	}

	// Returns the absolute URL of the site
	// Ex. https://example.com the method returns https://example.com/
	// Ex. https://example.com/brendy/ returns https://example.com/brendy/
	public static function siteUrl()
	{
		return DOMAIN_BASE;
	}

	// Returns the absolute URL of admin panel
	// Ex. https://example.com/admin/ the method returns https://example.com/admin/
	// Ex. https://example.com/brendy/admin/ returns https://example.com/brendy/admin/
	public static function adminUrl()
	{
		return DOMAIN_ADMIN;
	}

	public static function metaTags($tag)
	{
		if ($tag === 'title') {
			return self::metaTagTitle();
		} elseif ($tag === 'description') {
			return self::metaTagDescription();
		}
		return '';
	}

	public static function metaTagTitle()
	{
		global $url;
		global $site;
		global $L;
		global $WHERE_AM_I;
		global $page;

		if (is_object($url) && $url->notFound()) {
			$label = is_object($L) ? $L->g('Page not found') : 'Page not found';
			return '<title>' . self::escapeStoredText($label . ' | ' . $site->title()) . '</title>' . PHP_EOL;
		}
		$format = $site->titleFormatHomepage();
		if ($WHERE_AM_I === 'page') {
			$format = $site->titleFormatPages();
			$format = Text::replace('{{page-title}}', $page->title(), $format);
			$format = Text::replace('{{page-description}}', $page->description(), $format);
		} elseif ($WHERE_AM_I === 'tag') {
			try {
				$tagKey = $url->slug();
				$tag = new Tag($tagKey);
				$format = $site->titleFormatTag();
				$format = Text::replace('{{tag-name}}', $tag->name(), $format);
			} catch (Throwable $e) {
				// Tag doesn't exist
			}
		} elseif ($WHERE_AM_I === 'category') {
			try {
				$categoryKey = $url->slug();
				$category = new Category($categoryKey);
				$format = $site->titleFormatCategory();
				$format = Text::replace('{{category-name}}', $category->name(), $format);
			} catch (Throwable $e) {
				// Category doesn't exist
			}
		} elseif ($WHERE_AM_I === 'search') {
			$term = self::searchTermText();
			$format = $term === '' ? $site->title() : $L->g('Search') . ': ' . $term . ' | ' . $site->title();
		} else {
			$format = $site->titleFormatHomepage();
		}

		$format = Text::replace('{{site-title}}', $site->title(), $format);
		$format = Text::replace('{{site-slogan}}', $site->slogan(), $format);
		$format = Text::replace('{{site-description}}', $site->description(), $format);
		$pageNumber = max(1, (int)$url->pageNumber());
		if ($pageNumber > 1 && in_array($WHERE_AM_I, array('home', 'blog', 'category', 'tag', 'search'), true)) {
			$format .= ' — ' . $L->g('Page') . ' ' . $pageNumber;
		}

		return '<title>' . self::escapeStoredText($format) . '</title>' . PHP_EOL;
	}

	public static function metaTagDescription()
	{
		return self::renderMetaDescription(self::seoDescriptionText());
	}

	private static function renderMetaDescription($description)
	{
		return '<meta name="description" content="' . self::escapeStoredText($description) . '">' . PHP_EOL;
	}

	private static function seoPlainText($value, $maxLength = 200, $decodeStoredText = true)
	{
		$value = is_scalar($value) ? (string)$value : '';
		if ($decodeStoredText) $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, CHARSET);
		$value = Text::visibleHtmlText($value);
		$maxLength = max(1, min(1000, (int)$maxLength));
		if (mb_strlen($value, CHARSET) > $maxLength) {
			$value = rtrim(mb_substr($value, 0, $maxLength - 1, CHARSET)) . '…';
		}
		return $value;
	}

	private static function searchTermText()
	{
		$plugin = function_exists('getPluginByDirectory') ? getPluginByDirectory('search') : false;
		if (is_object($plugin) && method_exists($plugin, 'getSearchTerm')) {
			return self::seoPlainText($plugin->getSearchTerm(), 80);
		}
		global $url;
		$slug = is_object($url) && method_exists($url, 'slug') ? trim((string)$url->slug(), '/') : '';
		$term = $slug === 'search' ? '' : (strpos($slug, 'search/') === 0 ? substr($slug, 7) : $slug);
		if ($term === '' && isset($_GET['q']) && is_string($_GET['q'])) $term = $_GET['q'];
		return self::seoPlainText($term, 80);
	}

	private static function safePublicUrl($value)
	{
		if (!is_string($value)) return '';
		// Preserve valid escapes and encode non-ASCII path/query bytes for Unicode slugs.
		$value = preg_replace_callback('/[^\x00-\x7F]+/', static function ($match) { return rawurlencode($match[0]); }, $value);
		return brendyIsSafeHttpUrl($value) ? $value : '';
	}

	// Shared SEO normalization used by the core and social-meta plugins. Keeping
	// these rules in one place prevents Open Graph/Twitter escaping drift.
	public static function seoText($value, $maxLength = 200)
	{
		return self::seoPlainText($value, $maxLength);
	}

	public static function seoUrl($value, $allowRelative = false)
	{
		if (!is_string($value) || $value === '' || strlen($value) > 4096) return '';
		$value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, CHARSET));
		if ($value === '' || preg_match('/[\x00-\x20\x7F]/', $value)) return '';
		$parts = parse_url($value);
		if ($parts === false || isset($parts['user']) || isset($parts['pass'])) return '';
		if (!empty($parts['scheme'])) return self::safePublicUrl($value);
		if (!$allowRelative || strncmp($value, '//', 2) === 0 || strpos($value, '\\') !== false ||
			!isset($parts['path']) || $parts['path'] === '' || isset($parts['fragment'])) return '';
		// Inspect the path separately from the query. Browsers normalize encoded
		// dot segments too; reject those and encoded separators in local paths.
		$path = $parts['path'];
		if (preg_match('~%(?![0-9a-f]{2})|%(?:2f|5c|00|25)~i', $path) ||
			preg_match('~(?:^|/)\.{1,2}(?:/|$)~', rawurldecode($path)) === 1 ||
			preg_match('/[\x00-\x1F\x7F]/', rawurldecode($path))) return '';
		// Relative URLs are allowed only for local social-image discovery. Dot
		// traversal segments are rejected so resolution stays inside the site URL.
		return preg_match('#^(?:/|[A-Za-z0-9_~%-])#D', $value) === 1 ? $value : '';
	}

	public static function seoPageDescriptionText($pageObject, $maxLength = 200)
	{
		if (!is_object($pageObject)) return self::seoDescriptionText();
		$description = self::seoPlainText(method_exists($pageObject, 'description') ? $pageObject->description() : '', $maxLength);
		if ($description === '' && method_exists($pageObject, 'contentRaw')) {
			return self::seoPlainText($pageObject->contentRaw(), $maxLength, false);
		}
		return $description;
	}

	private static function absoluteSeoImageUrl($value)
	{
		$value = self::seoUrl($value, true);
		if ($value === '') return '';
		if (brendyIsSafeHttpUrl($value)) return $value;
		if (preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $value) === 1) return '';

		$base = defined('DOMAIN_BASE') ? self::safePublicUrl(DOMAIN_BASE) : '';
		if ($base === '') return '';
		if ($value[0] === '/') {
			$parts = parse_url($base);
			if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
			$origin = strtolower((string)$parts['scheme']) . '://' . $parts['host'];
			if (isset($parts['port']) && is_int($parts['port'])) $origin .= ':' . $parts['port'];
			$candidate = $origin . $value;
		} else {
			$candidate = rtrim($base, '/') . '/' . ltrim($value, '/');
		}
		return brendyIsSafeHttpUrl($candidate) ? $candidate : '';
	}

	public static function socialImageUrl($preferred = '', $html = '', $fallback = '')
	{
		$preferredUrl = self::absoluteSeoImageUrl($preferred);
		if ($preferredUrl !== '') return $preferredUrl;
		$candidates = array();
		if (is_string($html) && $html !== '') {
			$discovered = DOM::getFirstImage($html);
			if (is_string($discovered) && $discovered !== '') $candidates[] = $discovered;
		}
		$candidates[] = $fallback;
		foreach ($candidates as $candidate) {
			$url = self::absoluteSeoImageUrl($candidate);
			if ($url !== '') return $url;
		}
		return '';
	}

	public static function seoTitleText()
	{
		global $site, $url, $WHERE_AM_I, $page;
		if (is_object($page) && ($WHERE_AM_I === 'page' || ($WHERE_AM_I === 'home' && self::isConfiguredHome($page)))) {
			$title = self::seoPlainText($page->title(), 120);
			if ($title !== '') return $title;
		}
		if ($WHERE_AM_I === 'category') {
			try { return self::seoPlainText((new Category($url->slug()))->name(), 120); } catch (Throwable $e) {}
		}
		if ($WHERE_AM_I === 'tag') {
			try { return self::seoPlainText((new Tag($url->slug()))->name(), 120); } catch (Throwable $e) {}
		}
		if ($WHERE_AM_I === 'search') {
			$term = self::searchTermText();
			if ($term !== '') return self::seoPlainText($term . ' — ' . $site->title(), 120);
		}
		return self::seoPlainText($site->title(), 120);
	}

	public static function seoDescriptionText()
	{
		global $site, $url, $WHERE_AM_I, $page;
		$fallback = self::seoPlainText($site->description(), 180);
		if (is_object($page) && ($WHERE_AM_I === 'page' || ($WHERE_AM_I === 'home' && self::isConfiguredHome($page)))) {
			$description = self::seoPageDescriptionText($page, 180);
			return $description !== '' ? $description : $fallback;
		}
		if ($WHERE_AM_I === 'category') {
			try {
				$description = self::seoPlainText((new Category($url->slug()))->description(), 180);
				if ($description !== '') return $description;
			} catch (Throwable $e) {}
		}
		return $fallback;
	}

	private static function isConfiguredHome($pageObject)
	{
		global $site, $pages;
		return is_object($pageObject) && method_exists($pageObject, 'key') &&
			is_object($site) && $site->homepage() === $pageObject->key() &&
			is_object($pages) && $pages->isPubliclyAccessible($pageObject->key());
	}

	public static function canonicalPageUrl($pageObject)
	{
		if (!is_object($pageObject) || !method_exists($pageObject, 'permalink')) return '';
		return self::safePublicUrl(self::isConfiguredHome($pageObject) ? DOMAIN_BASE : $pageObject->permalink(true));
	}

	public static function canonicalUrl()
	{
		global $url, $WHERE_AM_I, $page;
		if (!empty($GLOBALS['BRENDY_PREVIEW_MODE']) || !is_object($url) || $url->notFound() || $WHERE_AM_I === 'search') return '';
		if ($WHERE_AM_I === 'page' && is_object($page)) {
			return self::canonicalPageUrl($page);
		}
		if ($WHERE_AM_I === 'home' && self::isConfiguredHome($page)) return self::safePublicUrl(DOMAIN_BASE);
		if (in_array($WHERE_AM_I, array('home', 'blog', 'category', 'tag'), true)) {
			return self::safePublicUrl(Paginator::numberUrl(max(1, (int)$url->pageNumber())));
		}
		$requestPath = (string)$url->uri();
		if ($requestPath === '' || strlen($requestPath) > 4096 || preg_match('/[\x00-\x20\x7F\\\\]/', $requestPath)) return self::safePublicUrl(DOMAIN_BASE);
		$rootPath = '/' . trim(HTML_PATH_ROOT, '/');
		if ($rootPath !== '/' && ($requestPath === $rootPath || strpos($requestPath, $rootPath . '/') === 0)) {
			$requestPath = substr($requestPath, strlen($rootPath));
		}
		$segments = array();
		foreach (explode('/', trim($requestPath, '/')) as $segment) {
			if ($segment !== '') $segments[] = rawurlencode($segment);
		}
		$urlValue = rtrim(DOMAIN_BASE, '/') . '/' . implode('/', $segments);
		if ($requestPath !== '/' && substr($requestPath, -1) === '/') $urlValue .= '/';
		return self::safePublicUrl($urlValue);
	}

	public static function robotsDirectives()
	{
		global $url, $WHERE_AM_I, $page;
		if (!empty($GLOBALS['BRENDY_PREVIEW_MODE'])) return array('noindex', 'nofollow', 'noarchive');
		if (is_object($url) && $url->notFound()) return array('noindex', 'follow');
		if ($WHERE_AM_I === 'search') return array('noindex', 'follow');
		$directives = array();
		if (is_object($page) && ($WHERE_AM_I === 'page' || ($WHERE_AM_I === 'home' && self::isConfiguredHome($page)))) {
			if ($page->noindex()) $directives[] = 'noindex';
			if ($page->nofollow()) $directives[] = 'nofollow';
			if ($page->noarchive()) $directives[] = 'noarchive';
		}
		return $directives;
	}

	private static function seoImageUrl()
	{
		global $site, $WHERE_AM_I, $page, $content;
		$source = null;
		if (is_object($page) && ($WHERE_AM_I === 'page' || ($WHERE_AM_I === 'home' && self::isConfiguredHome($page)))) {
			$source = $page;
		} elseif (isset($content[0]) && is_object($content[0])) {
			$source = $content[0];
		}
		$html = '';
		if (is_object($source)) {
			$cover = method_exists($source, 'coverImage') ? self::absoluteSeoImageUrl($source->coverImage(true)) : '';
			if ($cover !== '') return $cover;
			if (method_exists($source, 'content')) $html = $source->content();
		}
		$fallback = is_object($site) && method_exists($site, 'seoSocialImage') ? $site->seoSocialImage() : '';
		return self::socialImageUrl('', is_string($html) ? $html : '', $fallback);
	}

	public static function seoLocale()
	{
		global $site;
		$candidates = array();
		if (is_object($site) && method_exists($site, 'language')) $candidates[] = $site->language();
		if (is_object($site) && method_exists($site, 'locale')) {
			$locale = $site->locale();
			if (is_string($locale)) $candidates = array_merge($candidates, preg_split('/\s*,\s*/', $locale, -1, PREG_SPLIT_NO_EMPTY));
		}
		foreach ($candidates as $candidate) {
			if (!is_string($candidate)) continue;
			$candidate = preg_replace('/\.(?:UTF-?8|utf-?8)$/i', '', trim($candidate));
			$candidate = str_replace('-', '_', $candidate);
			if (preg_match('/^([a-z]{2,3})_([A-Za-z]{2})$/D', $candidate, $match) === 1) {
				return strtolower($match[1]) . '_' . strtoupper($match[2]);
			}
		}
		return '';
	}

	private static function jsonLdScript($data)
	{
		$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP |
			JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
		return is_string($json) ? '<script type="application/ld+json"' . brendyCspNonceAttribute() . '>' . $json . '</script>' . PHP_EOL : '';
	}

	private static function structuredItemList($items, $canonical)
	{
		if (!is_array($items) || !is_string($canonical) || $canonical === '') return false;
		global $pages;
		$list = array();
		$seen = array();
		$position = 1;
		foreach (array_slice($items, 0, 50) as $item) {
			if (!is_object($item) || !method_exists($item, 'permalink') || !method_exists($item, 'title')) continue;
			if (method_exists($item, 'noindex') && $item->noindex()) continue;
			if (is_object($pages) && method_exists($pages, 'isPubliclyAccessible') && method_exists($item, 'key') && !$pages->isPubliclyAccessible($item->key())) continue;
			$url = self::canonicalPageUrl($item);
			$name = self::seoPlainText($item->title(), 180);
			if ($url === '' || $name === '' || isset($seen[$url])) continue;
			$seen[$url] = true;
			$list[] = array('@type' => 'ListItem', 'position' => $position++, 'url' => $url, 'name' => $name);
		}
		if (empty($list)) return false;
		return array('@type' => 'ItemList', '@id' => $canonical . '#items', 'itemListElement' => $list);
	}

	private static function structuredData($title, $description, $canonical, $image, $robots = null)
	{
		global $site, $url, $WHERE_AM_I, $page, $content;
		if (is_object($url) && $url->notFound()) return '';
		if ($canonical === '' || $WHERE_AM_I === 'search' || in_array('noindex', $robots ?? self::robotsDirectives(), true)) return '';
		$siteDescription = self::seoPlainText($site->description(), 180);
		$language = self::lang();
		$siteName = self::seoPlainText($site->title(), 120);
		$baseUrl = self::safePublicUrl(DOMAIN_BASE);
		if ($baseUrl === '') return '';
		$websiteId = rtrim($baseUrl, '/') . '/#website';
		$organizationId = rtrim($baseUrl, '/') . '/#organization';
		$organization = array('@type' => 'Organization', '@id' => $organizationId, 'name' => $siteName, 'url' => $baseUrl);
		$website = array('@type' => 'WebSite', '@id' => $websiteId, 'name' => $siteName, 'url' => $baseUrl,
			'inLanguage' => $language, 'publisher' => array('@id' => $organizationId));
		if ($siteDescription !== '') $website['description'] = $siteDescription;

		$configuredHome = in_array($WHERE_AM_I, array('home', 'page'), true) && self::isConfiguredHome($page);
		if ($WHERE_AM_I === 'page' && is_object($page) && !$configuredHome) {
			$type = $page->isStatic() ? 'WebPage' : 'BlogPosting';
			$pageTitle = self::seoPlainText($page->title(), 180);
			if ($pageTitle === '') $pageTitle = $title;
			$data = array('@type' => $type, '@id' => $canonical . '#primary', 'name' => $pageTitle, 'headline' => $pageTitle,
				'description' => $description, 'url' => $canonical, 'inLanguage' => $language,
				'isPartOf' => array('@id' => $websiteId));
			if (!$page->isStatic()) {
				$data['mainEntityOfPage'] = array('@type' => 'WebPage', '@id' => $canonical);
				$data['datePublished'] = $page->date('c');
				$data['publisher'] = array('@id' => $organizationId);
				$tags = $page->tags(true);
				if (is_array($tags) && !empty($tags)) {
					$keywords = array();
					foreach (array_slice(array_values($tags), 0, 100) as $tag) {
						$tag = self::seoPlainText($tag, 120);
						if ($tag !== '') $keywords[] = $tag;
					}
					if (!empty($keywords)) $data['keywords'] = implode(', ', array_values(array_unique($keywords)));
				}
				$modified = $page->dateModified('c');
				if (is_string($modified) && $modified !== '') $data['dateModified'] = $modified;
				$author = self::seoPlainText($page->user('nickname'), 120);
				if ($author !== '') $data['author'] = array('@type' => 'Person', 'name' => $author);
			}
			if ($image !== '') $data['image'] = $image;

			$items = array(array('@type' => 'ListItem', 'position' => 1, 'name' => $siteName, 'item' => $baseUrl));
			$position = 2;
			if (method_exists($page, 'categoryKey') && $page->categoryKey()) {
				$categoryUrl = self::safePublicUrl($page->categoryPermalink());
				if ($categoryUrl !== '') {
					$items[] = array('@type' => 'ListItem', 'position' => $position++, 'name' => self::seoPlainText($page->category(), 120), 'item' => $categoryUrl);
				}
			}
			$items[] = array('@type' => 'ListItem', 'position' => $position, 'name' => $pageTitle, 'item' => $canonical);
			$breadcrumbs = array('@type' => 'BreadcrumbList', '@id' => $canonical . '#breadcrumb', 'itemListElement' => $items);
			if ($page->isStatic()) {
				$data['breadcrumb'] = array('@id' => $canonical . '#breadcrumb');
				$graph = array($organization, $website, $data, $breadcrumbs);
			} else {
				$webpage = array('@type' => 'WebPage', '@id' => $canonical, 'url' => $canonical, 'name' => $pageTitle, 'inLanguage' => $language, 'isPartOf' => array('@id' => $websiteId), 'breadcrumb' => array('@id' => $canonical . '#breadcrumb'), 'mainEntity' => array('@id' => $canonical . '#primary'));
				$graph = array($organization, $website, $webpage, $data, $breadcrumbs);
			}
			return self::jsonLdScript(array('@context' => 'https://schema.org', '@graph' => $graph));
		}

		$itemList = $configuredHome ? false : self::structuredItemList(is_array($content) ? $content : array(), $canonical);
		if ($configuredHome || $WHERE_AM_I === 'home' || $WHERE_AM_I === 'blog') {
			$landing = array('@type' => ($configuredHome || $WHERE_AM_I === 'home') ? 'WebPage' : 'CollectionPage',
				'@id' => $canonical . '#primary', 'name' => $title, 'description' => $description,
				'url' => $canonical, 'inLanguage' => $language, 'isPartOf' => array('@id' => $websiteId));
			$graph = array($organization, $website, $landing);
			if (is_array($itemList)) {
				$landing['mainEntity'] = array('@id' => $canonical . '#items');
				$graph[2] = $landing;
				$graph[] = $itemList;
			}
			return self::jsonLdScript(array('@context' => 'https://schema.org', '@graph' => $graph));
		}

		$collection = array('@type' => 'CollectionPage', '@id' => $canonical . '#primary', 'name' => $title,
			'description' => $description, 'url' => $canonical, 'inLanguage' => $language, 'isPartOf' => array('@id' => $websiteId));
		$graph = array($organization, $website, $collection);
		if (is_array($itemList)) {
			$collection['mainEntity'] = array('@id' => $canonical . '#items');
			$graph[2] = $collection;
			$graph[] = $itemList;
		}
		return self::jsonLdScript(array('@context' => 'https://schema.org', '@graph' => $graph));
	}

	public static function seoHead()
	{
		return self::renderSeoHead(self::seoDescriptionText());
	}

	private static function renderSeoHead($description)
	{
		global $site, $WHERE_AM_I, $page;
		global $url, $L;
		// Error and preview responses must not expose social metadata for unrelated content.
		if (!empty($GLOBALS['BRENDY_PREVIEW_MODE']) || (is_object($url) && $url->notFound())) {
			return '<meta name="robots" content="' . self::escapeStoredText(implode(',', self::robotsDirectives())) . '">' . PHP_EOL;
		}
		$title = self::seoTitleText();
		if (is_object($url) && method_exists($url, 'pageNumber') && is_object($L) &&
			in_array($WHERE_AM_I, array('home', 'blog', 'category', 'tag', 'search'), true) &&
			(int)$url->pageNumber() > 1) {
			$title .= ' — ' . $L->g('Page') . ' ' . (int)$url->pageNumber();
		}
		$canonical = self::canonicalUrl();
		$image = self::seoImageUrl();
		$output = PHP_EOL . '<!-- Brendy SEO -->' . PHP_EOL;

		if ($canonical !== '') {
			$output .= '<link rel="canonical" href="' . self::escapeStoredText($canonical) . '">' . PHP_EOL;
		}
		$robots = self::robotsDirectives();
		if (!empty($robots)) $output .= '<meta name="robots" content="' . self::escapeStoredText(implode(',', $robots)) . '">' . PHP_EOL;

		$type = ($WHERE_AM_I === 'page' && is_object($page) && !self::isConfiguredHome($page) && !$page->isStatic()) ? 'article' : 'website';
		$output .= '<meta property="og:type" content="' . $type . '">' . PHP_EOL;
		$locale = self::seoLocale();
		if ($locale !== '') $output .= '<meta property="og:locale" content="' . self::escapeStoredText($locale) . '">' . PHP_EOL;
		$output .= '<meta property="og:site_name" content="' . self::escapeStoredText($site->title()) . '">' . PHP_EOL;
		$output .= '<meta property="og:title" content="' . self::escapeStoredText($title) . '">' . PHP_EOL;
		$output .= '<meta property="og:description" content="' . self::escapeStoredText($description) . '">' . PHP_EOL;
		if ($canonical !== '') $output .= '<meta property="og:url" content="' . self::escapeStoredText($canonical) . '">' . PHP_EOL;
		if ($image !== '') {
			$output .= '<meta property="og:image" content="' . self::escapeStoredText($image) . '">' . PHP_EOL;
			$output .= '<meta property="og:image:alt" content="' . self::escapeStoredText($title) . '">' . PHP_EOL;
		}
		$fbAppId = method_exists($site, 'seoFacebookAppId') ? $site->seoFacebookAppId() : '';
		if (is_string($fbAppId) && preg_match('/^[0-9]{1,64}$/D', $fbAppId) === 1) {
			$output .= '<meta property="fb:app_id" content="' . self::escapeStoredText($fbAppId) . '">' . PHP_EOL;
		}
		if ($type === 'article') {
			$published = $page->date('Y-m-d\\TH:i:sP');
			if (is_string($published) && $published !== '') $output .= '<meta property="article:published_time" content="' . self::escapeStoredText($published) . '">' . PHP_EOL;
			$modified = $page->dateModified('Y-m-d\\TH:i:sP');
			if (is_string($modified) && $modified !== '') $output .= '<meta property="article:modified_time" content="' . self::escapeStoredText($modified) . '">' . PHP_EOL;
			$author = method_exists($page, 'user') ? self::seoPlainText($page->user('nickname'), 120) : '';
			if ($author !== '') $output .= '<meta property="article:author" content="' . self::escapeStoredText($author) . '">' . PHP_EOL;
		}

		$card = method_exists($site, 'seoTwitterCard') ? $site->seoTwitterCard() : 'summary_large_image';
		if (!in_array($card, array('summary', 'summary_large_image'), true) || $image === '') $card = 'summary';
		$output .= '<meta name="twitter:card" content="' . $card . '">' . PHP_EOL;
		$twitterSite = method_exists($site, 'seoTwitterSite') ? $site->seoTwitterSite() : '';
		if (is_string($twitterSite) && preg_match('/^@?[A-Za-z0-9_]{1,15}$/D', $twitterSite) === 1) {
			if ($twitterSite[0] !== '@') $twitterSite = '@' . $twitterSite;
			$output .= '<meta name="twitter:site" content="' . self::escapeStoredText($twitterSite) . '">' . PHP_EOL;
		}
		$output .= '<meta name="twitter:title" content="' . self::escapeStoredText($title) . '">' . PHP_EOL;
		$output .= '<meta name="twitter:description" content="' . self::escapeStoredText($description) . '">' . PHP_EOL;
		if ($image !== '') {
			$output .= '<meta name="twitter:image" content="' . self::escapeStoredText($image) . '">' . PHP_EOL;
			$output .= '<meta name="twitter:image:alt" content="' . self::escapeStoredText($title) . '">' . PHP_EOL;
		}
		return $output . self::structuredData($title, $description, $canonical, $image, $robots);
	}

	// DEPRECATED v3.0.0
	// Return the metatag <title> with a predefine structure
	public static function headTitle()
	{
		return self::metaTagTitle();
	}

	// DEPRECATED v3.0.0
	// Return the metatag <decription> with a predefine structure
	public static function headDescription()
	{
		return self::metaTagDescription();
	}

	/**
	 * Shared production-ready public <head> baseline for bundled themes.
	 * Themes provide only their local stylesheet list and whether they need
	 * Bootstrap; SEO, favicons and the Brendy design system stay centralized.
	 */
	public static function publicHead($styles = array(), $includeBootstrap = true)
	{
		$output  = self::charset('UTF-8');
		$output .= self::viewport('width=device-width, initial-scale=1, viewport-fit=cover');
		$output .= '<meta name="generator" content="' . self::escape(BRENDY_NAME) . '">' . PHP_EOL;
		$output .= self::metaTagTitle();
		$description = self::seoDescriptionText();
		$output .= self::renderMetaDescription($description);
		$output .= self::renderSeoHead($description);
		$output .= self::coreFavicon();

		$touchIcon = self::coreImage('favicon.png');
		if ($touchIcon !== '') {
			$output .= '<link rel="apple-touch-icon" href="' . self::escape($touchIcon) . '">' . PHP_EOL;
		}
		if ($includeBootstrap) $output .= self::cssBootstrap();
		if (!empty($styles)) $output .= self::css($styles);
		return $output . self::brendyDesignSystem();
	}

	private static function safeCssClassList($classes, $fallback = '')
	{
		$tokens = is_string($classes) ? preg_split('/\s+/', trim($classes)) : array();
		$safe = array();
		if (is_array($tokens)) {
			foreach (array_slice($tokens, 0, 40) as $token) {
				if (is_string($token) && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $token) === 1) $safe[] = $token;
			}
		}
		if (empty($safe) && is_string($fallback) && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $fallback) === 1) $safe[] = $fallback;
		return implode(' ', array_values(array_unique($safe)));
	}

	public static function emptyState($labelKey = 'No pages found', $icon = 'bi-inbox', $classes = 'mt-4')
	{
		global $L;
		$icon = is_string($icon) && preg_match('/^bi-[a-z0-9-]{1,50}$/D', $icon) === 1 ? $icon : 'bi-inbox';
		$classes = self::safeCssClassList('brendy-empty-state ' . (is_string($classes) ? $classes : ''), 'brendy-empty-state');
		$label = is_object($L) ? $L->get(is_string($labelKey) ? $labelKey : 'No pages found') : (string)$labelKey;
		return '<div class="' . self::escape($classes) . '"><i class="bi ' . self::escape($icon) . '" aria-hidden="true"></i><span>' .
			self::escape($label) . '</span></div>' . PHP_EOL;
	}

	/** Shared paginator state so themes only own presentation markup. */
	public static function pagerData()
	{
		$total = max(1, (int)Paginator::numberOfPages());
		$current = max(1, min($total, (int)Paginator::currentPage()));
		$previousUrl = Paginator::showPrev() ? Paginator::previousPageUrl() : '';
		$nextUrl = Paginator::showNext() ? Paginator::nextPageUrl() : '';
		return array(
			'visible' => $total > 1,
			'current' => $current,
			'total' => $total,
			'hasPrevious' => is_string($previousUrl) && $previousUrl !== '',
			'previousUrl' => is_string($previousUrl) ? $previousUrl : '',
			'hasNext' => is_string($nextUrl) && $nextUrl !== '',
			'nextUrl' => is_string($nextUrl) ? $nextUrl : ''
		);
	}

	public static function pagination($classes = 'mt-5')
	{
		if (!class_exists('Paginator') || Paginator::numberOfPages() <= 1) return '';
		$classes = self::safeCssClassList($classes);
		$attr = $classes !== '' ? ' class="' . self::escape($classes) . '"' : '';
		return '<div' . $attr . '>' . Paginator::bootstrap_html() . '</div>' . PHP_EOL;
	}

	/** Shared taxonomy renderer; bundled themes provide classes, not duplicate loops. */
	public static function taxonomy($pageObject, $options = array())
	{
		if (!is_object($pageObject) || !method_exists($pageObject, 'tags') || !method_exists($pageObject, 'categoryKey')) return '';
		$options = is_array($options) ? $options : array();
		$wrapperClass = self::safeCssClassList(isset($options['wrapperClass']) ? $options['wrapperClass'] : 'post-taxonomy mt-3');
		$wrapperTag = isset($options['wrapperTag']) && in_array($options['wrapperTag'], array('div', 'footer', 'nav'), true) ? $options['wrapperTag'] : 'div';
		$categoryClass = self::safeCssClassList(isset($options['categoryClass']) ? $options['categoryClass'] : 'taxonomy-badge taxonomy-category');
		$tagClass = self::safeCssClassList(isset($options['tagClass']) ? $options['tagClass'] : 'taxonomy-badge taxonomy-tag');
		$categoryIcon = isset($options['categoryIcon']) && is_string($options['categoryIcon']) && preg_match('/^bi-[a-z0-9-]{1,50}$/D', $options['categoryIcon']) === 1 ? $options['categoryIcon'] : '';
		$tagIcon = isset($options['tagIcon']) && is_string($options['tagIcon']) && preg_match('/^bi-[a-z0-9-]{1,50}$/D', $options['tagIcon']) === 1 ? $options['tagIcon'] : '';
		$tagPrefix = isset($options['tagPrefix']) && is_scalar($options['tagPrefix']) ? mb_substr((string)$options['tagPrefix'], 0, 4, CHARSET) : '';
		$showCategory = !array_key_exists('showCategory', $options) || $options['showCategory'] !== false;
		$showTags = !array_key_exists('showTags', $options) || $options['showTags'] !== false;
		$tags = $showTags ? $pageObject->tags(true) : array();
		$tags = is_array($tags) ? $tags : array();
		$categoryKey = $showCategory ? $pageObject->categoryKey() : false;
		$hasCategory = is_string($categoryKey) && $categoryKey !== '';
		if (empty($tags) && !$hasCategory) return '';
		$out = '<' . $wrapperTag . ($wrapperClass !== '' ? ' class="' . self::escape($wrapperClass) . '"' : '') . '>';
		if ($hasCategory && method_exists($pageObject, 'categoryPermalink') && method_exists($pageObject, 'category')) {
			$out .= '<a' . ($categoryClass !== '' ? ' class="' . self::escape($categoryClass) . '"' : '') . ' href="' . self::escape($pageObject->categoryPermalink()) . '">';
			if ($categoryIcon !== '') $out .= '<i class="bi ' . self::escape($categoryIcon) . '" aria-hidden="true"></i> ';
			$out .= self::escape($pageObject->category()) . '</a>';
		}
		$count = 0;
		foreach ($tags as $tagKey => $tagName) {
			if (++$count > 100 || !is_scalar($tagKey) || !is_scalar($tagName)) continue;
			$out .= '<a' . ($tagClass !== '' ? ' class="' . self::escape($tagClass) . '"' : '') . ' href="' . self::escape(DOMAIN_TAGS . rawurlencode((string)$tagKey)) . '">';
			if ($tagIcon !== '') $out .= '<i class="bi ' . self::escape($tagIcon) . '" aria-hidden="true"></i> ';
			$out .= self::escape($tagPrefix . (string)$tagName) . '</a>';
		}
		return $out . '</' . $wrapperTag . '>' . PHP_EOL;
	}

	/** Shared, side-effect-free search context for themes. */
	public static function searchContext()
	{
		$context = array(
			'active' => false,
			'action' => defined('DOMAIN_BASE') ? DOMAIN_BASE . 'search/' : '',
			'minChars' => 3,
			'term' => '',
			'resultCount' => null
		);
		if (!function_exists('pluginActivated') || !pluginActivated('BrendyPluginSearch') || !function_exists('getPlugin')) return $context;
		try {
			$plugin = getPlugin('BrendyPluginSearch');
			if (!is_object($plugin)) return $context;
			$minimum = method_exists($plugin, 'getValue') ? (int)$plugin->getValue('minChars') : 3;
			$context['minChars'] = max(1, min(100, $minimum));
			if (method_exists($plugin, 'getSearchTerm')) {
				$term = $plugin->getSearchTerm();
				if (is_string($term) && strlen($term) <= 2000 && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $term) !== 1) $context['term'] = $term;
			}
			if (method_exists($plugin, 'getResultCount')) {
				$count = $plugin->getResultCount();
				if (is_int($count) || (is_string($count) && preg_match('/^[0-9]{1,10}$/D', $count) === 1)) $context['resultCount'] = max(0, (int)$count);
			}
			$context['active'] = true;
		} catch (Throwable $e) {
			if (class_exists('Log')) Log::set(__METHOD__ . LOG_SEP . substr((string)$e->getMessage(), 0, 300), LOG_TYPE_ERROR);
		}
		return $context;
	}


	/** Safely include one PHP partial from the active theme while exposing the usual theme globals. */
	public static function includePartial($filename)
	{
		global $site, $page, $content, $url, $L, $language, $themePlugin, $WHERE_AM_I;
		if (!is_string($filename) || $filename !== basename($filename) ||
			preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.php$/D', $filename) !== 1) return false;
		$path = THEME_DIR_PHP . $filename;
		if (!is_file($path) || is_link($path) || !is_readable($path)) return false;
		$root = realpath(THEME_DIR_PHP);
		$real = realpath($path);
		if (!is_string($root) || !is_string($real) || strpos($real, $root . DS) !== 0) return false;
		include($real);
		return true;
	}

	public static function includeCurrentContent($notFoundTemplate = '')
	{
		global $WHERE_AM_I, $url;
		if (is_string($notFoundTemplate) && $notFoundTemplate !== '' && is_object($url) && method_exists($url, 'notFound') && $url->notFound()) {
			return self::includePartial($notFoundTemplate);
		}
		return self::includePartial($WHERE_AM_I === 'page' ? 'stranica.php' : 'naslovnica.php');
	}

	public static function archiveHeading($className = 'brendy-visually-hidden')
	{
		$className = is_string($className) && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $className) === 1
			? $className : 'brendy-visually-hidden';
		return '<h1 class="' . self::escape($className) . '">' . self::escape(self::seoTitleText()) . '</h1>' . PHP_EOL;
	}

	/** Standardized product signature used by bundled themes and the frontend fallback. */
	public static function brandSignature($tag = 'span', $className = '', $linkClass = '')
	{
		$tag = is_string($tag) && in_array(strtolower($tag), array('span', 'p', 'div'), true)
			? strtolower($tag) : 'span';
		$className = is_string($className) && strlen($className) <= 500 &&
			preg_match('/[\x00-\x1F\x7F]/', $className) !== 1 ? trim($className) : '';
		$linkClass = is_string($linkClass) && strlen($linkClass) <= 500 &&
			preg_match('/[\x00-\x1F\x7F]/', $linkClass) !== 1 ? trim($linkClass) : '';
		$classAttribute = $className === '' ? '' : ' class="' . self::escape($className) . '"';
		$linkClassAttribute = $linkClass === '' ? '' : ' class="' . self::escape($linkClass) . '"';
		$name = defined('BRENDY_NAME') ? BRENDY_NAME : 'Brendy CMS';
		$website = defined('BRENDY_WEBSITE') ? BRENDY_WEBSITE : 'https://brendigo.com';
		return '<' . $tag . $classAttribute . ' data-brendy-brand-signature>' . self::escape($name) .
			' · <a href="' . self::escape($website) . '" target="_blank" rel="noopener noreferrer"' .
			$linkClassAttribute . '>Brendigo</a></' . $tag . '>';
	}

	public static function skipLink($target = 'main-content')
	{
		global $L;
		$target = is_string($target) && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $target) === 1
			? $target : 'main-content';
		$label = is_object($L) ? $L->get('Skip to main content') : 'Skip to main content';
		return '<a class="brendy-skip-link" href="#' . self::escape($target) . '">' . self::escape($label) . '</a>' . PHP_EOL;
	}

	/** Render sidebar plugins as a theme-neutral responsive region. */
	public static function pluginRegion($className = '')
	{
		global $plugins, $L;
		if (!isset($plugins['siteSidebar']) || !is_array($plugins['siteSidebar']) || empty($plugins['siteSidebar'])) return '';
		$tokens = array();
		if (is_string($className)) {
			foreach (preg_split('/\s+/', trim($className)) ?: array() as $token) {
				if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $token) === 1) $tokens[] = $token;
			}
		}
		$classes = trim('brendy-plugin-region ' . implode(' ', array_slice(array_unique($tokens), 0, 10)));

		ob_start();
		try {
			self::plugins('siteSidebar');
			$content = ob_get_clean();
		} catch (Throwable $e) {
			ob_end_clean();
			Log::set(__METHOD__ . LOG_SEP . substr((string)$e->getMessage(), 0, 500), LOG_TYPE_ERROR);
			return '';
		}
		if (!is_string($content) || trim($content) === '') return '';
		$label = is_object($L) ? $L->get('Plugins') : 'Plugins';
		return '<aside class="' . self::escape($classes) . '" aria-label="' . self::escape($label) . '">' .
			'<div class="brendy-plugin-region__grid">' . $content . '</div></aside>' . PHP_EOL;
	}

	public static function publicScripts($themeScripts = array(), $includeBootstrap = true)
	{
		if (!is_array($themeScripts)) $themeScripts = array($themeScripts);
		$output = '';
		if ($includeBootstrap) {
			$output .= self::jquery();
			$output .= self::jsBootstrap();
		}
		foreach (array_slice($themeScripts, 0, 20) as $script) {
			if (!is_string($script) || preg_match('#^(?!/)(?!.*(?:^|/)\.{1,2}(?:/|$))[A-Za-z0-9][A-Za-z0-9._/-]{0,254}\.js$#D', $script) !== 1) continue;
			$output .= self::js($script, DOMAIN_THEME, 'defer');
		}
		return $output . self::brendyFrontendScripts();
	}

	public static function charset($charset)
	{
		$charset = is_string($charset) && preg_match('/^[A-Za-z0-9._-]{1,40}$/D', $charset) === 1 ? $charset : 'UTF-8';
		return '<meta charset="' . htmlspecialchars($charset, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '">' . PHP_EOL;
	}

	public static function viewport($content)
	{
		return '<meta name="viewport" content="' . htmlspecialchars(is_scalar($content) ? (string)$content : '', ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '">' . PHP_EOL;
	}

	public static function src($file, $base = DOMAIN_THEME)
	{
		if (!self::safeRelativeAsset($file) || !is_string($base) || $base === '' ||
			preg_match('/[\x00-\x1F\x7F]/', $base)) return '';
		return htmlspecialchars($base . $file, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET);
	}

	public static function css($files, $base = DOMAIN_THEME)
	{
		if (!is_array($files)) {
			$files = array($files);
		}

		$links = '';
		foreach (array_slice($files, 0, 100) as $file) {
			if (!self::safeRelativeAsset($file, 'css') || !is_string($base) || $base === '' || preg_match('/[\x00-\x1F\x7F]/', $base)) continue;
			$url = htmlspecialchars($base . $file . '?version=' . rawurlencode(BRENDY_BUILD), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET);
			$links .= '<link rel="stylesheet" type="text/css" href="' . $url . '">' . PHP_EOL;
		}

		return $links;
	}

	public static function javascript($files, $base = DOMAIN_THEME, $attributes = '')
	{
		if (!is_array($files)) {
			$files = array($files);
		}

		$scripts = '';
		$attributes = self::scriptAttributes($attributes);
		$attributePrefix = $attributes === '' ? '' : $attributes . ' ';
		foreach (array_slice($files, 0, 100) as $file) {
			if (!self::safeRelativeAsset($file, 'js') || !is_string($base) || $base === '' || preg_match('/[\x00-\x1F\x7F]/', $base)) continue;
			$url = htmlspecialchars($base . $file . '?version=' . rawurlencode(BRENDY_BUILD), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET);
			$scripts .= '<script ' . $attributePrefix . 'src="' . $url . '"></script>' . PHP_EOL;
		}

		return $scripts;
	}

	public static function js($files, $base = DOMAIN_THEME, $attributes = '')
	{
		return self::javascript($files, $base, $attributes);
	}

	/**
	 * Execute a page hook with an explicit Page context and restore the previous
	 * global afterwards. Listing themes must not leak the last iterated item into
	 * later plugin hooks or the footer.
	 */
	public static function isCurrentPage($candidate)
	{
		global $page;
		if (!is_object($page) || !is_object($candidate) ||
			!is_callable(array($page, 'key')) || !is_callable(array($candidate, 'key'))) return false;
		$currentKey = $page->key();
		$candidateKey = $candidate->key();
		return is_string($currentKey) && is_string($candidateKey) && $currentKey !== '' && $currentKey === $candidateKey;
	}

	public static function pageHook($type, $pageObject)
	{
		if (!in_array($type, array('pageBegin', 'pageEnd'), true) || !is_object($pageObject)) return false;
		$hadPage = array_key_exists('page', $GLOBALS);
		$previousPage = $hadPage ? $GLOBALS['page'] : null;
		$GLOBALS['page'] = $pageObject;
		try {
			return self::plugins($type);
		} finally {
			if ($hadPage) $GLOBALS['page'] = $previousPage;
			else unset($GLOBALS['page']);
		}
	}

	public static function plugins($type, $args = array())
	{
		global $plugins;
		if (!is_string($type) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $type) !== 1 ||
			!isset($plugins[$type]) || !is_array($plugins[$type]) || !is_array($args)) return false;
		$hookArgs = array_slice($args, 0, 20);
		$lifecycleHooks = array('afterPageCreate', 'afterPageModify', 'afterPageDelete',
			'afterCategoryCreate', 'afterCategoryModify', 'afterCategoryDelete', 'afterSettingsModify');
		$discardOutput = in_array($type, $lifecycleHooks, true);
		foreach (array_slice($plugins[$type], 0, 1000) as $plugin) {
			if (!is_object($plugin) || !is_callable(array($plugin, $type))) continue;
			$bufferLevel = ob_get_level();
			$buffering = false;
			if ($discardOutput) {
				ob_start();
				$buffering = true;
			}
			try {
				$result = $plugin->{$type}(...$hookArgs);
			} catch (Throwable $e) {
				if ($buffering) {
					while (ob_get_level() > $bufferLevel) @ob_end_clean();
				}
				// A broken extension hook must not take down the complete public/admin
				// response. Core authorization/security never depends on plugin hooks;
				// log the failure and let the remaining extensions continue.
				$className = get_class($plugin);
				Log::set(__METHOD__ . LOG_SEP . $type . LOG_SEP . $className . LOG_SEP .
					substr((string)$e->getMessage(), 0, 500), LOG_TYPE_ERROR);
				continue;
			}
			if ($buffering) {
				$unexpected = '';
				while (ob_get_level() > $bufferLevel) {
					$chunk = ob_get_clean();
					if (is_string($chunk)) $unexpected = $chunk . $unexpected;
				}
				if ($unexpected !== '') {
					Log::set(__METHOD__ . LOG_SEP . $type . LOG_SEP . get_class($plugin) .
						LOG_SEP . 'Discarded unexpected lifecycle hook output.', LOG_TYPE_WARN);
				}
				continue;
			}
			// Only strings are renderable hook output. Boolean/integer return values are
			// lifecycle status signals and must never leak into HTML/JSON responses.
			if (is_string($result)) echo $result;
		}
		return true;
	}

	public static function coreImage($file)
	{
		if (!is_string($file) || $file === '' || basename($file) !== $file ||
			preg_match('/[\x00-\x1F\x7F]/', $file) === 1 || !defined('DOMAIN_CORE_IMG')) return '';
		$path = PATH_ROOT . 'jezgra' . DS . 'img' . DS . $file;
		if (!is_file($path) || is_link($path)) return '';
		return DOMAIN_CORE_IMG . rawurlencode($file) . '?version=' . rawurlencode((string)BRENDY_BUILD);
	}

	public static function coreFavicon($typeIcon = 'image/png')
	{
		$typeIcon = is_string($typeIcon) && preg_match('#^[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+$#D', $typeIcon)
			? $typeIcon : 'image/png';
		$url = self::coreImage('favicon.png');
		if ($url === '') return '';
		return '<link rel="icon" href="' . self::escape($url) . '" type="' . self::escape($typeIcon) . '">' . PHP_EOL;
	}

	public static function favicon($file = 'favicon.png', $typeIcon = 'image/png')
	{
		$file = self::safeRelativeAsset($file) ? $file : '';
		if ($file === '') return '';
		$typeIcon = is_string($typeIcon) && preg_match('#^[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+$#D', $typeIcon) ? $typeIcon : 'image/png';
		$url = DOMAIN_THEME . $file . '?version=' . rawurlencode((string)BRENDY_BUILD);
		return '<link rel="icon" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) .
			'" type="' . htmlspecialchars($typeIcon, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '">' . PHP_EOL;
	}

	public static function keywords($keywords)
	{
		if (is_array($keywords)) {
			$keywords = implode(',', $keywords);
		}
		return '<meta name="keywords" content="' . self::escapeStoredText($keywords) . '">' . PHP_EOL;
	}

	public static function jquery()
	{
		return '<script src="' . DOMAIN_CORE_JS . 'jquery.min.js?version=' . BRENDY_BUILD . '"></script>' . PHP_EOL;
	}

	public static function jsBootstrap($attributes = '')
	{
		$attributes = self::scriptAttributes($attributes);
		$attributePrefix = $attributes === '' ? '' : $attributes . ' ';
		return '<script ' . $attributePrefix . 'src="' . DOMAIN_CORE_JS . 'bootstrap.bundle.min.js?version=' . rawurlencode(BRENDY_BUILD) . '"></script>' . PHP_EOL;
	}

	public static function cssBootstrap()
	{
		return '<link rel="stylesheet" type="text/css" href="' . DOMAIN_CORE_CSS . 'bootstrap.min.css?version=' . BRENDY_BUILD . '">' . PHP_EOL . '<link rel="stylesheet" href="' . DOMAIN_CORE_CSS . 'brendy-bootstrap-compat.css?version=' . BRENDY_BUILD . '">' . PHP_EOL;
	}

	public static function cssBootstrapIcons()
	{
		// https://icons.getbootstrap.com/
		return '<link rel="stylesheet" type="text/css" href="' . DOMAIN_CORE_CSS . 'bootstrap-icons/bootstrap-icons.css?version=' . BRENDY_BUILD . '">' . PHP_EOL . '<link rel="stylesheet" href="' . DOMAIN_CORE_CSS . 'bootstrap-icons/brendy-compat.css?version=' . BRENDY_BUILD . '">' . PHP_EOL;
	}

	public static function jsSortable($attributes = '')
	{
		// Legacy public helper for third-party extensions (HTML5 Sortable).
		$attributes = self::scriptAttributes($attributes);
		$attributePrefix = $attributes === '' ? '' : $attributes . ' ';
		return '<script ' . $attributePrefix . 'src="' . DOMAIN_CORE_JS . 'jquery.sortable.min.js?version=' . rawurlencode(BRENDY_BUILD) . '"></script>' . PHP_EOL;
	}
}
