<?php defined('BRENDY') or die('Brendy CMS.');

class Site extends BrendyJsonDatabase
{
	public $dbFields = array(
		'title' =>		'Brendy CMS',
		'slogan' =>		'Dobro došli u Brendy CMS',
		'description' =>		'Jednostavno i sigurno upravljanje sadržajem.',
		'footer' =>		'Brendy CMS · Brendigo · brendigo.com',
		'itemsPerPage' =>	6,
		'language' =>		'hr_HR',
		'locale' =>		'hr_HR, hr_HR.UTF-8, hr_HR.utf8, hr',
		'timezone' =>		'Europe/Zagreb',
		'theme' =>		'alternativa',
		'adminTheme' =>		'brendy-admin',
		'accentColor' =>	'#2563eb',
		'colorScheme' =>	'auto',
		'contentWidth' =>	'wide',
		'typographyScale' =>	'comfortable',
		'cardStyle' =>		'elevated',
		'animationLevel' =>	'subtle',
		'roundedCards' =>	true,
		'showReadingProgress' => true,
		'readingProgressPosition' => 'top',
		'readingProgressHeight' => 4,
		'readingProgressColor' => '#2563eb',
		'showBackToTop' =>	true,
		'showPostMeta' =>	true,
		'showReadingTime' =>	true,
		'showArticleTools' => true,
		'showRelatedContent' => true,
		'enableImageLazyLoading' => true,
		'homepage' =>		'',
		'pageNotFound' =>	'',
		'uriPage' =>		'/',
		'uriTag' =>		'/oznaka/',
		'uriCategory' =>		'/kategorija/',
		'uriBlog' =>		'/blog/',
		'url' =>			'',
		'emailFrom' =>		'',
		'dateFormat' =>		'd.m.Y.',
		'timeFormat' =>		'H:i',
		'currentBuild' =>	0,
		'twitter' =>		'',
		'facebook' =>		'',
		'codepen' =>		'',
		'instagram' =>		'',
		'github' =>		'',
		'gitlab' =>		'',
		'linkedin' =>		'',
		'xing' =>		'',
		'telegram' => '',
		'mastodon' =>		'',
		'dribbble' =>		'',
		'vk' =>			'',
		'youtube' =>		'',
		'bluesky' =>		'',
		'orderBy' =>		'date', // date or position
		'defaultContentStatus' => 'published', // published or draft
		'extremeFriendly' =>	true,
		'autosaveInterval' =>	2, // minutes
		'titleFormatHomepage' =>	'{{site-slogan}} | {{site-title}}',
		'titleFormatPages' =>	'{{page-title}} | {{site-title}}',
		'titleFormatCategory' => '{{category-name}} | {{site-title}}',
		'titleFormatTag' => 	'{{tag-name}} | {{site-title}}',
		'seoSocialImage' => '',
		'seoFacebookAppId' => '',
		'seoTwitterSite' => '',
		'seoTwitterCard' => 'summary_large_image',
		'robotsTxt' => "User-agent: *\nAllow: /",
		'maintenanceMode' => false,
		'maintenanceMessage' => 'Web-stranica je privremeno nedostupna zbog održavanja.',
		'alternativaShowPostInformation' => false,
		'alternativaDateFormat' => 'relative',
		'fokusDarkMode' => false,
		'fokusDateFormat' => 'relative',
		'fokusShowTags' => true,
		'imageRestrict' =>	true,
		'imageRelativeToAbsolute' => false,
		'thumbnailEnable' =>	true,
		'thumbnailWidth' => 	400, // px
		'thumbnailHeight' => 	400, // px
		'thumbnailQuality' => 	100,
		'logo' =>		'',
		'markdownParser' =>	true,
		'customFields' =>	'{}'
	);

    private static function legacyThemeAliases()
    {
        return array(
            'alternative' => 'alternativa',
            'blogx' => 'blog',
            'flavor' => 'okus',
            'newsroom' => 'novosti',
            'popeye' => 'fokus'
        );
    }

	function __construct()
	{
		parent::__construct(DB_SITE);
		$changed = false;

		// Brendy 2.6 moved essential SEO metadata and bundled-theme preferences
		// into core. Migrate values from the retired plugins before schema
		// defaults are filled so upgrades preserve administrator choices.
		$this->migrateRetiredPluginSettings($changed);

        // Migrate theme directory identifiers used by Brendy 1.3.x before the
        // schema validator can mistake them for invalid values and silently
        // reset the site to the default theme.
        if (isset($this->db['theme']) && is_string($this->db['theme'])) {
            $legacyTheme = Sanitize::htmlDecode($this->db['theme']);
            $aliases = self::legacyThemeAliases();
            if (isset($aliases[$legacyTheme])) {
                $candidate = $aliases[$legacyTheme];
                if (is_dir(PATH_THEMES . $candidate) && !is_link(PATH_THEMES . $candidate)) {
                    $this->db['theme'] = $candidate;
                    $changed = true;
                }
            }
        }

		foreach ($this->dbFields as $field => $default) {
			if (!array_key_exists($field, $this->db) || gettype($this->db[$field]) !== gettype($default) ||
				!$this->validateField($field, $this->db[$field])) {
				$this->db[$field] = $default;
				$changed = true;
			}
		}
		if ($changed && !$this->save()) {
			$this->recoverPersistedState();
			Log::set(__METHOD__ . LOG_SEP . 'Unable to persist repaired site settings; persisted state reloaded.', LOG_TYPE_ERROR);
		}

		// Set timezone
		if (!$this->setTimezone($this->timezone())) $this->setTimezone($this->dbFields['timezone']);

		// Set locale
		$this->setLocale($this->locale());
	}

	// Returns an array with site configuration.
	function get()
	{
		return $this->db;
	}

	public function set($args)
	{
		if (!is_array($args)) {
			return false;
		}
		$updated = $this->db;
		// Check values on args or set default values
		foreach ($this->dbFields as $field => $value) {
			if (array_key_exists($field, $args)) {
				$valid = false;
				$finalValue = $this->normalizeField($args[$field], $value, $valid);
				if (!$valid || !$this->validateField($field, $finalValue)) {
					return false;
				}
				$updated[$field] = $finalValue;
			}
		}
		$previous = $this->db;
		$this->db = $updated;
		if (!$this->save()) {
			$this->db = $previous;
			return false;
		}
		return true;
	}

	private function normalizeField($value, $default, &$valid)
	{
		$valid = false;
		if (is_bool($default)) {
			$boolean = brendyBooleanValue($value, null);
			if ($boolean === null) return false;
			$valid = true;
			return $boolean;
		}
		if (is_int($default)) {
			$integer = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
			if ($integer === false) return false;
			$valid = true;
			return (int)$integer;
		}
		if (is_string($default) && (is_string($value) || is_numeric($value))) {
			$valid = true;
			return Sanitize::html((string)$value);
		}
		return false;
	}

	private function validateField($field, $value)
	{
		$integerRanges = array(
			'itemsPerPage' => array(1, 1000),
			'autosaveInterval' => array(1, 1440),
			'thumbnailWidth' => array(16, 8192),
			'thumbnailHeight' => array(16, 8192),
			'thumbnailQuality' => array(0, 100),
			'currentBuild' => array(0, PHP_INT_MAX),
			'readingProgressHeight' => array(2, 10)
		);
		if (isset($integerRanges[$field])) {
			return $value >= $integerRanges[$field][0] && $value <= $integerRanges[$field][1];
		}
		$rawValue = is_string($value) ? Sanitize::htmlDecode($value) : $value;
		if ($field === 'orderBy') return in_array($rawValue, array('date', 'position'), true);
		if ($field === 'defaultContentStatus') return in_array($rawValue, array('published', 'draft'), true);
		if ($field === 'accentColor') {
			return is_string($rawValue) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $rawValue) === 1;
		}
		if ($field === 'colorScheme') return in_array($rawValue, array('auto', 'light', 'dark'), true);
		if ($field === 'contentWidth') return in_array($rawValue, array('compact', 'wide', 'full'), true);
		if ($field === 'typographyScale') return in_array($rawValue, array('compact', 'comfortable', 'large'), true);
		if ($field === 'cardStyle') return in_array($rawValue, array('elevated', 'outlined', 'flat'), true);
		if ($field === 'animationLevel') return in_array($rawValue, array('full', 'subtle', 'off'), true);
		if ($field === 'readingProgressPosition') return in_array($rawValue, array('top', 'bottom'), true);
		if ($field === 'readingProgressColor') return is_string($rawValue) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $rawValue) === 1;
		if ($field === 'theme') {
			return is_string($rawValue) && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $rawValue) === 1 &&
				is_dir(PATH_THEMES . $rawValue) && !is_link(PATH_THEMES . $rawValue);
		}
		if ($field === 'adminTheme') {
			return is_string($rawValue) && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $rawValue) === 1 &&
				is_dir(PATH_ADMIN_THEMES . $rawValue) && !is_link(PATH_ADMIN_THEMES . $rawValue);
		}
		if ($field === 'language') {
			return is_string($rawValue) && preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8})?$/D', $rawValue) === 1 &&
				is_file(PATH_LANGUAGES . $rawValue . '.json') && !is_link(PATH_LANGUAGES . $rawValue . '.json');
		}
		if ($field === 'timezone') return is_string($rawValue) && in_array($rawValue, DateTimeZone::listIdentifiers(), true);
		if ($field === 'locale') {
			if (!is_string($rawValue) || strlen($rawValue) > 1000) return false;
			foreach (explode(',', $rawValue) as $locale) {
				if (preg_match('/^[A-Za-z0-9_.@-]{1,64}$/D', trim($locale)) !== 1) return false;
			}
			return true;
		}
		if ($field === 'url') {
			if ($rawValue === '') return true;
			if (!is_string($rawValue) || strlen($rawValue) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $rawValue) ||
				!filter_var($rawValue, FILTER_VALIDATE_URL)) return false;
			$parts = parse_url($rawValue);
			return is_array($parts) && isset($parts['scheme'], $parts['host']) &&
				in_array(strtolower($parts['scheme']), array('http', 'https'), true) &&
				(!isset($parts['port']) || ($parts['port'] >= 1 && $parts['port'] <= 65535)) &&
				!isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']);
		}
		if ($field === 'emailFrom') return $rawValue === '' || (is_string($rawValue) && filter_var($rawValue, FILTER_VALIDATE_EMAIL));
		$socialFields = array('twitter', 'facebook', 'codepen', 'instagram', 'github', 'gitlab', 'linkedin',
			'xing', 'telegram', 'mastodon', 'dribbble', 'vk', 'youtube', 'bluesky');
		if (in_array($field, $socialFields, true)) {
			if ($rawValue === '') return true;
			if (!is_string($rawValue) || strlen($rawValue) > 2048 || preg_match('/[\x00-\x20\x7F]/', $rawValue) ||
				!filter_var($rawValue, FILTER_VALIDATE_URL)) return false;
			$parts = parse_url($rawValue);
			return is_array($parts) && isset($parts['scheme'], $parts['host']) &&
				in_array(strtolower($parts['scheme']), array('http', 'https'), true) &&
				!isset($parts['user']) && !isset($parts['pass']);
		}
		if (in_array($field, array('uriPage', 'uriTag', 'uriCategory', 'uriBlog'), true)) {
			if ($field === 'uriBlog' && $rawValue === '') return true;
			return is_string($rawValue) && strlen($rawValue) <= 200 &&
				($rawValue === '/' || preg_match('#^/[\pL\pN._~/%-]+/$#uD', $rawValue) === 1) && $rawValue[0] === '/' &&
				substr($rawValue, -1) === '/' && strpos($rawValue, '//') === false &&
				!preg_match('/[\x00-\x20\x7F\\\\?#]/', $rawValue) &&
				!preg_match('#(?:^|/)\.\.?(/|$)#', $rawValue);
		}
		if (in_array($field, array('homepage', 'pageNotFound'), true)) {
			return is_string($rawValue) && ($rawValue === '' ||
				(strlen($rawValue) <= 200 && preg_match('#^[\pL\pN._~/-]+$#uD', $rawValue) === 1 &&
				 !preg_match('#(?:^|/)\.\.?(/|$)#', $rawValue)));
		}

		if ($field === 'seoSocialImage') {
			if ($rawValue === '') return true;
			if (!is_string($rawValue) || strlen($rawValue) > 4096 || preg_match('/[\x00-\x20\x7F\\\\]/', $rawValue)) return false;
			if (brendyIsSafeHttpUrl($rawValue, 4096)) return true;
			return $rawValue[0] === '/' && strncmp($rawValue, '//', 2) !== 0 &&
				preg_match('#(?:^|/)\.\.?(/|$)#', $rawValue) !== 1 &&
				preg_match('#^/[A-Za-z0-9._~/%+-]+$#D', $rawValue) === 1;
		}
		if ($field === 'seoFacebookAppId') return $rawValue === '' ||
			(is_string($rawValue) && preg_match('/^[0-9]{1,64}$/D', $rawValue) === 1);
		if ($field === 'seoTwitterSite') return $rawValue === '' ||
			(is_string($rawValue) && preg_match('/^@?[A-Za-z0-9_]{1,15}$/D', $rawValue) === 1);
		if ($field === 'seoTwitterCard') return in_array($rawValue, array('summary', 'summary_large_image'), true);
		if ($field === 'robotsTxt') return is_string($rawValue) && strlen($rawValue) <= 100000 && strpos($rawValue, "\0") === false;
		if ($field === 'maintenanceMessage') return is_string($rawValue) && strlen($rawValue) <= 1000 && strpos($rawValue, "\0") === false;
		if ($field === 'alternativaDateFormat') return in_array($rawValue, array('noshow', 'relative', 'absolute'), true);
		if ($field === 'fokusDateFormat') return in_array($rawValue, array('relative', 'absolute'), true);
		if ($field === 'logo') {
			if ($rawValue === '') return true;
			return is_string($rawValue) && strlen($rawValue) <= 255 && basename($rawValue) === $rawValue &&
				strpos($rawValue, "\0") === false && in_array(Text::lowercase(Filesystem::extension($rawValue)),
					$GLOBALS['ALLOWED_IMG_EXTENSION'], true);
		}
		if ($field === 'customFields') {
			if (!is_string($rawValue) || strlen($rawValue) > 100000) return false;
			$definitions = json_decode($rawValue, true, 64);
			if (!is_array($definitions) || count($definitions) > 100) return false;
			foreach ($definitions as $name => $options) {
				if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $name) !== 1 ||
					!is_array($options) || !isset($options['type']) || !in_array($options['type'], array('string', 'bool'), true)) return false;
			}
			return true;
		}
		if (is_string($rawValue) && (strlen($rawValue) > 100000 || strpos($rawValue, "\0") !== false)) return false;
		return true;
	}

	private function legacyPluginDatabase($directory)
	{
		if (!is_string($directory) || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $directory) !== 1 ||
			!defined('PATH_PLUGINS_DATABASES')) return array();
		$file = PATH_PLUGINS_DATABASES . $directory . DS . 'db.php';
		if (Filesystem::readFileBounded($file, 1048576, 2) === false) return array();
		$db = new BrendyJsonDatabase($file);
		return is_array($db->db) ? $db->db : array();
	}

	private function migrateRetiredField($field, $candidate, &$changed)
	{
		if (!is_string($field) || !array_key_exists($field, $this->dbFields) || array_key_exists($field, $this->db)) return false;
		$default = $this->dbFields[$field];
		// Legacy BrendyPlugin string values already carry one stored HTML-entity
		// layer. Decode exactly once, then pass them through the normal Site
		// normalization path so migration neither double-encodes nor bypasses
		// the current field contract.
		if (is_string($default) && (is_string($candidate) || is_numeric($candidate))) {
			$candidate = Sanitize::htmlDecode((string)$candidate);
		}
		$valid = false;
		$value = $this->normalizeField($candidate, $default, $valid);
		if (!$valid || !$this->validateField($field, $value)) return false;
		$this->db[$field] = $value;
		$changed = true;
		return true;
	}

	private function migrateRetiredPluginSettings(&$changed)
	{
		$changed = (bool)$changed;
		$og = $this->legacyPluginDatabase('opengraph');
		$twitter = $this->legacyPluginDatabase('twitter-cards');
		$alternativa = $this->legacyPluginDatabase('alternative');
		$fokus = $this->legacyPluginDatabase('popeye');
		$readingProgress = $this->legacyPluginDatabase('reading-progress');
		$robots = $this->legacyPluginDatabase('robots');
		$maintenance = $this->legacyPluginDatabase('maintenance-mode');

		$image = '';
		if (isset($og['defaultImage']) && is_string($og['defaultImage'])) $image = $og['defaultImage'];
		if (trim(Sanitize::htmlDecode($image)) === '' && isset($twitter['defaultImage']) && is_string($twitter['defaultImage'])) {
			$image = $twitter['defaultImage'];
		}
		if ($image !== '') $this->migrateRetiredField('seoSocialImage', $image, $changed);
		if (isset($og['fbAppId'])) $this->migrateRetiredField('seoFacebookAppId', $og['fbAppId'], $changed);
		if (isset($twitter['twitterSite'])) $this->migrateRetiredField('seoTwitterSite', $twitter['twitterSite'], $changed);
		if (isset($twitter['cardType'])) $this->migrateRetiredField('seoTwitterCard', $twitter['cardType'], $changed);
		if (isset($robots['robotstxt'])) $this->migrateRetiredField('robotsTxt', $robots['robotstxt'], $changed);
		if (array_key_exists('enable', $maintenance)) $this->migrateRetiredField('maintenanceMode', $maintenance['enable'], $changed);
		if (isset($maintenance['message'])) $this->migrateRetiredField('maintenanceMessage', $maintenance['message'], $changed);

		if (array_key_exists('showPostInformation', $alternativa)) {
			$this->migrateRetiredField('alternativaShowPostInformation', $alternativa['showPostInformation'], $changed);
		}
		if (isset($alternativa['dateFormat'])) $this->migrateRetiredField('alternativaDateFormat', $alternativa['dateFormat'], $changed);
		if (array_key_exists('darkMode', $fokus)) $this->migrateRetiredField('fokusDarkMode', $fokus['darkMode'], $changed);
		if (isset($fokus['dateFormat'])) $this->migrateRetiredField('fokusDateFormat', $fokus['dateFormat'], $changed);
		if (array_key_exists('showTags', $fokus)) $this->migrateRetiredField('fokusShowTags', $fokus['showTags'], $changed);

		// Reading Progress became a core visitor-experience feature in Brendy 2.6.
		// Accept the 2.5 namespaced field and the early top/bottom legacy value,
		// but never mistake the numeric reserved plugin-order position for it.
		if (isset($readingProgress['progressPosition'])) {
			$this->migrateRetiredField('readingProgressPosition', $readingProgress['progressPosition'], $changed);
		} elseif (isset($readingProgress['position']) && is_string($readingProgress['position'])) {
			$legacyPosition = strtolower(trim(Sanitize::htmlDecode($readingProgress['position'])));
			if (in_array($legacyPosition, array('top', 'bottom'), true)) {
				$this->migrateRetiredField('readingProgressPosition', $legacyPosition, $changed);
			}
		}
		if (array_key_exists('progressHeight', $readingProgress)) {
			$this->migrateRetiredField('readingProgressHeight', $readingProgress['progressHeight'], $changed);
		}
		if (isset($readingProgress['progressColor'])) {
			$this->migrateRetiredField('readingProgressColor', $readingProgress['progressColor'], $changed);
		}
	}

	// Returns an array with the URL filters
	// Also, you can get a particular filter
	public function uriFilters($filter = '')
	{
		if (!is_string($filter)) return false;
		$filters['admin'] = '/' . ADMIN_URI_FILTER . '/';
		$filters['page'] = $this->getField('uriPage');
		$filters['tag'] = $this->getField('uriTag');
		$filters['category'] = $this->getField('uriCategory');

		if ($this->getField('uriBlog')) {
			$filters['blog'] = $this->getField('uriBlog');
		}

		if ($filter === '') {
			return $filters;
		}

		if (isset($filters[$filter])) {
			return $filters[$filter];
		}

		return false;
	}


	// DEPRECATED in v3.0, use Theme::sitemapUrl()
	public function sitemap()
	{
		return DOMAIN_BASE . 'sitemap.xml';
	}

	public function thumbnailEnable()
	{
		return $this->getField('thumbnailEnable');
	}

	public function thumbnailWidth()
	{
		return $this->getField('thumbnailWidth');
	}

	public function thumbnailHeight()
	{
		return $this->getField('thumbnailHeight');
	}

	public function thumbnailQuality()
	{
		return $this->getField('thumbnailQuality');
	}

	public function autosaveInterval()
	{
		return $this->getField('autosaveInterval');
	}

	public function extremeFriendly()
	{
		return $this->getField('extremeFriendly');
	}

	public function markdownParser()
	{
		return $this->getField('markdownParser');
	}

	public function twitter()
	{
		return $this->getField('twitter');
	}

	public function facebook()
	{
		return $this->getField('facebook');
	}

	public function codepen()
	{
		return $this->getField('codepen');
	}

	public function instagram()
	{
		return $this->getField('instagram');
	}

	public function github()
	{
		return $this->getField('github');
	}

	public function gitlab()
	{
		return $this->getField('gitlab');
	}

	public function linkedin()
	{
		return $this->getField('linkedin');
	}

	public function xing()
	{
		return $this->getField('xing');
	}

	public function telegram()
	{
		return $this->getField('telegram');
	}

	public function mastodon()
	{
		return $this->getField('mastodon');
	}

	public function dribbble()
	{
		return $this->getField('dribbble');
	}

	public function vk()
	{
		return $this->getField('vk');
	}

	public function youtube()
	{
		return $this->getField('youtube');
	}

	public function bluesky()
	{
		return $this->getField('bluesky');
	}

	public function orderBy()
	{
		return $this->getField('orderBy');
	}

	public function defaultContentStatus()
	{
		return $this->getField('defaultContentStatus');
	}

	public function accentColor()
	{
		return Sanitize::htmlDecode($this->getField('accentColor'));
	}

	public function colorScheme()
	{
		return Sanitize::htmlDecode($this->getField('colorScheme'));
	}

	public function contentWidth()
	{
		return Sanitize::htmlDecode($this->getField('contentWidth'));
	}

	public function typographyScale()
	{
		return Sanitize::htmlDecode($this->getField('typographyScale'));
	}

	public function cardStyle()
	{
		return Sanitize::htmlDecode($this->getField('cardStyle'));
	}

	public function animationLevel()
	{
		return Sanitize::htmlDecode($this->getField('animationLevel'));
	}

	public function roundedCards()
	{
		return $this->getField('roundedCards');
	}

	public function showReadingProgress()
	{
		return $this->getField('showReadingProgress');
	}

	public function readingProgressPosition()
	{
		$value = Sanitize::htmlDecode($this->getField('readingProgressPosition'));
		return in_array($value, array('top', 'bottom'), true) ? $value : 'top';
	}

	public function readingProgressHeight()
	{
		return max(2, min(10, (int)$this->getField('readingProgressHeight')));
	}

	public function readingProgressColor()
	{
		$value = Sanitize::htmlDecode($this->getField('readingProgressColor'));
		return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1 ? strtolower($value) : '#2563eb';
	}

	public function showBackToTop()
	{
		return $this->getField('showBackToTop');
	}

	public function showPostMeta()
	{
		return $this->getField('showPostMeta');
	}

	public function showReadingTime()
	{
		return $this->getField('showReadingTime');
	}

	public function showArticleTools()
	{
		return $this->getField('showArticleTools');
	}

	public function showRelatedContent()
	{
		return $this->getField('showRelatedContent');
	}

	public function enableImageLazyLoading()
	{
		return $this->getField('enableImageLazyLoading');
	}

	public function imageRestrict()
	{
		return $this->getField('imageRestrict');
	}

	public function imageRelativeToAbsolute()
	{
		return $this->getField('imageRelativeToAbsolute');
	}

	// Returns the site title
	public function title()
	{
		return $this->getField('title');
	}

	// Returns the site slogan
	public function slogan()
	{
		return $this->getField('slogan');
	}

	// Returns the site description
	public function description()
	{
		return $this->getField('description');
	}

	public function emailFrom()
	{
		return $this->getField('emailFrom');
	}

	public function dateFormat()
	{
		return $this->getField('dateFormat');
	}

	public function timeFormat()
	{
		return $this->getField('timeFormat');
	}

	// Returns the site theme name
	public function theme()
	{
		$theme = $this->getField('theme');
		$aliases = self::legacyThemeAliases();
		return (is_string($theme) && isset($aliases[$theme])) ? $aliases[$theme] : $theme;
	}

	// Returns the admin theme name
	public function adminTheme()
	{
		return $this->getField('adminTheme');
	}

	// Returns the footer text
	public function footer()
	{
		return $this->getField('footer');
	}

	public function titleFormatPages()
	{
		return $this->getField('titleFormatPages');
	}

	public function titleFormatHomepage()
	{
		return $this->getField('titleFormatHomepage');
	}

	public function titleFormatCategory()
	{
		return $this->getField('titleFormatCategory');
	}

	public function titleFormatTag()
	{
		return $this->getField('titleFormatTag');
	}


	public function seoSocialImage()
	{
		return Sanitize::htmlDecode($this->getField('seoSocialImage'));
	}

	public function seoFacebookAppId()
	{
		return Sanitize::htmlDecode($this->getField('seoFacebookAppId'));
	}

	public function seoTwitterSite()
	{
		return Sanitize::htmlDecode($this->getField('seoTwitterSite'));
	}

	public function seoTwitterCard()
	{
		return Sanitize::htmlDecode($this->getField('seoTwitterCard'));
	}

	public function robotsTxt()
	{
		$value = Sanitize::htmlDecode($this->getField('robotsTxt'));
		if (!is_string($value)) return "User-agent: *\nAllow: /";
		$value = str_replace(array("\r\n", "\r"), "\n", $value);
		$value = preg_replace('/[^\P{C}\n\t]/u', '', $value);
		return is_string($value) ? substr($value, 0, 100000) : "User-agent: *\nAllow: /";
	}

	public function maintenanceMode()
	{
		return (bool)$this->getField('maintenanceMode');
	}

	public function maintenanceMessage()
	{
		$value = Sanitize::htmlDecode($this->getField('maintenanceMessage'));
		return is_string($value) && trim($value) !== '' ? trim($value) : 'Web-stranica je privremeno nedostupna zbog održavanja.';
	}

	public function alternativaShowPostInformation()
	{
		return (bool)$this->getField('alternativaShowPostInformation');
	}

	public function alternativaDateFormat()
	{
		return Sanitize::htmlDecode($this->getField('alternativaDateFormat'));
	}

	public function fokusDarkMode()
	{
		return (bool)$this->getField('fokusDarkMode');
	}

	public function fokusDateFormat()
	{
		return Sanitize::htmlDecode($this->getField('fokusDateFormat'));
	}

	public function fokusShowTags()
	{
		return (bool)$this->getField('fokusShowTags');
	}

	// Returns the absolute URL of the site logo
	// If you set $absolute=false returns only the filename
	public function logo($absolute = true)
	{
		// Settings are stored with one legacy HTML-entity layer. Filesystem calls
		// need the real filename, while URLs need percent-encoding per path segment.
		$logo = Sanitize::htmlDecode($this->getField('logo'));
		if (!is_string($logo) || $logo === '') return false;
		if (basename($logo) !== $logo || strpos($logo, "\0") !== false ||
			strpos($logo, '/') !== false || strpos($logo, '\\') !== false) return false;
		if (!$absolute) return $logo;

		$path = PATH_UPLOADS . $logo;
		if (!is_file($path) || is_link($path)) return false;
		$version = Filesystem::fileVersionToken($path, (string)BRENDY_BUILD);
		return DOMAIN_UPLOADS . rawurlencode($logo) . '?version=' . rawurlencode($version);
	}

	// Returns the full domain and base url
	// For example, https://www.domain.com/brendy
	public function url()
	{
		return $this->getField('url');
	}

	// Returns the protocol and the domain, without the base url
	// For example, http://www.domain.com
	public function domain()
	{
		// If the URL field is not set, tries to detect the domain.
		if (Text::isEmpty($this->url())) {
			$https = isset($_SERVER['HTTPS']) && is_string($_SERVER['HTTPS']) &&
				$_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off';
			if ($https || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)) {
				$protocol = 'https://';
			} else {
				$protocol = 'http://';
			}

			// HTTP_HOST is client-controlled. Use the server-configured name when a
			// canonical URL was not supplied in settings.
			$serverName = isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME']) ? trim($_SERVER['SERVER_NAME']) : '';
			$parsedHost = preg_match('/[\x00-\x20\x7F,]/', $serverName) ? false : parse_url('http://' . $serverName);
			$parsedName = is_array($parsedHost) && isset($parsedHost['host']) ? $parsedHost['host'] : '';
			$validationName = trim($parsedName, '[]');
			if ($parsedName === '' || isset($parsedHost['user']) || isset($parsedHost['pass']) ||
				(!filter_var($validationName, FILTER_VALIDATE_IP) &&
				 preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $validationName) !== 1)) {
				$host = 'localhost';
			} else {
				$host = strpos($validationName, ':') !== false ? '[' . $validationName . ']' : strtolower($validationName);
			}
			$serverPort = isset($_SERVER['SERVER_PORT']) && is_scalar($_SERVER['SERVER_PORT'])
				? filter_var($_SERVER['SERVER_PORT'], FILTER_VALIDATE_INT) : false;
			$defaultPort = $protocol === 'https://' ? 443 : 80;
			if ($serverPort !== false && $serverPort >= 1 && $serverPort <= 65535 && $serverPort !== $defaultPort) {
				$host .= ':' . $serverPort;
			}
			return $protocol . $host;
		}

		// Parse the domain from the field url (Settings->Advanced)
		$parse = parse_url(Sanitize::htmlDecode($this->url()));
		if (!is_array($parse) || empty($parse['host']) || empty($parse['scheme'])) return 'http://localhost';
		$domain = rtrim($parse['host'], '/');
		$port = isset($parse['port']) && $parse['port'] >= 1 && $parse['port'] <= 65535 ? ':' . $parse['port'] : '';
		$scheme = !empty($parse['scheme']) ? $parse['scheme'] . '://' : 'http://';

		return $scheme . $domain . $port;
	}

	// Returns the timezone.
	public function timezone()
	{
		return $this->getField('timezone');
	}

	public function urlPath()
	{
		$url = Sanitize::htmlDecode($this->getField('url'));
		$path = parse_url($url, PHP_URL_PATH);
		return is_string($path) && $path !== '' ? $path : '/';
	}

	public function isHTTPS()
	{
		$url = Sanitize::htmlDecode($this->getField('url'));
		$scheme = is_string($url) && $url !== '' ? parse_url($url, PHP_URL_SCHEME) : null;
		if (is_string($scheme) && $scheme !== '') {
			return strtolower($scheme) === 'https';
		}
		$https = isset($_SERVER['HTTPS']) && is_string($_SERVER['HTTPS'])
			? strtolower(trim($_SERVER['HTTPS'])) : '';
		return ($https !== '' && $https !== 'off' && $https !== '0') ||
			(isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
	}

	// Returns the current Brendy build.
	public function currentBuild()
	{
		return $this->getField('currentBuild');
	}

	// Returns the amount of pages per page
	public function itemsPerPage()
	{
		$value = filter_var($this->getField('itemsPerPage'), FILTER_VALIDATE_INT);
		if ($value !== false && ($value === -1 || $value > 0)) {
			return $value;
		}
		return 6;
	}

	// Returns the current language.
	public function language()
	{
		return $this->getField('language');
	}

	// Returns the sort version of the site's language
	public function languageShortVersion()
	{
		$current = $this->language();
		$explode = explode('_', $current);
		return $explode[0];
	}

	// Returns the current locale.
	public function locale()
	{
		return $this->getField('locale');
	}

	// Returns the current homepage, FALSE if not defined homepage
	public function homepage()
	{
		$homepage = $this->getField('homepage');
		return is_string($homepage) && $homepage !== '' ? $homepage : false;
	}

	// Returns the page key for the page not found
	public function pageNotFound()
	{
		$pageNotFound = $this->getField('pageNotFound');
		return $pageNotFound;
	}

	// Set the locale, returns TRUE is success, FALSE otherwise
	public function setLocale($locale)
	{
		if (!is_string($locale) || strlen($locale) > 1000) return false;
		$localeList = explode(',', $locale);
		foreach ($localeList as $locale) {
			$locale = trim($locale);
			if (setlocale(LC_ALL, $locale . '.UTF-8') !== false) {
				return true;
			} elseif (setlocale(LC_ALL, $locale) !== false) {
				return true;
			}
		}

		// Not was possible to set a locale, using default locale
		return false;
	}

	// Set the timezone.
	public function setTimezone($timezone)
	{
		return is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true)
			? date_default_timezone_set($timezone) : false;
	}

	// Returns the custom fields as array
	public function customFields()
	{
		$customFields = Sanitize::htmlDecode($this->getField('customFields'));
		$decoded = is_string($customFields) ? json_decode($customFields, true, 64) : null;
		return is_array($decoded) ? $decoded : array();
	}
}
