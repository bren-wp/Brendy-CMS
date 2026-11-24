<?php defined('BRENDY') or die('Brendy CMS.');

class Page
{

	protected $vars;

	function __construct($key)
	{
		global $pages;

		if ($key !== false && (!is_string($key) || strlen($key) > 1000 || strpos($key, "\0") !== false)) {
			throw new InvalidArgumentException('Invalid page key.');
		}

		$this->vars['key'] = $key;

		// If key is FALSE, the page is created with default values, like an empty page
		// Useful for Page Not Found
		if ($key === false) {
			$row = $pages->getDefaultFields();
		} else {
			if (Text::isEmpty($key) || !$pages->exists($key)) {
				$errorMessage = 'Page not found in database by key [' . $key . ']';
				Log::set(__METHOD__ . LOG_SEP . $errorMessage);
				throw new Exception($errorMessage);
			}
			$row = $pages->getPageDB($key);
		}

		foreach ($row as $field => $value) {
			if ($field === 'date') {
				$this->setField('dateRaw', $value);
			} else {
				$this->setField($field, $value);
			}
		}
	}

	public function getValue($field)
	{
		if (isset($this->vars[$field])) {
			return $this->vars[$field];
		}
		return false;
	}

	public function setField($field, $value)
	{
		$this->vars[$field] = $value;
		return true;
	}

	// Returns the raw content
	// This content is not markdown parser
	// (boolean) $sanitize, TRUE returns the content sanitized
	public function contentRaw($sanitize = false)
	{
		$key = $this->key();
		if (!is_string($key) || $key === '') {
			return '';
		}
		$filePath = PATH_PAGES . $key . DS . FILENAME;
		if (!is_file($filePath) || is_link($filePath) || !Sanitize::pathFile(PATH_PAGES, $key . DS . FILENAME)) {
			return '';
		}
		$contentRaw = Filesystem::readFileBounded($filePath, BRENDY_MAX_CONTENT_BYTES, 0);
		if (!is_string($contentRaw)) return '';

		if ($sanitize) {
			return Sanitize::html($contentRaw);
		}
		return $contentRaw;
	}

	// Returns the full content
	// This content is markdown parser
	// (boolean) $sanitize, TRUE returns the content sanitized
	public function content($sanitize = false)
	{
		// If already set the content, sanitize it before public rendering as well.
		$content = $this->getValue('content');
		if (is_string($content) && $content !== '') {
			$content = brendySanitizeContentHtml($content);
			return $sanitize ? Sanitize::html($content) : $content;
		}

		// Get the raw content
		$content = $this->contentRaw();

		// Parse Markdown
		if (MARKDOWN_PARSER) {
			$parsedown = new Parsedown();
			$content = $parsedown->text($content);
		}

		// Parse img src relative to absolute (with domain)
		if (IMAGE_RELATIVE_TO_ABSOLUTE) {
			$domain = IMAGE_RESTRICT ? DOMAIN_UPLOADS_PAGES . $this->uuid() . '/' : DOMAIN_UPLOADS;
			$content = Text::imgRel2Abs($content, $domain);
		}

		$content = brendySanitizeContentHtml($content);
		if ($sanitize) {
			return Sanitize::html($content);
		}
		return $content;
	}

	// Returns the first part of the content if the content is splited, otherwise is returned the full content
	// This content is markdown parser
	// (boolean) $sanitize, TRUE returns the content sanitized
	public function contentBreak($sanitize = false)
	{
		$content = $this->content($sanitize);
		$explode = explode(PAGE_BREAK, $content);
		return $explode[0];
	}

	// Returns the date according to locale settings and the format defined in the system
	public function date($format = false)
	{
		$dateRaw = $this->dateRaw();
		if ($format === false) {
			global $site;
			$format = $site->dateFormat();
		}
		return Date::format($dateRaw, DB_DATE_FORMAT, $format);
	}

	// Returns the date according to locale settings and format as database stored
	public function dateRaw()
	{
		// This field is set in the constructor
		return $this->getValue('dateRaw');
	}

	// Returns the date according to locale settings and format settings
	public function dateModified($format = false)
	{
		$dateRaw = $this->getValue('dateModified');
		if ($format === false) {
			global $site;
			$format = $site->dateFormat();
		}
		return Date::format($dateRaw, DB_DATE_FORMAT, $format);
	}

	// Returns the username who created the page
	public function username()
	{
		return $this->getValue('username');
	}

	// Returns the normalized page fields for compatibility with extensions that
	// need a read-only snapshot of the current Page object.
	public function getDB()
	{
		return $this->vars;
	}

	// Returns the permalink
	// (boolean) $absolute, TRUE returns the page link with the DOMAIN, FALSE without the DOMAIN
	public function permalink($absolute = true)
	{
		// Get the key of the page
		$key = $this->key();

		if ($absolute) {
			return DOMAIN_PAGES . $key;
		}

		return HTML_PATH_ROOT . PAGE_URI_FILTER . $key;
	}

	// Returns the previous page key
	public function previousKey()
	{
		global $pages;
		return $pages->previousPageKey($this->key());
	}

	// Returns the next page key
	public function nextKey()
	{
		global $pages;
		return $pages->nextPageKey($this->key());
	}

	// Returns the category name
	public function category()
	{
		return $this->categoryMap('name');
	}

	// Returns the category template
	public function categoryTemplate()
	{
		return $this->categoryMap('template');
	}

	// Returns the category description
	public function categoryDescription()
	{
		return $this->categoryMap('description');
	}

	// Returns the category key
	public function categoryKey()
	{
		return $this->getValue('category');
	}

	// Returns the category permalink
	public function categoryPermalink()
	{
		return DOMAIN_CATEGORIES . $this->categoryKey();
	}

	// Returns the field from the array
	// categoryMap = array( 'name'=>'', 'list'=>array() )
	public function categoryMap($field)
	{
		global $categories;
		$categoryKey = $this->categoryKey();
		$map = $categories->getMap($categoryKey);

		if ($field === 'key') {
			return $this->categoryKey();
		} elseif (isset($map[$field])) {
			return $map[$field];
		}

		return false;
	}

	// Returns the user object or passing the method returns the object User method
	public function user($method = false)
	{
		$username = $this->username();
		try {
			$user = new User($username);
			if ($method !== false) {
				if (!is_string($method) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $method) !== 1 ||
					!is_callable(array($user, $method))) return false;
				return $user->{$method}();
			}
			return $user;
		} catch (Throwable $e) {
			return false;
		}
	}

	public function template()
	{
		return $this->getValue('template');
	}

	// Returns the description field
	public function description()
	{
		return $this->getValue('description');
	}

	// Returns the tags separated by comma
	// (boolean) $returnsArray, TRUE to get the tags as an array, FALSE to get the tags separated by comma
	// The tags in array format returns array( tagKey => tagName )
	public function tags($returnsArray = false)
	{
		$tags = $this->getValue('tags');
		if (!is_array($tags)) {
			$tags = array();
		}
		// Defensively encode legacy database values as well as newly stored tags.
		foreach ($tags as $key => $value) {
			if (!is_string($key) || (!is_string($value) && !is_numeric($value))) {
				unset($tags[$key]);
				continue;
			}
			$value = (string)$value;
			if (strlen($key) > 200 || strlen($value) > 500 || strpos($key . $value, "\0") !== false) {
				unset($tags[$key]);
				continue;
			}
			$decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, CHARSET);
			$tags[$key] = htmlspecialchars($decoded, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, CHARSET);
		}
		if ($returnsArray) {
			if (empty($tags)) {
				return array();
			}
			return $tags;
		}

		if (empty($tags)) {
			return '';
		}
		// Return string with tags separated by comma.
		return implode(',', $tags);
	}



	public function json($returnsArray = false)
	{
		$tmp['key'] 		= $this->key();
		$tmp['title'] 		= $this->title();
		$tmp['content'] 	= $this->content(); // Markdown parsed
		$tmp['contentRaw'] 	= $this->contentRaw(true); // No Markdown parsed
		$tmp['description'] 	= $this->description();
		$tmp['type'] 		= $this->type();
		$tmp['slug'] 		= $this->slug();
		$tmp['date'] 		= $this->date();
		$tmp['dateRaw'] 	= $this->dateRaw();
		$tmp['tags'] 		= $this->tags(false);
		$tmp['username'] 	= $this->username();
		$tmp['category'] 	= $this->category();
		$tmp['uuid'] 		= $this->uuid();
		$tmp['dateUTC']		= Date::convertToUTC($this->dateRaw(), DB_DATE_FORMAT, DB_DATE_FORMAT);
		$tmp['permalink'] 	= $this->permalink(true);
		$tmp['coverImage'] 		= $this->coverImage(true);
		$tmp['coverImageFilename'] 	= $this->coverImage(false);

		if ($returnsArray) {
			return $tmp;
		}

		return json_encode($tmp);
	}

	// Returns the endpoint of the coverimage, FALSE if the page doesn't have a cover image
	// (boolean) $absolute, TRUE returns the complete URL, FALSE returns the filename
	// If the user defined an external cover image the function returns it
	public function coverImage($absolute = true)
	{
		$stored = $this->getValue('coverImage');
		if (!is_string($stored) || $stored === '') {
			return false;
		}
		$filename = Sanitize::htmlDecode($stored);

		// Accept external images only over HTTP(S), without credentials or control
		// characters. This also protects pages imported from older databases.
		$urlParts = parse_url($filename);
		if (filter_var($filename, FILTER_VALIDATE_URL) && is_array($urlParts) &&
			isset($urlParts['scheme'], $urlParts['host']) &&
			in_array(strtolower($urlParts['scheme']), array('http', 'https'), true) &&
			!isset($urlParts['user']) && !isset($urlParts['pass']) && !preg_match('/[\x00-\x20\x7F]/', $filename)) {
			return $filename;
		}
		$extension = Text::lowercase(Filesystem::extension($filename));
		if (basename($filename) !== $filename || preg_match('/[\x00-\x1F\x7F]/', $filename) ||
			!in_array($extension, $GLOBALS['ALLOWED_IMG_EXTENSION'], true)) {
			return false;
		}

		if ($absolute) {
			$encodedFilename = rawurlencode($filename);
			if (IMAGE_RESTRICT) {
				$uuid = $this->uuid();
				if ($uuid === false || strpos($uuid, 'autosave-') === 0) return false;
				return DOMAIN_UPLOADS_PAGES . rawurlencode($uuid) . '/' . $encodedFilename;
			}
			return DOMAIN_UPLOADS . $encodedFilename;
		}

		return $filename;
	}

	// Returns the endpoint of the thumbnail cover image, FALSE if the page doesn't have a cover image
	public function thumbCoverImage()
	{
		$filename = $this->coverImage(false);
		if ($filename === false) {
			return false;
		}

		$urlParts = parse_url($filename);
		if (filter_var($filename, FILTER_VALIDATE_URL) && is_array($urlParts) &&
			isset($urlParts['scheme'], $urlParts['host']) &&
			in_array(strtolower($urlParts['scheme']), array('http', 'https'), true) &&
			!isset($urlParts['user']) && !isset($urlParts['pass'])) {
			return $filename;
		}

		$encodedFilename = rawurlencode($filename);
		if (IMAGE_RESTRICT) {
			$uuid = $this->uuid();
			if ($uuid === false || strpos($uuid, 'autosave-') === 0) return false;
			return DOMAIN_UPLOADS_PAGES . rawurlencode($uuid) . '/thumbnails/' . $encodedFilename;
		}
		return DOMAIN_UPLOADS_THUMBNAILS . $encodedFilename;
	}

	// Returns TRUE if the content has the text splited
	public function readMore()
	{
		$content = $this->contentRaw();
		return Text::stringContains($content, PAGE_BREAK);
	}

	public function uuid()
	{
		$uuid = $this->getValue('uuid');
		return is_string($uuid) && preg_match('/^(?:autosave-)?[a-f0-9]{32}$/iD', $uuid) === 1 ? $uuid : false;
	}

	// Returns the field key
	public function key()
	{
		return $this->getValue('key');
	}

	// (boolean) Returns TRUE if the page is published, FALSE otherwise
	public function published()
	{
		return ($this->getValue('type') === 'published');
	}

	// (boolean) Returns TRUE if the page is scheduled, FALSE otherwise
	public function scheduled()
	{
		return ($this->getValue('type') === 'scheduled');
	}

	// (boolean) Returns TRUE if the page is draft, FALSE otherwise
	public function draft()
	{
		return ($this->getValue('type') === 'draft');
	}

	// (boolean) Returns TRUE if the page is autosave, FALSE otherwise
	public function autosave()
	{
		return ($this->getValue('type') === 'autosave');
	}

	// (boolean) Returns TRUE if the page is sticky, FALSE otherwise
	public function sticky()
	{
		return ($this->getValue('type') === 'sticky');
	}

	// (boolean) Returns TRUE if the page is static, FALSE otherwise
	public function isStatic()
	{
		return ($this->getValue('type') === 'static');
	}

	// (string) Returns type of the page
	public function type()
	{
		return $this->getValue('type');
	}

	// Returns the title field
	public function title()
	{
		return $this->getValue('title');
	}

	// Returns TRUE if the page has enabled the comments, FALSE otherwise
	public function allowComments()
	{
		return $this->getValue('allowComments');
	}

	// Returns the page position
	public function position()
	{
		return $this->getValue('position');
	}

	// Returns the page noindex
	public function noindex()
	{
		return $this->getValue('noindex');
	}

	// Returns the page nofollow
	public function nofollow()
	{
		return $this->getValue('nofollow');
	}

	// Returns the page noarchive
	public function noarchive()
	{
		return $this->getValue('noarchive');
	}

	// Returns the page slug
	public function slug()
	{
		$explode = explode('/', $this->key());
		return end($explode);
	}

	// Returns the parent key, if the page doesn't have a parent returns FALSE
	public function parent()
	{
		return $this->parentKey();
	}

	// Returns the parent key, if the page doesn't have a parent returns FALSE
	public function parentKey()
	{
		$key = $this->key();
		if (!is_string($key) || $key === '') return false;

		// Keys encode the complete hierarchy (for example section/topic/post).
		// The immediate parent is therefore everything before the final slash,
		// not the first path segment. The previous implementation returned
		// "section" for "section/topic/post", skipping one hierarchy level.
		$separator = strrpos($key, '/');
		if ($separator === false || $separator < 1 || $separator >= strlen($key) - 1) return false;
		$parent = substr($key, 0, $separator);
		return $parent !== '' ? $parent : false;
	}

	// Returns TRUE if the page is a parent, has or not children
	public function isParent()
	{
		return $this->parentKey() === false;
	}

	// Returns the parent method output, if the page doesn't have a parent returns FALSE
	public function parentMethod($method)
	{
		if (!is_string($method) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $method) !== 1) return false;
		$parentKey = $this->parentKey();
		if ($parentKey !== false) {
			try {
				$page = new Page($parentKey);
				if (!is_callable(array($page, $method))) return false;
				return $page->{$method}();
			} catch (Throwable $e) {
				// Invalid/corrupted parent records fail closed.
			}
		}

		return false;
	}

	// Returns TRUE if the page is a child, FALSE otherwise
	public function isChild()
	{
		return $this->parentKey() !== false;
	}

	// Returns TRUE if the page has children
	public function hasChildren()
	{
		$childrenKeys = $this->childrenKeys();
		return !empty($childrenKeys);
	}

	// Returns an array with all children's keys
	public function childrenKeys()
	{
		global $pages;
		$key = $this->key();
		return $pages->getChildren($key);
	}

	// Returns an array with all children as Page-Object
	public function children()
	{
		global $pages;
		$list = array();
		$childrenKeys = $pages->getChildren($this->key());
		foreach ($childrenKeys as $childKey) {
			try {
				$child = new Page($childKey);
				array_push($list, $child);
			} catch (Throwable $e) {
				// Continue
			}
		}

		return $list;
	}

	// Returns a Unicode-aware word count without counting HTML tag names.
	public function wordCount()
	{
		$text = html_entity_decode(strip_tags($this->content()), ENT_QUOTES | ENT_HTML5, CHARSET);
		$matched = @preg_match_all("/[\p{L}\p{N}]+(?:[’'-][\p{L}\p{N}]+)*/u", $text, $matches);
		if ($matched === false) {
			$asciiText = preg_replace('/[^\x20-\x7E]+/', ' ', $text);
			$matched = str_word_count(is_string($asciiText) ? $asciiText : '');
		}
		return max(0, (int)$matched);
	}

	public function readingMinutes()
	{
		return max(1, (int)ceil($this->wordCount() / 200));
	}

	// Returns a localized estimate of the time needed to read the page.
	public function readingTime()
	{
		$minutes = $this->readingMinutes();

		$label = $this->relativeTimeUnit('minute', $minutes);
		return ($minutes === 1 ? '~' : '') . $label;
	}

	// Returns relative time (e.g. "1 minute ago")
	// Based on http://stackoverflow.com/a/18602474
	// Adapted for Brendy content rendering.
	// $complete = false : short version
	// $complete = true  : full version
	public function relativeTime($complete = false)
	{
		$dateRaw = $this->getValue('dateRaw');
		if (!is_string($dateRaw) || $dateRaw === '' || strlen($dateRaw) > 128) {
			return $this->relativeTimeMessage('now');
		}

		try {
			$current = new DateTime();
			$event = new DateTime($dateRaw);
		} catch (Throwable $e) {
			return $this->relativeTimeMessage('now');
		}

		$elapsed = $current->diff($event);
		$weeks = (int)floor($elapsed->d / 7);
		$days = $elapsed->d - ($weeks * 7);
		$values = array(
			'year' => (int)$elapsed->y,
			'month' => (int)$elapsed->m,
			'week' => $weeks,
			'day' => $days,
			'hour' => (int)$elapsed->h,
			'minute' => (int)$elapsed->i,
			'second' => (int)$elapsed->s
		);

		$parts = array();
		foreach ($values as $unit => $amount) {
			if ($amount > 0) {
				$parts[] = $this->relativeTimeUnit($unit, $amount);
				if (!$complete) break;
			}
		}

		if (empty($parts)) return $this->relativeTimeMessage('now');
		$isFuture = $event->getTimestamp() > $current->getTimestamp();
		return $this->relativeTimeMessage($isFuture ? 'future' : 'past', implode(', ', $parts));
	}

	private function relativeTimeUnit($unit, $amount)
	{
		global $L;
		$amount = max(0, (int)$amount);
		$language = $L->currentLanguageShortVersion();

		if ($language === 'hr') {
			$lastTwo = $amount % 100;
			$last = $amount % 10;
			if ($last === 1 && $lastTwo !== 11) {
				$form = 'one';
			} elseif ($last >= 2 && $last <= 4 && ($lastTwo < 12 || $lastTwo > 14)) {
				$form = 'few';
			} else {
				$form = 'many';
			}
			return $amount . ' ' . $L->get('relative-' . $unit . '-' . $form);
		}

		// English is the compatibility fallback for dictionaries that do not yet
		// contain Brendy's relative-time keys.
		$english = array(
			'year' => 'year', 'month' => 'month', 'week' => 'week',
			'day' => 'day', 'hour' => 'hour', 'minute' => 'minute', 'second' => 'second'
		);
		$label = isset($english[$unit]) ? $english[$unit] : 'unit';
		return $amount . ' ' . $label . ($amount === 1 ? '' : 's');
	}

	private function relativeTimeMessage($type, $value = '')
	{
		global $L;
		$language = $L->currentLanguageShortVersion();
		if ($language === 'hr') {
			if ($type === 'now') return $L->get('relative-time-now');
			return sprintf($L->get('relative-time-' . $type), $value);
		}
		if ($type === 'now') return 'Just now';
		return $type === 'future' ? 'In ' . $value : $value . ' ago';
	}

	// Returns the value from the field, false if the fields don't exist
	// If you set the $option as TRUE, the function returns an array with all the values of the field
	public function custom($field, $options = false)
	{
		if (isset($this->vars['custom'][$field])) {
			if ($options) {
				return $this->vars['custom'][$field];
			}
			return $this->vars['custom'][$field]['value'];
		}
		return false;
	}

	// Returns an array with all pages key related to the page
	// The relation is based on the tags
	public function related()
	{
		global $tags;
		$pageTags = $this->tags(true);
		$list = array();
		// For each tag get the list of related pages
		foreach ($pageTags as $tagKey => $tagName) {
			$pagesRelated = $tags->getList($tagKey, 1, -1);
			if (is_array($pagesRelated)) {
				$list = array_merge($list, $pagesRelated);
			}
		}

		// Remove duplicates
		$list = array_unique($list);

		// Remove himself from the list
		if (($key = array_search($this->key(), $list, true)) !== false) {
			unset($list[$key]);
		}

		return $list;
	}
}
