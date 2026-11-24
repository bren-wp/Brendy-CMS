<?php defined('BRENDY') or die('Brendy CMS.');

class BrendyPlugin
{

	// (string) directory name, just the name
	// Ex: sitemap
	public $directoryName;

	// (string) Absolute database filename and path
	// Ex: /www/brendy/sadrzaj/plugins/sitemap/db.php
	public $filenameDb;

	// (string) Absolute metadata filename and path
	// Ex: /www/brendy/dodaci/sitemap/metadata.json
	public $filenameMetadata;

	// (array) Plugin metadata
	// Ex: array('author'=>'',...., 'notes'=>'')
	public $metadata;

	// (string) Class name
	// Ex: BrendyPluginSitemap
	public $className;

	// (array) Database unserialized
	public $db;

	// (array) Database fields, only for initialize
	public $dbFields;

	// (boolean) Enable or disable default Save and Cancel button on plugin settings
	public $formButtons;

	// (array) List of custom hooks
	public $customHooks;

	// Operation locks shared by plugins that perform destructive or long-running
	// filesystem mutations. Keeping this in the base class prevents extensions
	// from reimplementing subtly different lock semantics.
	private $operationLocks = array();

	// Extension-owned settings must never shadow fields used internally by the
	// plugin loader. Conflicts are retained for diagnostics and then ignored.
	private $reservedFieldConflicts = array();

	// Keep the JSON store that was loaded with this plugin instance. Reusing the
	// same optimistic-write hash prevents a stale request from silently replacing
	// settings committed by another worker after this object was constructed.
	private $databaseStore = null;

	// Existing plugin state must validate before any prepare() code or runtime
	// hooks are allowed to execute. A corrupt db.php remains administratively
	// visible so it can be diagnosed/uninstalled, but never becomes active code.
	private $databaseStateValid = true;

	function __construct()
	{
		$this->dbFields = array();
		$this->customHooks = array();

		$reflector = new ReflectionClass(get_class($this));

		// Directory name
		$this->directoryName = basename(dirname($reflector->getFileName()));

		// Class Name
		$this->className = $reflector->getName();

		$this->formButtons = true;

		// Call the method init() from the children. The core then reclaims its
		// reserved fields so an extension cannot accidentally shadow loader state.
		$this->init();
		foreach ($this->reservedSettingFields() as $reservedField) {
			if (array_key_exists($reservedField, $this->dbFields)) {
				$this->reservedFieldConflicts[] = $reservedField;
				unset($this->dbFields[$reservedField]);
			}
		}
		// Position is part of every installed plugin database. Register it on every
		// request as well as during installation so sorting and setPosition() do not
		// silently degrade after the first request.
		$this->dbFields['position'] = 1;

		// Init empty database with default values
		$this->db = $this->dbFields;

		$this->filenameDb = PATH_PLUGINS_DATABASES . $this->directoryName . DS . 'db.php';

		// --- Metadata ---
		$this->filenameMetadata = PATH_PLUGINS . $this->directoryName() . DS . 'metadata.json';
		$metadataString = Filesystem::readFileBounded($this->filenameMetadata, 1048576, 2);
		$this->metadata = is_string($metadataString) ? json_decode($metadataString, true, 64) : array();
		if (!is_array($this->metadata)) {
			$this->metadata = array();
		}

		// If the plugin is installed then get the database
		if ($this->installed()) {
			$this->databaseStore = new BrendyJsonDatabase($this->filenameDb);
			if (!$this->databaseStore->loadedSuccessfully()) {
				$this->databaseStateValid = false;
				Log::set(__METHOD__ . LOG_SEP . 'Plugin database failed validation; runtime disabled for ' . $this->directoryName, LOG_TYPE_ERROR);
				return;
			}
			$rawLoaded = is_array($this->databaseStore->db) ? $this->databaseStore->db : array();
			$loaded = $this->migrateLegacySettings($rawLoaded);
			if (!is_array($loaded)) $loaded = array();
			$this->db = $loaded;
			// A migration must be persisted even when every post-migration field is
			// already canonical. Comparing only the migrated array to itself would
			// otherwise repeat the same migration on every request.
			$changed = ($loaded !== $rawLoaded);
			// Canonicalize every declared field, including databases created by older
			// versions. Decode once before normalization to avoid double-encoding
			// already-safe values while still neutralizing raw legacy markup in forms.
			foreach ($this->dbFields as $field => $defaultValue) {
				$candidate = array_key_exists($field, $loaded) ? $loaded[$field] : $defaultValue;
				if (is_string($defaultValue) && (is_string($candidate) || is_numeric($candidate))) {
					$candidate = Sanitize::htmlDecode((string)$candidate);
				}
				$valid = false;
				$normalized = $this->normalizeFieldValue($candidate, $defaultValue, $valid, $field);
				if (!$valid) {
					$fallbackValid = false;
					$normalized = $this->normalizeFieldValue($defaultValue, $defaultValue, $fallbackValid, $field);
					if (!$fallbackValid) continue;
				}
				if (!array_key_exists($field, $loaded) || $loaded[$field] !== $normalized) $changed = true;
				$this->db[$field] = $normalized;
			}
			if ($changed && !$this->save()) {
				$this->databaseStore->recoverPersistedState();
				$this->db = is_array($this->databaseStore->db) ? $this->databaseStore->db : array();
				$this->databaseStateValid = false;
				Log::set(__METHOD__ . LOG_SEP . 'Unable to persist normalized plugin settings; persisted state reloaded and runtime disabled for ' . $this->directoryName, LOG_TYPE_ERROR);
				return;
			}
			// prepare() may have side effects (cache generation, migrations, remote/file
			// setup). Never run it for an extension that cannot enter runtime because
			// of a reserved-field conflict or Extension API incompatibility. Existing
			// extensions may keep returning NULL; an explicit FALSE is a fail-closed
			// signal that preparation could not be persisted safely.
			if (empty($this->reservedFieldConflicts) && $this->isCompatible()) {
				try {
					$prepared = $this->prepare();
				} catch (Throwable $e) {
					$this->databaseStateValid = false;
					Log::set(__METHOD__ . LOG_SEP . 'Plugin prepare failed for ' . $this->directoryName . LOG_SEP .
						substr((string)$e->getMessage(), 0, 500), LOG_TYPE_ERROR);
					return;
				}
				if ($prepared === false) {
					$this->databaseStateValid = false;
					Log::set(__METHOD__ . LOG_SEP . 'Plugin prepare could not commit state; runtime disabled for ' . $this->directoryName, LOG_TYPE_ERROR);
					return;
				}
			}
		}
	}


	/** Core-owned database keys that extensions may not redefine. */
	protected function reservedSettingFields()
	{
		return array('position');
	}

	/**
	 * Upgrade hook executed on the raw persisted plugin database before field
	 * normalization. This is the safe place to rename legacy settings without
	 * losing values that collide with a newer core-reserved field.
	 */
	protected function migrateLegacySettings($loaded)
	{
		return is_array($loaded) ? $loaded : array();
	}

	public function __destruct()
	{
		$this->releaseOperationLock();
	}

	/**
	 * Acquire a non-blocking exclusive lock in Brendy's protected temp area.
	 * The caller supplies only a safe lock basename, never a path. Multiple
	 * different locks can be held by one plugin instance when required.
	 */
	protected function acquireOperationLock($lockName)
	{
		if (!is_string($lockName) || $lockName !== basename($lockName) ||
			preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,126}\.lock$/D', $lockName) !== 1) return false;
		if (isset($this->operationLocks[$lockName]) && is_resource($this->operationLocks[$lockName])) return true;
		if (!Filesystem::directoryExists(PATH_TMP) && !Filesystem::mkdir(PATH_TMP, true)) return false;

		$path = PATH_TMP . $lockName;
		$handle = Filesystem::acquireLock($path, LOCK_EX | LOCK_NB, 0600);
		if ($handle === false) return false;
		$this->operationLocks[$lockName] = $handle;
		return true;
	}

	/**
	 * Release one named operation lock or every lock held by this plugin.
	 */
	protected function releaseOperationLock($lockName = null)
	{
		if ($lockName !== null) {
			if (!is_string($lockName) || !isset($this->operationLocks[$lockName])) return;
			$handles = array($lockName => $this->operationLocks[$lockName]);
		} else {
			$handles = $this->operationLocks;
		}
		foreach ($handles as $name => $handle) {
			if (is_resource($handle)) Filesystem::releaseLock($handle);
			unset($this->operationLocks[$name]);
		}
	}

	/**
	 * Execute a storage mutation under a blocking lock scoped to this plugin's
	 * workspace. This is intended for short JSON/file transactions that must
	 * serialize across concurrent PHP workers. The callback is always released
	 * in a finally block, so an exception cannot strand the request holding a
	 * live lock descriptor.
	 */
	protected function withWorkspaceLock($lockName, $callback)
	{
		if (!is_string($lockName) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,126}$/D', $lockName) !== 1 ||
			!is_callable($callback)) return false;

		$workspace = $this->workspace();
		if ((!Filesystem::directoryExists($workspace) && !Filesystem::mkdir($workspace, true)) ||
			is_link($workspace) || !is_writable($workspace)) return false;

		$locksDirectory = $workspace . '.locks' . DS;
		if ((!Filesystem::directoryExists($locksDirectory) && !Filesystem::mkdir($locksDirectory, true)) ||
			is_link($locksDirectory) || !is_writable($locksDirectory)) return false;

		$lockPath = $locksDirectory . $lockName . '.lock';
		$handle = Filesystem::acquireLock($lockPath, LOCK_EX, 0600);
		if ($handle === false) return false;

		try {
			return $callback();
		} catch (Throwable $e) {
			Log::set(__METHOD__ . LOG_SEP . $this->directoryName . LOG_SEP .
				substr((string)$e->getMessage(), 0, 500), LOG_TYPE_ERROR);
			return false;
		} finally {
			Filesystem::releaseLock($handle);
		}
	}

	public function save()
	{
		if (!$this->databaseStore instanceof BrendyJsonDatabase) {
			$this->databaseStore = new BrendyJsonDatabase($this->filenameDb);
		}
		$beforeStore = $this->databaseStore->db;
		$this->databaseStore->db = $this->db;
		if (!$this->databaseStore->save()) {
			// Keep the backing store aligned with what is actually persisted. The
			// plugin instance may choose its own higher-level rollback semantics, but
			// a failed save must not poison the store used for the next attempt.
			$this->databaseStore->db = $beforeStore;
			return false;
		}
		return true;
	}

	public function includeCSS($filename)
	{
		$asset = $this->assetFilename($filename, 'css');
		if ($asset === false) return '';
		return '<link rel="stylesheet" type="text/css" href="' . Theme::escape($this->domainPath() . 'css/' . rawurlencode($asset) .
			'?version=' . rawurlencode(BRENDY_BUILD)) . '">' . PHP_EOL;
	}

	/** CSP nonce attribute for trusted inline code emitted by an extension. */
	public function cspNonceAttribute()
	{
		return function_exists('brendyCspNonceAttribute') ? brendyCspNonceAttribute() : '';
	}

	/** Backwards-compatible administrator-specific alias. */
	public function adminCspNonceAttribute()
	{
		return $this->cspNonceAttribute();
	}

	// Plugins that can restore files, change domains, inject public code or pull
	// sensitive extensions can opt into Brendy's recent-authentication boundary.
	public function requiresRecentAuthentication()
	{
		return false;
	}

	public function includeJS($filename)
	{
		$asset = $this->assetFilename($filename, 'js');
		if ($asset === false) return '';
		return '<script charset="utf-8" src="' . Theme::escape($this->domainPath() . 'js/' . rawurlencode($asset) .
			'?version=' . rawurlencode(BRENDY_BUILD)) . '"></script>' . PHP_EOL;
	}

	private function assetFilename($filename, $type)
	{
		if (!is_string($filename) || $filename !== basename($filename) ||
			preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.' . preg_quote($type, '/') . '$/Di', $filename) !== 1) return false;
		$directory = $this->phpPath() . $type . DS;
		$path = $directory . $filename;
		if (!is_file($path) || is_link($path) || !is_readable($path)) return false;
		$directoryReal = realpath($directory);
		$fileReal = realpath($path);
		if (!is_string($directoryReal) || !is_string($fileReal) || strpos($fileReal, $directoryReal . DS) !== 0) return false;
		return $filename;
	}

	// Returns absolute URL and path of the plugin directory
	// This function helps to include CSS or Javascript files with absolute URL
	public function domainPath()
	{
		return DOMAIN_PLUGINS . $this->directoryName . '/';
	}

	// Returns relative path of the plugin directory
	// This function helps to include CSS or Javascript files with relative URL
	public function htmlPath()
	{
		return HTML_PATH_PLUGINS . $this->directoryName . '/';
	}

	// Returns absolute path of the plugin directory
	// This function helps to include PHP libraries or some file at server level
	public function phpPath()
	{
		return PATH_PLUGINS . $this->directoryName . DS;
	}

	public function phpPathDB()
	{
		return PATH_PLUGINS_DATABASES . $this->directoryName . DS;
	}

	// Returns the value of the key from the metadata of the plugin, FALSE if the key doesn't exist
	public function getMetadata($key)
	{
		if (is_string($key) && isset($this->metadata[$key]) && is_scalar($this->metadata[$key])) {
			return $this->metadata[$key];
		}

		return false;
	}

	// Set a key / value on the metadata of the plugin
	public function setMetadata($key, $value)
	{
		if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $key) !== 1 ||
			!is_scalar($value) || strlen((string)$value) > 10000) return false;
		$this->metadata[$key] = $value;
		return true;
	}

	// Returns the value of the field from the database
	// (string) $field
	// (boolean) $html, TRUE returns the value sanitized, FALSE unsanitized
	public function getValue($field, $html = true)
	{
		if (!is_string($field) || !array_key_exists($field, $this->dbFields)) return false;
		if (isset($this->db[$field]) && gettype($this->db[$field]) === gettype($this->dbFields[$field])) {
			$value = $this->db[$field];
			if (!is_string($value)) return $value;

			// Normalize legacy or manually edited plugin JSON at the output boundary.
			// Current saves already store escaped strings, but older/raw records must
			// never be able to break out of an admin input or textarea.
			if ($html) return Sanitize::html(Sanitize::htmlDecode($value));
			return Sanitize::htmlDecode($value);
		}
		return $this->dbFields[$field];
	}

	public function label()
	{
		return Sanitize::html($this->getMetadata('label'));
	}

	public function name()
	{
		return Sanitize::html($this->getMetadata('name'));
	}

	public function description()
	{
		return Sanitize::html($this->getMetadata('description'));
	}

	public function author()
	{
		return Sanitize::html($this->getMetadata('author'));
	}

	public function email()
	{
		$email = $this->getMetadata('email');
		return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? Sanitize::html($email) : '';
	}

	public function website()
	{
		$website = $this->getMetadata('website');
		return brendyIsSafeHttpUrl($website, 2048) ? Sanitize::html($website) : '';
	}

	public function position()
	{
		return $this->getValue('position');
	}

	public function version()
	{
		$version = $this->getMetadata('version');
		return is_string($version) && preg_match('/^[A-Za-z0-9._+-]{1,50}$/D', $version) === 1 ? $version : '';
	}

	public function releaseDate()
	{
		$date = $this->getMetadata('releaseDate');
		return is_string($date) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) === 1 ? $date : '';
	}

	public function brendyRelease()
	{
		$release = $this->getMetadata('brendyRelease');
		return is_string($release) && preg_match('/^[A-Za-z0-9._+-]{1,50}$/D', $release) === 1 ? $release : '';
	}

	public function icon()
	{
		$icon = $this->getMetadata('icon');
		return is_string($icon) && preg_match('/^[a-z0-9-]{1,50}$/D', $icon) === 1 ? $icon : 'puzzle';
	}

	public function category()
	{
		$category = $this->getMetadata('category');
		$allowed = array('analytics', 'content', 'editor', 'integration', 'seo', 'system', 'theme');
		return is_string($category) && in_array($category, $allowed, true) ? $category : 'system';
	}

	public function sidebarHeading($label)
	{
		$label = is_scalar($label) ? (string)$label : '';
		if ($label === '') return '';
		return '<h2 class="plugin-label brendy-icon-label"><i class="bi bi-' . Theme::escape($this->icon()) .
			'" aria-hidden="true"></i><span>' . Theme::escape($label) . '</span></h2>';
	}

	/** Shared administrator form fragments used by simple bundled plugins. */
	protected function settingsIntro()
	{
		return '<div class="alert alert-primary" role="alert">' . $this->description() . '</div>';
	}

	protected function settingsLabelField($id = '')
	{
		global $L;
		if (!is_string($id) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $id) !== 1) $id = 'jslabel';
		$escapedId = Theme::escape($id);
		$label = is_object($L) ? $L->get('Label') : 'Label';
		$tip = is_object($L) ? $L->get('This title is almost always used in the sidebar of the site') : '';
		return '<div class="form-group"><label for="' . $escapedId . '">' . Theme::escape($label) . '</label>' .
			'<input id="' . $escapedId . '" name="label" class="form-control" type="text" dir="auto" maxlength="200" value="' .
			Theme::escape($this->getValue('label', false)) . '">' .
			($tip !== '' ? '<span class="tip">' . Theme::escape($tip) . '</span>' : '') . '</div>';
	}


	protected function settingsSelectField($field, $labelKey, $options, $tipKey = '')
	{
		global $L;
		if (!is_string($field) || !array_key_exists($field, $this->dbFields) ||
			preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $field) !== 1 || !is_array($options)) return '';
		$label = is_object($L) ? $L->get(is_string($labelKey) ? $labelKey : $field) : (string)$labelKey;
		$current = $this->getValue($field, false);
		$html = '<div class="form-group"><label for="' . Theme::escape($field) . '">' . Theme::escape($label) . '</label>' .
			'<select class="form-select" id="' . Theme::escape($field) . '" name="' . Theme::escape($field) . '">';
		$count = 0;
		foreach ($options as $value => $optionLabelKey) {
			if (++$count > 100 || (!is_string($value) && !is_int($value)) || !is_scalar($optionLabelKey)) continue;
			$value = (string)$value;
			if (strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value)) continue;
			$optionLabel = is_object($L) ? $L->get((string)$optionLabelKey) : (string)$optionLabelKey;
			$selected = ((string)$current === $value) ? ' selected' : '';
			$html .= '<option value="' . Theme::escape($value) . '"' . $selected . '>' . Theme::escape($optionLabel) . '</option>';
		}
		$html .= '</select>';
		if (is_string($tipKey) && $tipKey !== '') {
			$tip = is_object($L) ? $L->get($tipKey) : $tipKey;
			$html .= '<small class="form-text text-muted">' . Theme::escape($tip) . '</small>';
		}
		return $html . '</div>';
	}

	protected function settingsNumberField($field, $labelKey, $min, $max, $tipKey = '', $step = 1)
	{
		global $L;
		if (!is_string($field) || !array_key_exists($field, $this->dbFields) ||
			preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $field) !== 1) return '';
		if (!is_numeric($min) || !is_numeric($max) || !is_numeric($step)) return '';
		$min = (float)$min;
		$max = (float)$max;
		$step = (float)$step;
		if (!is_finite($min) || !is_finite($max) || !is_finite($step) || $min > $max || $step <= 0) return '';
		$label = is_object($L) ? $L->get(is_string($labelKey) ? $labelKey : $field) : (string)$labelKey;
		$value = $this->getValue($field, false);
		if (!is_numeric($value)) $value = $this->dbFields[$field];
		$value = max($min, min($max, (float)$value));
		if (is_int($this->dbFields[$field])) $value = (int)$value;
		$html = '<div class="form-group"><label for="' . Theme::escape($field) . '">' . Theme::escape($label) . '</label>' .
			'<input class="form-control" id="' . Theme::escape($field) . '" name="' . Theme::escape($field) . '" type="number" min="' .
			Theme::escape((string)$min) . '" max="' . Theme::escape((string)$max) . '" step="' . Theme::escape((string)$step) . '" value="' .
			Theme::escape((string)$value) . '">';
		if (is_string($tipKey) && $tipKey !== '') {
			$tip = is_object($L) ? $L->get($tipKey) : $tipKey;
			$html .= '<small class="form-text text-muted">' . Theme::escape($tip) . '</small>';
		}
		return $html . '</div>';
	}

	/** Per-plugin integer bounds applied before settings are written to disk. */
	protected function integerSettingBounds()
	{
		return array();
	}

	/** Per-plugin string byte limits applied before settings are written to disk. */
	protected function stringSettingMaxLengths()
	{
		return array();
	}

	protected function settingsBooleanField($field, $labelKey, $tipKey = '')
	{
		if (!is_string($field) || !array_key_exists($field, $this->dbFields)) return '';
		global $L;
		$label = is_object($L) ? $L->get(is_string($labelKey) ? $labelKey : $field) : (string)$labelKey;
		$enabled = is_object($L) ? $L->get('Enabled') : 'Enabled';
		$disabled = is_object($L) ? $L->get('Disabled') : 'Disabled';
		$value = $this->getValue($field) === true;
		$html = '<div class="form-group"><label for="' . Theme::escape($field) . '">' . Theme::escape($label) . '</label>' .
			'<select class="form-select" id="' . Theme::escape($field) . '" name="' . Theme::escape($field) . '">' .
			'<option value="true"' . ($value ? ' selected' : '') . '>' . Theme::escape($enabled) . '</option>' .
			'<option value="false"' . (!$value ? ' selected' : '') . '>' . Theme::escape($disabled) . '</option></select>';
		if (is_string($tipKey) && $tipKey !== '') {
			$tip = is_object($L) ? $L->get($tipKey) : $tipKey;
			$html .= '<small class="form-text text-muted">' . Theme::escape($tip) . '</small>';
		}
		return $html . '</div>';
	}

	protected function settingsHomeLinkField($tipKey = 'Show the home link on the sidebar')
	{
		global $L;
		if (!array_key_exists('homeLink', $this->dbFields)) return '';
		$label = is_object($L) ? $L->get('Home link') : 'Home link';
		$enabled = is_object($L) ? $L->get('Enabled') : 'Enabled';
		$disabled = is_object($L) ? $L->get('Disabled') : 'Disabled';
		$tip = is_object($L) && is_string($tipKey) ? $L->get($tipKey) : '';
		$value = $this->getValue('homeLink') === true;
		return '<div class="form-group"><label for="brendy-home-link">' . Theme::escape($label) . '</label>' .
			'<select id="brendy-home-link" name="homeLink" class="form-select">' .
			'<option value="true"' . ($value ? ' selected' : '') . '>' . Theme::escape($enabled) . '</option>' .
			'<option value="false"' . (!$value ? ' selected' : '') . '>' . Theme::escape($disabled) . '</option>' .
			'</select>' . ($tip !== '' ? '<small class="form-text text-muted">' . Theme::escape($tip) . '</small>' : '') . '</div>';
	}

	protected function sidebarHomeLink()
	{
		global $L;
		if (!array_key_exists('homeLink', $this->dbFields) || $this->getValue('homeLink') !== true) return '';
		$label = is_object($L) ? $L->get('Home page') : 'Home page';
		return '<li><a' . (($GLOBALS['WHERE_AM_I'] ?? '') === 'home' ? ' aria-current="page"' : '') . ' href="' . Theme::escape(Theme::siteUrl()) . '"><i class="bi bi-house-door" aria-hidden="true"></i> ' .
			Theme::escape($label) . '</a></li>';
	}

	/** Render a bounded taxonomy list shared by Categories and Tags. */
	protected function sidebarTaxonomyList($taxonomy, $routeName, $domain, $icon, $hideEmpty = true, $showCount = false)
	{
		global $WHERE_AM_I, $url;
		if (!is_object($taxonomy) || !method_exists($taxonomy, 'getDB') || !method_exists($taxonomy, 'numberOfPages') ||
			!is_string($routeName) || !is_string($domain) || !is_string($icon) ||
			preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $routeName) !== 1 ||
			preg_match('/^[a-z0-9-]{1,50}$/D', $icon) !== 1) return '';
		$db = $taxonomy->getDB();
		if (!is_array($db)) return '';
		$html = '<ul>';
		$seen = 0;
		foreach ($db as $key => $fields) {
			if (++$seen > 1000) break;
			if (!is_string($key) || $key === '' || strlen($key) > 200 || !is_array($fields)) continue;
			$count = max(0, (int)$taxonomy->numberOfPages($key));
			if ($hideEmpty && $count === 0) continue;
			$name = isset($fields['name']) && is_scalar($fields['name']) ? (string)$fields['name'] : $key;
			$isCurrent = $WHERE_AM_I === $routeName && is_object($url) && method_exists($url, 'slug') && $url->slug() === $key;
			$html .= '<li><a' . ($isCurrent ? ' aria-current="page"' : '') . ' href="' .
				Theme::escape(rtrim($domain, '/') . '/' . rawurlencode($key)) . '"><i class="bi bi-' .
				Theme::escape($icon) . '" aria-hidden="true"></i> <span>' . Theme::escape($name) . '</span>' .
				($showCount ? ' <span class="brendy-taxonomy-count" aria-hidden="true">(' . $count . ')</span>' : '') . '</a></li>';
		}
		return $html . '</ul>';
	}

	/** Return a bounded page-number window with first/last anchors. */
	protected function paginationWindow($current, $pages, $radius = 2)
	{
		return class_exists('Paginator', false) ? Paginator::window($current, $pages, $radius) : array();
	}

	/** Include the shared nested-sidebar stylesheet at most once per request. */
	protected function sidebarTreeStylesheet()
	{
		if (!defined('DOMAIN_CORE_CSS')) return '';
		if (!empty($GLOBALS['_brendySidebarTreeStylesheetLoaded'])) return '';
		$GLOBALS['_brendySidebarTreeStylesheetLoaded'] = true;
		return Theme::css('sidebar-tree.css', DOMAIN_CORE_CSS);
	}

	/** Render a safe nested page tree for sidebar plugins without duplicating HTML traversal. */
	protected function sidebarPageTree($rootKeys, $allowedTypes, $maxDepth = 32)
	{
		global $pages;
		global $page;
		if (!is_object($pages) || !is_array($rootKeys) || !is_array($allowedTypes)) return '';
		$maxDepth = max(1, min(64, (int)$maxDepth));
		$currentKey = (isset($page) && $page instanceof Page) ? $page->key() : false;
		$render = function ($keys, $depth, $ancestors) use (&$render, $pages, $allowedTypes, $maxDepth, $currentKey) {
			if ($depth > $maxDepth || !is_array($keys)) return '';
			$html = '';
			$count = 0;
			foreach ($keys as $key) {
				if (++$count > 1000) break;
				if (!is_string($key) || $key === '' || isset($ancestors[$key]) || !$pages->isPubliclyAccessible($key)) continue;
				$row = $pages->getPageDB($key);
				if (!is_array($row) || !isset($row['type']) || !is_string($row['type']) || !in_array($row['type'], $allowedTypes, true)) continue;
				try {
					$item = new Page($key);
				} catch (Throwable $e) {
					continue;
				}
				$nextAncestors = $ancestors;
				$nextAncestors[$key] = true;
				$children = $pages->getDirectChildrenByTypes($key, $allowedTypes);
				$class = $depth === 1 ? 'parent' : 'child depth-' . $depth;
				$current = is_string($currentKey) && hash_equals($currentKey, $key) ? ' aria-current="page"' : '';
				$html .= '<li class="' . Theme::escape($class) . '"><a href="' . Theme::escape($item->permalink()) . '"' . $current . '>' .
					Theme::escape($item->title()) . '</a>';
				if (!empty($children) && $depth < $maxDepth) {
					$childHtml = $render($children, $depth + 1, $nextAncestors);
					if ($childHtml !== '') $html .= '<ul class="child depth-' . ($depth + 1) . '">' . $childHtml . '</ul>';
				}
				$html .= '</li>';
			}
			return $html;
		};
		return $render(array_values($rootKeys), 1, array());
	}

	/**
	 * Lightweight local diagnostics shared by all bundled plugins.
	 * Plugins can override this method when they have additional dependencies.
	 */
	public function healthStatus()
	{
		$issues = array();
		$status = 'ok';
		$requiredMetadata = array('name', 'description', 'author', 'version', 'releaseDate', 'brendyRelease', 'compatible', 'icon', 'category');
		foreach ($requiredMetadata as $field) {
			$value = $this->getMetadata($field);
			if (!is_string($value) || trim($value) === '') $issues[] = 'metadata-' . $field;
		}
		if (!empty($issues)) $status = 'warning';

		// Reuse the same metadata contract enforced during extension installation
		// instead of maintaining a second, weaker validator in the plugin UI.
		if (function_exists('brendyValidateExtensionMetadata') &&
			!brendyValidateExtensionMetadata($this->metadata, 'plugin', false)) {
			$status = 'error';
			$issues[] = 'metadata-format';
		}

		foreach ($this->reservedFieldConflicts as $field) {
			$status = 'error';
			$issues[] = 'reserved-field-' . $field;
		}

		if (!$this->isCompatible()) $issues[] = 'compatibility';
		if ($status !== 'error' && !empty($issues)) $status = 'warning';

		$pluginFile = $this->phpPath() . 'plugin.php';
		if (!is_file($pluginFile) || is_link($pluginFile) || !is_readable($pluginFile)) {
			$status = 'error';
			$issues[] = 'source';
		} else {
			// Executable source is already fail-closed scanned before plugin loading
			// (60.plugins.php). Avoid hashing the complete plugin tree again here;
			// System Health performs an explicit whole-library security scan on demand.
			// Verify literal local assets referenced through the safe include helpers.
			$source = Filesystem::readFileBounded($pluginFile, 5242880, 1);
			if (is_string($source)) {
				foreach (array('CSS' => 'css', 'JS' => 'js') as $method => $assetType) {
					if (!preg_match_all('/\$this->include' . $method . '\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $assetMatches)) continue;
					foreach (array_values(array_unique($assetMatches[1])) as $assetName) {
						if ($this->assetFilename($assetName, $assetType) === false) {
							$status = 'error';
							$issues[] = 'asset-' . $assetType . '-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $assetName);
						}
					}
				}
			}
		}
		if ($this->installed()) {
			if (!$this->databaseStateValid()) {
				$status = 'error';
				$issues[] = 'database-integrity';
			}
			$databaseDirectory = dirname($this->filenameDb);
			$workspace = $this->workspace();
			if (!is_readable($this->filenameDb) || is_link($databaseDirectory) || !is_writable($databaseDirectory)) {
				$status = 'error';
				$issues[] = 'database';
			}
			if (!is_dir($workspace) || is_link($workspace) || !is_readable($workspace) || !is_writable($workspace)) {
				$status = 'error';
				$issues[] = 'workspace';
			}
		}
		return array('status' => $status, 'issues' => array_values(array_unique($issues)));
	}

	public function settingsFieldCount()
	{
		return max(0, count($this->dbFields) - (array_key_exists('position', $this->dbFields) ? 1 : 0));
	}

	public function databaseSize()
	{
		if (!$this->installed() || !is_file($this->filenameDb) || is_link($this->filenameDb)) return 0;
		$size = @filesize($this->filenameDb);
		return is_int($size) && $size > 0 ? $size : 0;
	}

	public function workspaceSize()
	{
		$workspace = $this->workspace();
		if (!is_dir($workspace) || is_link($workspace)) return 0;
		$total = 0;
		$seen = 0;
		try {
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS));
			foreach ($iterator as $item) {
				if (++$seen > 5000) break;
				if ($item->isFile() && !$item->isLink()) $total += max(0, (int)$item->getSize());
			}
		} catch (Throwable $e) {
			return 0;
		}
		return $total;
	}

	public function lastModified()
	{
		$latest = 0;
		foreach (array($this->filenameMetadata, $this->phpPath() . 'plugin.php', $this->filenameDb) as $filename) {
			if (!is_file($filename) || is_link($filename)) continue;
			$modified = @filemtime($filename);
			if (is_int($modified)) $latest = max($latest, $modified);
		}
		return $latest;
	}

	public function className()
	{
		return $this->className;
	}

	public function formButtons()
	{
		return $this->formButtons;
	}

	public function isCompatible()
	{
		return function_exists('brendyExtensionMetadataCompatible')
			? brendyExtensionMetadataCompatible($this->metadata) : false;
	}

	public function directoryName()
	{
		return $this->directoryName;
	}

	// Return TRUE if the installation success, otherwise FALSE.
	public function install($position = 1)
	{
		return $this->installCore($position);
	}

	protected function installCore($position = 1)
	{
		if ($this->installed()) return false;

        $workspace = $this->workspace();
        $databaseDirectory = PATH_PLUGINS_DATABASES . $this->directoryName;
        $workspaceCreated = false;
        $databaseCreated = false;

        if (!Filesystem::directoryExists($workspace)) {
            if (!Filesystem::mkdir($workspace, true)) return false;
            $workspaceCreated = true;
        }
        if (!Filesystem::directoryExists($databaseDirectory)) {
            if (!Filesystem::mkdir($databaseDirectory, true)) {
                if ($workspaceCreated) Filesystem::deleteRecursive($workspace);
                return false;
            }
            $databaseCreated = true;
        }

        $success = false;
        try {
            $this->dbFields['position'] = max(0, min(10000, (int)$position));
            foreach ($this->dbFields as $key => $value) {
                if ($key === 'label' && is_string($value)) {
                    $localizedName = Sanitize::htmlDecode($this->name());
                    if ($localizedName !== '' && Text::length($localizedName) <= 200) {
                        $value = $localizedName;
                        $this->dbFields[$key] = $localizedName;
                    }
                }
                $valid = false;
                $finalValue = $this->normalizeFieldValue($value, $value, $valid, $key);
                if (!$valid) return false;
                $this->db[$key] = $finalValue;
            }
            $success = $this->save();
            return $success;
        } finally {
            // Do not leave half-installed directories behind after failed
            // validation or an I/O failure. Never remove pre-existing data.
            if (!$success) {
                if ($databaseCreated && Filesystem::directoryExists($databaseDirectory)) Filesystem::deleteRecursive($databaseDirectory);
                if ($workspaceCreated && Filesystem::directoryExists($workspace)) Filesystem::deleteRecursive($workspace);
            }
        }
	}

	/**
	 * Install plugin state and require a generated artifact to be created before
	 * considering the installation committed. Used by feed/index generators.
	 */
	protected function installWithGeneratedArtifact($position, $generator)
	{
		if (!is_callable($generator) || !$this->installCore($position)) return false;
		try {
			if ((bool)$generator()) return true;
		} catch (Throwable $e) {
			Log::set(__METHOD__ . LOG_SEP . $this->directoryName . LOG_SEP . substr((string)$e->getMessage(), 0, 500), LOG_TYPE_ERROR);
		}
		$this->uninstall();
		return false;
	}

	public function uninstall()
	{
		return function_exists('removeBrendyPluginState')
			? removeBrendyPluginState($this->directoryName) : false;
	}

	// Runtime activation is stricter than mere state-file existence. This keeps
	// corrupt/incompatible extensions visible to the admin without executing
	// their hooks on public or administrator requests.
	public function runtimeReady()
	{
		return $this->installed() && $this->databaseStateValid === true &&
			empty($this->reservedFieldConflicts) && $this->isCompatible();
	}

	public function databaseStateValid()
	{
		return $this->databaseStateValid === true;
	}

	// Returns TRUE if the plugin is installed
	// This function just check if the database of the plugin is created
	public function installed()
	{
		return is_file($this->filenameDb) && !is_link($this->filenameDb);
	}

	public function workspace()
	{
		return PATH_WORKSPACES . $this->directoryName . DS;
	}

	public function init()
	{
		// This method is used on children classes
		// The user can define his own field of the database
	}

	public function prepare()
	{
		// This method is used on children classes
		// The user can prepare the plugin, when it is installed
	}

	public function post()
	{
		return $this->savePostedSettings();
	}

	protected function savePostedSettings()
	{
		$args = is_array($_POST) ? $_POST : array();
		$before = $this->db;
		foreach ($this->dbFields as $field => $value) {
			if (array_key_exists($field, $args)) {
				$valid = false;
				$finalValue = $this->normalizeFieldValue($args[$field], $value, $valid, $field);
				if (!$valid) {
					$this->db = $before;
					return false;
				}
				$this->db[$field] = $finalValue;
			}
		}
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return true;
	}

	/**
	 * Persist submitted settings and roll them back if a derived public artifact
	 * cannot be regenerated. This keeps configuration and generated files in sync.
	 */
	protected function postWithGeneratedArtifact($generator)
	{
		if (!is_callable($generator)) return false;
		$before = $this->db;
		if (!$this->savePostedSettings()) return false;
		try {
			if ((bool)$generator()) return true;
		} catch (Throwable $e) {
			Log::set(__METHOD__ . LOG_SEP . $this->directoryName . LOG_SEP . substr((string)$e->getMessage(), 0, 500), LOG_TYPE_ERROR);
		}
		$this->db = $before;
		if (!$this->save()) Log::set(__METHOD__ . LOG_SEP . 'Unable to roll back plugin settings for ' . $this->directoryName, LOG_TYPE_ERROR);
		return false;
	}

	public function type()
	{
		return $this->getMetadata('type');
	}

	public function setField($field, $value)
	{
		return $this->setFields(array($field => $value));
	}

	/**
	 * Atomically update multiple declared plugin settings. Every value is
	 * normalized before the in-memory state changes, then persisted with one
	 * optimistic/atomic save. A failed write restores the complete snapshot.
	 */
	public function setFields($fields)
	{
		if (!is_array($fields) || empty($fields) || count($fields) > 100) return false;
		$normalized = array();
		foreach ($fields as $field => $value) {
			if (!is_string($field) || !array_key_exists($field, $this->dbFields)) return false;
			$valid = false;
			$finalValue = $this->normalizeFieldValue($value, $this->dbFields[$field], $valid, $field);
			if (!$valid) return false;
			$normalized[$field] = $finalValue;
		}

		$before = $this->db;
		foreach ($normalized as $field => $value) $this->db[$field] = $value;
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return true;
	}

	private function normalizeFieldValue($value, $defaultValue, &$valid, $field = '')
	{
		$valid = false;
		if (is_bool($defaultValue)) {
			$boolean = brendyBooleanValue($value, null);
			if ($boolean === null) return false;
			$valid = true;
			return $boolean;
		}
		if (is_int($defaultValue)) {
			$minimum = -1000000000;
			$maximum = 1000000000;
			if ($field === 'numberOfItems') { $minimum = 1; $maximum = 1000; }
			if ($field === 'position') { $minimum = 0; $maximum = 10000; }
			$bounds = $this->integerSettingBounds();
			if (is_array($bounds) && isset($bounds[$field]) && is_array($bounds[$field]) && count($bounds[$field]) >= 2) {
				$customMinimum = brendyIntegerValue($bounds[$field][0], null, -1000000000, 1000000000);
				$customMaximum = brendyIntegerValue($bounds[$field][1], null, -1000000000, 1000000000);
				if ($customMinimum === null || $customMaximum === null || $customMinimum > $customMaximum) return false;
				$minimum = $customMinimum;
				$maximum = $customMaximum;
			}
			$integer = brendyIntegerValue($value, null, $minimum, $maximum);
			if ($integer === null) return false;
			$valid = true;
			return $integer;
		}
		if (is_float($defaultValue)) {
			if (!is_scalar($value) || !is_numeric($value)) return false;
			$float = (float)$value;
			if (!is_finite($float) || abs($float) > 1000000000) return false;
			$valid = true;
			return $float;
		}
		if (is_string($defaultValue) && (is_string($value) || is_numeric($value))) {
			$value = (string)$value;
			$limit = ($field === 'label') ? 200 : 4194304;
			$limits = $this->stringSettingMaxLengths();
			if (is_array($limits) && array_key_exists($field, $limits)) {
				$customLimit = brendyIntegerValue($limits[$field], null, 0, 4194304);
				if ($customLimit === null) return false;
				$limit = $customLimit;
			}
			if (strlen($value) > $limit) return false;
			$valid = true;
			return Sanitize::html($value);
		}
		return false;
	}

	public function setPosition($position)
	{
		return $this->setField('position', $position);
	}

	// Returns the parameters after the URI, FALSE if the URI doesn't match with the webhook
	// Example: https://cms.example.com/api/foo/bar
	public function webhook($URI = false, $returnsAfterURI = false, $fixed = true)
	{
		global $url;

		if (!is_string($URI) || $URI === '' || strlen($URI) > 255 ||
			preg_match('#^(?!/)(?!.*(?:^|/)\.{1,2}(?:/|$))[A-Za-z0-9][A-Za-z0-9._/-]{0,254}$#D', $URI) !== 1 ||
			!is_object($url) || !method_exists($url, 'uri')) return false;

		// Check URI start with the webhook. Treat the request URI as hostile input:
		// malformed arrays/control characters/oversized values must fail closed
		// before reaching multibyte string functions used by every plugin webhook.
		$startString = HTML_PATH_ROOT . $URI;
		$requestUri = $url->uri();
		if (!is_string($requestUri) || strlen($requestUri) > 8192 || preg_match('/[\x00-\x1F\x7F]/', $requestUri)) return false;
		$length = mb_strlen($startString, CHARSET);
		if (mb_substr($requestUri, 0, $length) !== $startString) {
			return false;
		}

		$afterURI = mb_substr($requestUri, $length);
		if (!empty($afterURI)) {
			if ($fixed) {
				return false;
			}
			if ($afterURI[0] !== '/') {
				return false;
			}
		}

		if ($returnsAfterURI) {
			return $afterURI;
		}

		Log::set(__METHOD__ . LOG_SEP . 'Webhook requested.');
		return true;
	}
}

/**
 * Base for plugins whose persisted settings must stay synchronized with a
 * generated public XML artifact (for example an XML sitemap).
 */
abstract class BrendyGeneratedArtifactPlugin extends BrendyPlugin
{
	abstract protected function generatePublicArtifact();
	abstract protected function generatedArtifactConfig();

	private function normalizedGeneratedArtifactConfig()
	{
		$config = $this->generatedArtifactConfig();
		if (!is_array($config)) return false;
		$filename = isset($config['filename']) && is_string($config['filename']) ? $config['filename'] : '';
		$contentType = isset($config['contentType']) && is_string($config['contentType']) ? $config['contentType'] : '';
		$maxSize = isset($config['maxSize']) ? (int)$config['maxSize'] : 0;
		$dependencies = isset($config['dependencies']) && is_array($config['dependencies']) ? $config['dependencies'] : array();
		if ($filename !== basename($filename) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.xml$/D', $filename) !== 1 ||
			$contentType !== 'application/xml' ||
			$maxSize < 1024 || $maxSize > 67108864 || count($dependencies) > 50) return false;
		foreach ($dependencies as $dependency) {
			if (!is_string($dependency) || $dependency === '' || strpos($dependency, "\0") !== false) return false;
		}
		return array('filename' => $filename, 'contentType' => $contentType, 'maxSize' => $maxSize, 'dependencies' => $dependencies);
	}

	private function generatedArtifactLockName($filename)
	{
		return 'artifact-' . substr(hash('sha256', (string)$filename), 0, 32);
	}

	protected function refreshGeneratedArtifact($onlyIfStale = false)
	{
		$config = $this->normalizedGeneratedArtifactConfig();
		if (!is_array($config)) return false;
		$file = $this->workspace() . $config['filename'];
		$lockName = $this->generatedArtifactLockName($config['filename']);
		return $this->withWorkspaceLock($lockName, function () use ($onlyIfStale, $config, $file) {
			// Another worker may have refreshed the file while this request waited.
			if ($onlyIfStale && !generatedFileNeedsRefresh($file, $config['dependencies'])) return true;
			return (bool)$this->generatePublicArtifact();
		});
	}

	public function install($position = 0)
	{
		return $this->installWithGeneratedArtifact($position, function () {
			return $this->refreshGeneratedArtifact(false);
		});
	}

	public function post()
	{
		return $this->postWithGeneratedArtifact(function () {
			return $this->refreshGeneratedArtifact(false);
		});
	}

	public function afterPageCreate() { return $this->refreshGeneratedArtifact(false); }
	public function afterPageModify() { return $this->refreshGeneratedArtifact(false); }
	public function afterPageDelete() { return $this->refreshGeneratedArtifact(false); }

	public function afterSettingsModify()
	{
		$config = $this->normalizedGeneratedArtifactConfig();
		if (!is_array($config)) return false;
		$file = $this->workspace() . $config['filename'];
		$lockName = $this->generatedArtifactLockName($config['filename']);
		return $this->withWorkspaceLock($lockName, function () use ($file) {
			if (is_link($file)) return Filesystem::rmfile($file);
			if (!file_exists($file)) return true;
			return is_file($file) && Filesystem::rmfile($file);
		});
	}

	public function beforeAll()
	{
		$config = $this->normalizedGeneratedArtifactConfig();
		if (!is_array($config) || !$this->webhook($config['filename'])) return false;
		$file = $this->workspace() . $config['filename'];
		if (generatedFileNeedsRefresh($file, $config['dependencies']) && !$this->refreshGeneratedArtifact(true)) {
			sendJsonResponse(array('status' => '1', 'message' => 'Generated XML could not be refreshed.'), 503);
		}
		serveGeneratedXmlFile($file, $config['contentType'], $config['maxSize']);
		return true;
	}
}

/**
 * Shared preferences used by bundled companion theme plugins.
 */
abstract class BrendyThemePreferencesPlugin extends BrendyPlugin
{
	public function dateFormat()
	{
		$value = $this->getValue('dateFormat');
		return is_string($value) ? $value : '';
	}
}
