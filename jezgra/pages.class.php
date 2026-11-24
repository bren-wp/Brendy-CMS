<?php defined('BRENDY') or die('Brendy CMS.');

class Pages extends BrendyJsonDatabase
{

	protected $parentKeyList = array();
	public $dbFields = array(
		'title' => '',
		'description' => '',
		'username' => '',
		'tags' => array(),
		'type' => 'published', // published, static, draft, sticky, scheduled, autosave
		'date' => '',
		'dateModified' => '',
		'position' => 0,
		'coverImage' => '',
		'category' => '',
		'md5file' => '',
		'contentHash' => '',
		'uuid' => '',
		'allowComments' => true,
		'template' => '',
		'noindex' => false,
		'nofollow' => false,
		'noarchive' => false,
		'custom' => array()
	);

	function __construct()
	{
		parent::__construct(DB_PAGES);
	}

	public function getDefaultFields()
	{
		return $this->dbFields;
	}

	// Return an array with the database for a page, FALSE otherwise
	public function getPageDB($key)
	{
		if ($this->exists($key)) {
			return $this->db[$key];
		}

		return false;
	}

	// Return TRUE if the page exists, FALSE otherwise
	public function exists($key)
	{
		return $this->hasArrayRow($key);
	}

	// Create a new page
	// This function returns the key of the new page
	public function add($args)
	{
		if (!is_array($args)) {
			return false;
		}
		if ((array_key_exists('slug', $args) && !is_string($args['slug'])) ||
			(array_key_exists('parent', $args) && !is_string($args['parent'])) ||
			(isset($args['slug']) && strlen($args['slug']) > 1000) ||
			(isset($args['parent']) && strlen($args['parent']) > 200)) {
			return false;
		}
		$row = array();

		// Predefined values
		foreach ($this->dbFields as $field => $value) {
			if ($field === 'tags') {
				$tags = '';
				if (isset($args['tags'])) {
					if (!is_string($args['tags'])) return false;
					$tags = $args['tags'];
				}
				$finalValue = $this->generateTags($tags);
				if ($finalValue === false) return false;
			} elseif ($field === 'custom') {
				$row['custom'] = $this->normalizeCustomValues(isset($args['custom']) ? $args['custom'] : array());
				if ($row['custom'] === false) return false;
				continue;
			} elseif (isset($args[$field])) {
				$valid = false;
				$finalValue = $this->normalizeRowField($field, $args[$field], $value, true, $valid);
				if (!$valid) return false;
			} else {
				// Default value for the field if not defined
				$finalValue = $value;
			}
			$row[$field] = $finalValue;
		}

		// Content
		// This variable is not belong to the database so is not defined in $row
		if (isset($args['content']) && (!is_string($args['content']) || strlen($args['content']) > BRENDY_MAX_CONTENT_BYTES)) {
			return false;
		}
		$contentRaw = isset($args['content']) ? $args['content'] : '';

		// Parent
		// This variable is not belong to the database so is not defined in $row
		$parent = '';
		if (array_key_exists('parent', $args) && $args['parent'] !== '') {
			$parent = $args['parent'];
			if (!$this->exists($parent)) {
				return false;
			}
			$row['type'] = $this->db[$parent]['type']; // get the parent type
		}

		// Slug from the title or the content
		// This variable is not belong to the database so is not defined in $row
		if (!array_key_exists('slug', $args) || $args['slug'] === '') {
			if (isset($row['title']) && is_string($row['title']) && $row['title'] !== '') {
				$slug = $this->generateSlug($row['title']);
			} else {
				$slug = $this->generateSlug($contentRaw);
			}
		} else {
			$slug = $args['slug'];
		}

		// Generate key
		// This variable is not belong to the database so is not defined in $row
		$key = $this->generateKey($slug, $parent);
		if (!is_string($key) || $key === '') return false;

		// Generate UUID
		if (!in_array($row['type'], array('published', 'static', 'draft', 'sticky', 'scheduled', 'autosave'), true)) {
			return false;
		}
		if (empty($row['uuid'])) {
			$row['uuid'] = $this->generateUUID();
		} elseif (!is_string($row['uuid']) ||
			preg_match($row['type'] === 'autosave' ? '/^autosave-[a-f0-9]{32}$/iD' : '/^[a-f0-9]{32}$/iD', $row['uuid']) !== 1) {
			return false;
		}
		if ($this->getByUUID($row['uuid']) !== false) {
			return false;
		}

		// Validate date
		if (!Valid::date($row['date'], DB_DATE_FORMAT)) {
			$row['date'] = Date::current(DB_DATE_FORMAT);
		}

		// Schedule page
		if (($row['date'] > Date::current(DB_DATE_FORMAT)) && ($row['type'] === 'published')) {
			$row['type'] = 'scheduled';
		}

		// Create the directory
		if (Filesystem::mkdir(PATH_PAGES . $key, true) === false) {
			Log::set(__METHOD__ . LOG_SEP . 'Error occurred when trying to create the directory [' . PATH_PAGES . $key . ']', LOG_TYPE_ERROR);
			return false;
		}

		// Create the index.txt and save the file
		if (!$this->writeContentFile(PATH_PAGES . $key . DS . FILENAME, $contentRaw)) {
			Log::set(__METHOD__ . LOG_SEP . 'Error occurred when trying to create the content in the file [' . FILENAME . ']', LOG_TYPE_ERROR);
			Filesystem::deleteRecursive(PATH_PAGES . $key);
			return false;
		}

		// Keep the legacy MD5 field and canonical SHA-256 in one bounded descriptor
		// pass so a path replacement cannot make the two digests describe different bytes.
		$contentDigests = Filesystem::hashFileDigestsBounded(
			PATH_PAGES . $key . DS . FILENAME, array('md5', 'sha256'), BRENDY_MAX_CONTENT_BYTES
		);
		$row['md5file'] = is_array($contentDigests) && isset($contentDigests['md5']) ? $contentDigests['md5'] : false;
		$row['contentHash'] = is_array($contentDigests) && isset($contentDigests['sha256']) ? $contentDigests['sha256'] : false;
		if ($row['md5file'] === false || $row['contentHash'] === false) {
			Filesystem::deleteRecursive(PATH_PAGES . $key);
			return false;
		}

		$uploadPath = PATH_UPLOADS_PAGES . $row['uuid'];
		$createdUploadPath = false;
		if (is_link($uploadPath) || (file_exists($uploadPath) && !is_dir($uploadPath))) {
			Filesystem::deleteRecursive(PATH_PAGES . $key);
			return false;
		}
		if (!is_dir($uploadPath)) {
			if (!Filesystem::mkdir($uploadPath, true)) {
				Filesystem::deleteRecursive(PATH_PAGES . $key);
				return false;
			}
			$createdUploadPath = true;
		}

		// Insert in database
		$this->db[$key] = $row;

		// Sort database
		$this->sortBy();

		// Maintain the legacy page-key media alias through the canonical UUID
		// directory. Nested aliases must not traverse a parent symlink; the shared
		// helper resolves their physical parent UUID directory instead. Alias
		// availability is compatibility-only, so inability to create it must not
		// discard otherwise valid content stored under the canonical UUID path.
		$aliasCreated = false;
		if (!brendyEnsurePageUploadAlias($key, $this->db, $aliasCreated)) {
			Log::set(__METHOD__ . LOG_SEP . 'Unable to create page upload alias: ' . $key, LOG_TYPE_WARN);
		}

		// Save database
		if (!$this->save()) {
			if ($aliasCreated) brendyRemovePageUploadAlias($key, $this->db);
			unset($this->db[$key]);
			Filesystem::deleteRecursive(PATH_PAGES . $key);
			if ($createdUploadPath) {
				Filesystem::deleteRecursive($uploadPath);
			}
			return false;
		}

		return $key;
	}

	// Edit a page
	// This function do not edit the current row from the table -
	// - instead of that the function creates a new row and is completed by the current -
	// - values of the page and then the old row is deleted and the new row is inserted.
	public function edit($args)
	{
		if (!is_array($args) || !isset($args['key']) || !is_string($args['key']) || !$this->exists($args['key'])) {
			return false;
		}
		if ((array_key_exists('slug', $args) && !is_string($args['slug'])) ||
			(array_key_exists('parent', $args) && !is_string($args['parent'])) ||
			(isset($args['slug']) && strlen($args['slug']) > 1000) ||
			(isset($args['parent']) && strlen($args['parent']) > 200)) {
			return false;
		}
		// This is the new row for the table and is going to replace the old row
		$row = array();

		// Current key
		// This variable is not belong to the database so is not defined in $row
		$key = $args['key'];

		// Check values from the arguments ($args)
		// If some field is missing the current value is taken
		foreach ($this->dbFields as $field => $value) {
			if (($field === 'tags') && isset($args['tags'])) {
				if (!is_string($args['tags'])) return false;
				$finalValue = $this->generateTags($args['tags']);
				if ($finalValue === false) return false;
			} elseif ($field === 'custom') {
				$existingCustom = isset($this->db[$key]['custom']) && is_array($this->db[$key]['custom'])
					? $this->db[$key]['custom']
					: array();
				$row['custom'] = $this->normalizeCustomValues(
					isset($args['custom']) ? $args['custom'] : array(),
					$existingCustom
				);
				if ($row['custom'] === false) return false;
				continue;
			} elseif (isset($args[$field])) {
				$valid = false;
				$finalValue = $this->normalizeRowField($field, $args[$field], $value, true, $valid);
				if (!$valid) return false;
			} else {
				// Default value from the current row
				$stored = array_key_exists($field, $this->db[$key]) ? $this->db[$key][$field] : $value;
				$valid = false;
				$finalValue = $this->normalizeRowField($field, $stored, $value, false, $valid);
				if (!$valid) return false;
			}
			$row[$field] = $finalValue;
		}

		// Parent
		// Parent is not stored as a separate field; it is encoded in the page key.
		// When callers omit the parent (for example revision restore or a partial API
		// update), preserve the current immediate parent. An explicitly supplied empty
		// string still means "move this page to the root".
		$separator = strrpos($key, '/');
		$parent = ($separator === false) ? '' : substr($key, 0, $separator);
		if (array_key_exists('parent', $args)) {
			if (!is_string($args['parent'])) return false;
			$parent = $args['parent'];
		}
		if ($parent !== '') {
			if (!$this->exists($parent) || $parent === $key || Text::startsWith($parent, $key . '/')) {
				return false;
			}
			$row['type'] = $this->db[$parent]['type']; // children inherit the parent type
		}

		// Slug
		// If the user change the slug the page key changes
		// If the user send an empty slug the page key doesn't change
		// This variable is not belong to the database so is not defined in $row
		if (!array_key_exists('slug', $args) || $args['slug'] === '') {
			$explode = explode('/', $key);
			$slug = end($explode);
		} else {
			$slug = $args['slug'];
		}

		// New key
		// The key of the page can change if the user change the slug or the parent, -
		// - if the user doesn't change the slug or the parent the key is going to be the same -
		// - as the current key.
		// This variable is not belong to the database so is not defined in $row
		$newKey = $this->generateKey($slug, $parent, false, $key);
		if (!is_string($newKey) || $newKey === '') return false;
		if (!in_array($row['type'], array('published', 'static', 'draft', 'sticky', 'scheduled', 'autosave'), true) ||
			!isset($row['uuid']) || !is_string($row['uuid']) ||
			$row['uuid'] !== $this->db[$key]['uuid']) {
			return false;
		}
		if (isset($args['content']) && (!is_string($args['content']) || strlen($args['content']) > BRENDY_MAX_CONTENT_BYTES)) {
			return false;
		}

		// if the date in the arguments is not valid, take the value from the old row
		if (!Valid::date($row['date'], DB_DATE_FORMAT)) {
			$row['date'] = $this->db[$key]['date'];
		}

		// Modified date
		$row['dateModified'] = Date::current(DB_DATE_FORMAT);

		// Schedule page
		if (($row['date'] > Date::current(DB_DATE_FORMAT)) && ($row['type'] === 'published')) {
			$row['type'] = 'scheduled';
		}

		$oldDatabase = $this->db;
		$oldContentPath = PATH_PAGES . $key . DS . FILENAME;
		if (!is_file($oldContentPath) || is_link($oldContentPath)) {
			return false;
		}
		$oldContent = isset($args['content']) ? Filesystem::readFileBounded($oldContentPath, BRENDY_MAX_CONTENT_BYTES, 0) : null;
		if (isset($args['content']) && !is_string($oldContent)) {
			return false;
		}

		// Move the directory from old key to new key only if the keys are different.
		$moved = false;
		if ($newKey !== $key) {
			if (Filesystem::mv(PATH_PAGES . $key, PATH_PAGES . $newKey) === false) {
				Log::set(__METHOD__ . LOG_SEP . 'Error occurred when trying to move the directory to ' . PATH_PAGES . $newKey);
				return false;
			}
			$moved = true;
		}

		// Replace content atomically, including when it is intentionally empty.
		$contentPath = PATH_PAGES . $newKey . DS . FILENAME;
		if (isset($args['content']) && !$this->writeContentFile($contentPath, $args['content'])) {
			if ($moved) {
				Filesystem::mv(PATH_PAGES . $newKey, PATH_PAGES . $key);
			}
			Log::set(__METHOD__ . LOG_SEP . 'Error occurred when trying to put the content in the file ' . FILENAME);
			return false;
		}

		// Remove the old row
		unset($this->db[$key]);

		// Reindex descendants before committing the new parent key. Abort on any
		// collision instead of silently overwriting an unrelated database row.
		if ($newKey !== $key && !$this->reindexChildren($key, $newKey)) {
			$this->db = $oldDatabase;
			$this->rollbackPageEdit($key, $newKey, $moved, $oldContent);
			return false;
		}

		$contentDigests = Filesystem::hashFileDigestsBounded(
			$contentPath, array('md5', 'sha256'), BRENDY_MAX_CONTENT_BYTES
		);
		$row['md5file'] = is_array($contentDigests) && isset($contentDigests['md5']) ? $contentDigests['md5'] : false;
		$row['contentHash'] = is_array($contentDigests) && isset($contentDigests['sha256']) ? $contentDigests['sha256'] : false;
		if ($row['md5file'] === false || $row['contentHash'] === false) {
			$this->db = $oldDatabase;
			$this->rollbackPageEdit($key, $newKey, $moved, $oldContent);
			return false;
		}

		// A page tree has one visibility/type boundary. Moving a parent below a
		// different parent or changing its type through the regular editor must not
		// leave published descendants below a draft/static root (or vice versa).
		$oldType = isset($oldDatabase[$key]['type']) && is_string($oldDatabase[$key]['type'])
			? $oldDatabase[$key]['type'] : '';
		if ($row['type'] !== $oldType) {
			foreach ($this->getDescendants($newKey) as $descendantKey) {
				if (!isset($this->db[$descendantKey]) || !is_array($this->db[$descendantKey])) {
					$this->db = $oldDatabase;
					$this->rollbackPageEdit($key, $newKey, $moved, $oldContent);
					return false;
				}
				$this->db[$descendantKey]['type'] = $row['type'];
			}
		}

		// Insert in database the new row
		$this->db[$newKey] = $row;

		// Sort database
		$this->sortBy();

		// Save database. If this fails, restore both the in-memory database and
		// page content/directory so metadata and files cannot diverge.
		if (!$this->save()) {
			$this->db = $oldDatabase;
			$this->rollbackPageEdit($key, $newKey, $moved, $oldContent);
			return false;
		}

		if ($moved) {
			// Create the new compatibility alias first so a failure never destroys a
			// still-working old URL. The canonical UUID media path remains authoritative.
			$newAliasCreated = false;
			if (!brendyEnsurePageUploadAlias($newKey, $this->db, $newAliasCreated)) {
				Log::set(__METHOD__ . LOG_SEP . 'Unable to create renamed page upload alias: ' . $newKey, LOG_TYPE_WARN);
			} elseif (!brendyRemovePageUploadAlias($key, $oldDatabase)) {
				Log::set(__METHOD__ . LOG_SEP . 'Unable to remove stale page upload alias: ' . $key, LOG_TYPE_WARN);
			}
		}

		return $newKey;
	}

	// Reindex every descendant when a page key changes. Only the leading
	// hierarchy prefix is replaced; repeated parent text deeper in the key must
	// remain untouched (a/a/c -> b/a/c, not b/b/c).
	public function reindexChildren($oldParentKey, $newParentKey)
	{
		if (!is_string($oldParentKey) || !is_string($newParentKey) ||
			$oldParentKey === '' || $newParentKey === '' || $oldParentKey === $newParentKey) {
			return false;
		}

		$oldPrefix = $oldParentKey . '/';
		$newPrefix = $newParentKey . '/';
		$mappings = array();
		$sourceKeys = array();

		foreach ($this->db as $key => $fields) {
			if (!is_string($key) || !Text::startsWith($key, $oldPrefix)) continue;
			$suffix = substr($key, strlen($oldPrefix));
			if ($suffix === '') return false;
			$targetKey = $newPrefix . $suffix;
			if (isset($mappings[$targetKey])) return false;
			$mappings[$targetKey] = array('source' => $key, 'fields' => $fields);
			$sourceKeys[$key] = true;
		}

		// Detect target collisions before mutating anything. A corrupted/orphaned
		// row at the destination must never be overwritten by a subtree rename.
		foreach ($mappings as $targetKey => $item) {
			if (array_key_exists($targetKey, $this->db) && !isset($sourceKeys[$targetKey])) return false;
		}

		foreach ($mappings as $targetKey => $item) unset($this->db[$item['source']]);
		foreach ($mappings as $targetKey => $item) $this->db[$targetKey] = $item['fields'];
		return true;
	}

	public function delete($key)
	{
		if (!isValidPageKey($key)) {
			return false;
		}

		// Page doesn't exist in database
		if (!$this->exists($key)) {
			Log::set(__METHOD__ . LOG_SEP . 'The page does not exist. Key: ' . $key);
			return false;
		}

		// A parent cannot be removed while descendants still reference its key.
		if (!empty($this->getDescendants($key))) {
			Log::set(__METHOD__ . LOG_SEP . 'Cannot delete a page with descendants: ' . $key, LOG_TYPE_ERROR);
			return false;
		}

		$pagePath = PATH_PAGES . $key;
		if (!is_dir($pagePath) || is_link($pagePath)) {
			return false;
		}
		$uuid = $this->getUUID($key);
		if (!is_string($uuid) || preg_match('/^(?:autosave-)?[a-f0-9]{32}$/iD', $uuid) !== 1) return false;
		$uploadsPath = PATH_UPLOADS_PAGES . $uuid;
		$staging = PATH_TMP . 'page-delete-' . bin2hex(random_bytes(8)) . DS;
		if (!Filesystem::mkdir($staging, true) || !Filesystem::mv($pagePath, $staging . 'page')) {
			if (Filesystem::directoryExists($staging)) Filesystem::deleteRecursive($staging);
			return false;
		}
		$movedUploads = false;
		$restoreStaging = function () use ($staging, $pagePath, $uploadsPath, &$movedUploads) {
			$success = true;
			if ($movedUploads && Filesystem::directoryExists($staging . 'uploads')) {
				if (!Filesystem::mv($staging . 'uploads', $uploadsPath)) $success = false;
			}
			if (Filesystem::directoryExists($staging . 'page')) {
				if (!Filesystem::mv($staging . 'page', $pagePath)) $success = false;
			}
			// Never purge staged data after a failed restore. Keeping it in PATH_TMP
			// is preferable to turning a recoverable transaction failure into data loss.
			if ($success && Filesystem::directoryExists($staging) && !Filesystem::deleteRecursive($staging)) $success = false;
			if (!$success) Log::set(__METHOD__ . LOG_SEP . 'Incomplete page-delete rollback; staged data preserved at ' . $staging, LOG_TYPE_ERROR);
			return $success;
		};

		if (is_dir($uploadsPath) && !is_link($uploadsPath)) {
			if (!Filesystem::mv($uploadsPath, $staging . 'uploads')) {
				$restoreStaging();
				return false;
			}
			$movedUploads = true;
		} elseif (file_exists($uploadsPath) || is_link($uploadsPath)) {
			$restoreStaging();
			return false;
		}

		$databaseBefore = $this->db;
		unset($this->db[$key]);
		if (!$this->save()) {
			$this->db = $databaseBefore;
			$restoreStaging();
			$this->recoverPersistedState();
			return false;
		}

		if (!brendyRemovePageUploadAlias($key, $databaseBefore)) {
			Log::set(__METHOD__ . LOG_SEP . 'Unable to remove page upload alias: ' . $key, LOG_TYPE_WARN);
		}
		if (defined('PATH_REVISIONS')) {
			$revisionPath = PATH_REVISIONS . strtolower($uuid);
			if (is_dir($revisionPath) && !is_link($revisionPath)) {
				if (!Filesystem::deleteRecursive($revisionPath)) {
					Log::set(__METHOD__ . LOG_SEP . 'Unable to remove page revision history: ' . $key, LOG_TYPE_ERROR);
				}
			} elseif (file_exists($revisionPath) || is_link($revisionPath)) {
				Log::set(__METHOD__ . LOG_SEP . 'Unsafe page revision path was not removed: ' . $key, LOG_TYPE_ERROR);
			}
		}
		if (!Filesystem::deleteRecursive($staging)) {
			Log::set(__METHOD__ . LOG_SEP . 'Unable to remove staged deleted page: ' . $staging, LOG_TYPE_ERROR);
		}
		return true;
	}

	// Delete all pages from a user
	public function deletePagesByUser($args)
	{
		if (!is_array($args) || !isset($args['username']) || !is_string($args['username'])) return false;
		$username = $args['username'];
		$keys = array();
		foreach ($this->db as $key => $fields) {
			if (is_string($key) && is_array($fields) && isset($fields['username']) && $fields['username'] === $username) {
				$keys[] = $key;
			}
		}
		if (empty($keys)) return true;

		$deleteSet = array_fill_keys($keys, true);
		// Do not delete a parent while retaining one of its descendants.
		foreach ($keys as $key) {
			$prefix = $key . '/';
			foreach ($this->db as $candidateKey => $candidateFields) {
				if (is_string($candidateKey) && strpos($candidateKey, $prefix) === 0 && !isset($deleteSet[$candidateKey])) {
					Log::set(__METHOD__ . LOG_SEP . 'Cannot delete user pages while a descendant belongs to another user: ' . $candidateKey, LOG_TYPE_ERROR);
					return false;
				}
			}
		}

		usort($keys, function ($a, $b) {
			return substr_count($b, '/') <=> substr_count($a, '/');
		});

		$stagingRoot = PATH_TMP . 'user-pages-delete-' . bin2hex(random_bytes(8)) . DS;
		if (!Filesystem::mkdir($stagingRoot, true)) return false;
		$staged = array();
		$databaseBefore = $this->db;

		$rollback = function () use (&$staged, $stagingRoot) {
			$success = true;
			foreach (array_reverse($staged) as $item) {
				if ($item['uploadsMoved'] && Filesystem::directoryExists($item['stagedUploads'])) {
					if (!Filesystem::mv($item['stagedUploads'], $item['uploadsPath'])) $success = false;
				}
				if (Filesystem::directoryExists($item['stagedPage'])) {
					if (!Filesystem::mv($item['stagedPage'], $item['pagePath'])) $success = false;
				}
			}
			// Do not delete a staging tree that still contains content which could not
			// be restored. Operators can recover that data instead of losing it.
			if ($success && Filesystem::directoryExists($stagingRoot) && !Filesystem::deleteRecursive($stagingRoot)) $success = false;
			if (!$success) Log::set(__METHOD__ . LOG_SEP . 'Incomplete user-page rollback; staged data preserved at ' . $stagingRoot, LOG_TYPE_ERROR);
			return $success;
		};

		foreach ($keys as $index => $key) {
			if (!isValidPageKey($key) || !$this->exists($key)) {
				$rollback();
				return false;
			}
			$uuid = $this->getUUID($key);
			if (!is_string($uuid) || preg_match('/^(?:autosave-)?[a-f0-9]{32}$/iD', $uuid) !== 1) {
				$rollback();
				return false;
			}
			$pagePath = PATH_PAGES . $key;
			$uploadsPath = PATH_UPLOADS_PAGES . $uuid;
			if (!is_dir($pagePath) || is_link($pagePath) || is_link($uploadsPath) ||
				(file_exists($uploadsPath) && !is_dir($uploadsPath))) {
				$rollback();
				return false;
			}

			$itemRoot = $stagingRoot . 'item-' . str_pad((string)$index, 6, '0', STR_PAD_LEFT) . DS;
			if (!Filesystem::mkdir($itemRoot, true)) {
				$rollback();
				return false;
			}
			$stagedPage = $itemRoot . 'page';
			$stagedUploads = $itemRoot . 'uploads';
			if (!Filesystem::mv($pagePath, $stagedPage)) {
				$rollback();
				return false;
			}
			// Register the page in the rollback journal immediately after its move.
			// If staging the uploads then fails, rollback must still know where the
			// already-moved page is; otherwise cleanup could delete the only copy.
			$staged[] = array(
				'key' => $key,
				'uuid' => strtolower($uuid),
				'pagePath' => $pagePath,
				'uploadsPath' => $uploadsPath,
				'stagedPage' => $stagedPage,
				'stagedUploads' => $stagedUploads,
				'uploadsMoved' => false
			);
			$stagedIndex = count($staged) - 1;
			if (is_dir($uploadsPath) && !is_link($uploadsPath)) {
				if (!Filesystem::mv($uploadsPath, $stagedUploads)) {
					$rollback();
					return false;
				}
				$staged[$stagedIndex]['uploadsMoved'] = true;
			}
		}

		foreach ($keys as $key) unset($this->db[$key]);
		if (!$this->save()) {
			$this->db = $databaseBefore;
			$rollback();
			$this->recoverPersistedState();
			return false;
		}

		foreach ($keys as $key) {
			$separator = strrpos($key, '/');
			$parentKey = $separator === false ? '' : substr($key, 0, $separator);
			// If the parent is deleted too, the nested alias already moved inside the
			// parent's staged UUID directory and will disappear with that staging tree.
			if ($parentKey !== '' && isset($deleteSet[$parentKey])) continue;
			if (!brendyRemovePageUploadAlias($key, $databaseBefore)) {
				Log::set(__METHOD__ . LOG_SEP . 'Unable to remove user page upload alias: ' . $key, LOG_TYPE_WARN);
			}
		}
		if (defined('PATH_REVISIONS')) {
			foreach ($staged as $item) {
				if (!isset($item['uuid']) || !is_string($item['uuid'])) continue;
				$revisionPath = PATH_REVISIONS . $item['uuid'];
				if (is_dir($revisionPath) && !is_link($revisionPath)) {
					if (!Filesystem::deleteRecursive($revisionPath)) {
						Log::set(__METHOD__ . LOG_SEP . 'Unable to remove page revision history: ' . $item['key'], LOG_TYPE_ERROR);
					}
				} elseif (file_exists($revisionPath) || is_link($revisionPath)) {
					Log::set(__METHOD__ . LOG_SEP . 'Unsafe page revision path was not removed: ' . $item['key'], LOG_TYPE_ERROR);
				}
			}
		}
		if (Filesystem::directoryExists($stagingRoot) && !Filesystem::deleteRecursive($stagingRoot)) {
			Log::set(__METHOD__ . LOG_SEP . 'Unable to remove committed user-page staging directory: ' . $stagingRoot, LOG_TYPE_ERROR);
		}
		return true;
	}

	// Link all pages to a new user
	public function transferPages($args)
	{
		if (!is_array($args) || !isset($args['oldUsername']) || !is_string($args['oldUsername']) ||
			(isset($args['newUsername']) && !is_string($args['newUsername']))) {
			return false;
		}
		$oldUsername = $args['oldUsername'];
		$newUsername = isset($args['newUsername']) ? $args['newUsername'] : 'admin';

		$before = $this->db;
		foreach ($this->db as $key => $fields) {
			if (is_array($fields) && isset($fields['username']) && $fields['username'] === $oldUsername) {
				$this->db[$key]['username'] = $newUsername;
			}
		}

		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return true;
	}

	// Set field = value
	public function setField($key, $field, $value)
	{
		if (!is_string($key) || !is_string($field) || !$this->exists($key) ||
			!array_key_exists($field, $this->dbFields)) return false;
		$default = $this->dbFields[$field];
		$valid = false;
		if ($field === 'custom') {
			$value = $this->normalizeCustomValues($value, array());
			if ($value === false) return false;
		} elseif ($field === 'tags') {
			if (is_string($value)) {
				$value = $this->generateTags($value);
				if ($value === false) return false;
			} else {
				$value = $this->normalizeRowField($field, $value, $default, false, $valid);
				if (!$valid) return false;
			}
		} else {
			$value = $this->normalizeRowField($field, $value, $default, true, $valid);
			if (!$valid) return false;
		}
		if ($field === 'type' && !in_array($value, array('published', 'static', 'draft', 'sticky', 'scheduled', 'autosave'), true)) {
			return false;
		}
		$before = array_key_exists($field, $this->db[$key]) ? $this->db[$key][$field] : null;
		$this->db[$key][$field] = $value;
		if (!$this->save()) {
			$this->db[$key][$field] = $before;
			return false;
		}
		return true;
	}

	// Clear local cover-image references before the underlying media file is removed.
	// When a UUID is supplied, only that page can be changed; shared-media mode
	// clears every page that references the same local filename in one database write.
	public function clearCoverImageReferences($filename, $uuid = null)
	{
		if (!is_string($filename) || $filename === '' || strlen($filename) > 255 ||
			basename($filename) !== $filename || strpos($filename, "\0") !== false) {
			return false;
		}
		if ($uuid !== null && (!is_string($uuid) || preg_match('/^[a-f0-9]{32}$/iD', $uuid) !== 1)) {
			return false;
		}

		$before = $this->db;
		$changed = 0;
		foreach ($this->db as $key => $row) {
			if (!is_string($key) || !is_array($row)) continue;
			if ($uuid !== null && (!isset($row['uuid']) || !is_string($row['uuid']) || !hash_equals($row['uuid'], $uuid))) continue;
			if (!isset($row['coverImage']) || !is_string($row['coverImage']) || $row['coverImage'] !== $filename) continue;
			$this->db[$key]['coverImage'] = '';
			$changed++;
		}
		if ($changed === 0) return 0;
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return $changed;
	}

	// Atomically change the type of a page and every descendant. Page creation
	// already inherits the parent type; keeping that invariant on later type
	// changes prevents mixed public/draft trees and hidden static descendants.
	public function setTypeForSubtree($key, $type)
	{
		$allowed = array('published', 'static', 'draft', 'sticky');
		if (!is_string($key) || !is_string($type) || !$this->exists($key) || !in_array($type, $allowed, true)) return false;
		$targets = array_merge(array($key), $this->getDescendants($key));
		$before = $this->db;
		foreach ($targets as $target) {
			if (!isset($this->db[$target]) || !is_array($this->db[$target])) {
				$this->db = $before;
				return false;
			}
			$this->db[$target]['type'] = $type;
		}
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return count($targets);
	}

	// Returns a database with all pages
	// $onlyKeys = true; Returns only the pages keys
	// $onlyKeys = false; Returns part of the database, I do not recommend use this
	public function getDB($onlyKeys = true)
	{
		$tmp = $this->db;
		if ($onlyKeys) {
			return array_keys($tmp);
		}
		return $tmp;
	}

	// Returns a database with published pages
	// $onlyKeys = true; Returns only the pages keys
	// $onlyKeys = false; Returns part of the database, I do not recommend use this
	public function getPublishedDB($onlyKeys = true)
	{
		$tmp = $this->db;
		foreach ($tmp as $key => $fields) {
			if (!$this->hasType($fields, 'published')) {
				unset($tmp[$key]);
			}
		}
		if ($onlyKeys) {
			return array_keys($tmp);
		}
		return $tmp;
	}

	// Returns an array with a list of keys/database of static pages
	// By default the static pages are sort by position
	public function getStaticDB($onlyKeys = true)
	{
		$tmp = $this->db;
		foreach ($tmp as $key => $fields) {
			if (!$this->hasType($fields, 'static')) {
				unset($tmp[$key]);
			}
		}
		uasort($tmp, array($this, 'sortByPositionLowToHigh'));
		if ($onlyKeys) {
			return array_keys($tmp);
		}
		return $tmp;
	}

	// Returns an array with a list of keys/database of draft pages
	public function getDraftDB($onlyKeys = true)
	{
		$tmp = $this->db;
		foreach ($tmp as $key => $fields) {
			if (!$this->hasType($fields, 'draft')) {
				unset($tmp[$key]);
			}
		}
		if ($onlyKeys) {
			return array_keys($tmp);
		}
		return $tmp;
	}

	// Returns an array with a list of keys/database of autosave pages
	public function getAutosaveDB($onlyKeys = true)
	{
		$tmp = $this->db;
		foreach ($tmp as $key => $fields) {
			if (!$this->hasType($fields, 'autosave')) {
				unset($tmp[$key]);
			}
		}
		if ($onlyKeys) {
			return array_keys($tmp);
		}
		return $tmp;
	}

	// Returns an array with a list of keys/database of scheduled pages
	public function getScheduledDB($onlyKeys = true)
	{
		$tmp = $this->db;
		foreach ($tmp as $key => $fields) {
			if (!$this->hasType($fields, 'scheduled')) {
				unset($tmp[$key]);
			}
		}
		if ($onlyKeys) {
			return array_keys($tmp);
		}
		return $tmp;
	}

	// Returns an array with a list of keys of sticky pages
	public function getStickyDB($onlyKeys = true)
	{
		$tmp = $this->db;
		foreach ($tmp as $key => $fields) {
			if (!$this->hasType($fields, 'sticky')) {
				unset($tmp[$key]);
			}
		}
		if ($onlyKeys) {
			return array_keys($tmp);
		}
		return $tmp;
	}

	// Returns the next number of the bigger position
	public function nextPositionNumber()
	{
		$tmp = 0;
		foreach ($this->db as $key => $fields) {
			if (is_array($fields) && isset($fields['position']) && is_int($fields['position']) && $fields['position'] > $tmp) {
				$tmp = $fields['position'];
			}
		}
		return min(1000000000, $tmp + 1);
	}

	// Returns the next page key of the current page key
	public function nextPageKey($currentKey)
	{
		if (!$this->exists($currentKey) || !$this->hasType($this->db[$currentKey], 'published') || !$this->isPubliclyAccessible($currentKey)) return false;
		$keys = array_keys($this->db);
		$currentPosition = array_search($currentKey, $keys, true);
		if ($currentPosition === false) return false;
		// Draft/static/scheduled rows may sit between two published posts in the
		// globally sorted database. Skip them instead of incorrectly reporting that
		// there is no next public post.
		for ($position = $currentPosition - 1; $position >= 0; $position--) {
			$nextKey = $keys[$position];
			if (isset($this->db[$nextKey]) && $this->hasType($this->db[$nextKey], 'published') && $this->isPubliclyAccessible($nextKey)) return $nextKey;
		}
		return false;
	}

	// Returns the previous page key of the current page key
	public function previousPageKey($currentKey)
	{
		if (!$this->exists($currentKey) || !$this->hasType($this->db[$currentKey], 'published') || !$this->isPubliclyAccessible($currentKey)) return false;
		$keys = array_keys($this->db);
		$currentPosition = array_search($currentKey, $keys, true);
		if ($currentPosition === false) return false;
		$count = count($keys);
		for ($position = $currentPosition + 1; $position < $count; $position++) {
			$previousKey = $keys[$position];
			if (isset($this->db[$previousKey]) && $this->hasType($this->db[$previousKey], 'published') && $this->isPubliclyAccessible($previousKey)) return $previousKey;
		}
		return false;
	}

	// Returns an array with a list of page keys, FALSE if out of range.
	// Filtering is completed before pagination so counts and page slices always
	// describe the same result set (including the optional untagged filter).
	public function getList($pageNumber, $numberOfItems, $published = true, $static = false, $sticky = false, $draft = false, $scheduled = false, $untagged = false, $username = null)
	{
		$pageNumber = filter_var($pageNumber, FILTER_VALIDATE_INT);
		$numberOfItems = filter_var($numberOfItems, FILTER_VALIDATE_INT);
		if ($pageNumber === false || $pageNumber < 1 || $pageNumber > 1000000000 || $numberOfItems === false ||
			($numberOfItems !== -1 && ($numberOfItems < 1 || $numberOfItems > 1000000))) return false;
		$filters = $this->normalizeListFilters($published, $static, $sticky, $draft, $scheduled, $untagged);
		if ($filters === false) return false;

		$list = $this->keysMatchingFilters($filters);
		$list = $this->filterKeysByUsername($list, $username);
		if ($list === false) return false;
		if ($numberOfItems === -1) return $list;

		$init = $numberOfItems * ($pageNumber - 1);
		if ($init >= count($list)) return $pageNumber === 1 ? array() : false;
		return array_slice($list, $init, $numberOfItems, true);
	}

	// Public list/count variants filter hierarchy visibility before pagination.
	// This keeps page sizes and paginator totals consistent when a legacy or
	// scheduled child is non-public because one of its ancestors is hidden.
	public function getPublicList($pageNumber, $numberOfItems, $published = true, $static = false, $sticky = false, $untagged = false)
	{
		$pageNumber = filter_var($pageNumber, FILTER_VALIDATE_INT);
		$numberOfItems = filter_var($numberOfItems, FILTER_VALIDATE_INT);
		if ($pageNumber === false || $pageNumber < 1 || $pageNumber > 1000000000 || $numberOfItems === false ||
			($numberOfItems !== -1 && ($numberOfItems < 1 || $numberOfItems > 1000000))) return false;
		$filters = $this->normalizeListFilters($published, $static, $sticky, false, false, $untagged);
		if ($filters === false) return false;
		$list = array();
		foreach ($this->keysMatchingFilters($filters) as $key) {
			if ($this->isPubliclyAccessible($key)) $list[] = $key;
		}
		if ($numberOfItems === -1) return $list;
		$init = $numberOfItems * ($pageNumber - 1);
		if ($init >= count($list)) return $pageNumber === 1 ? array() : false;
		return array_slice($list, $init, $numberOfItems, true);
	}

	public function countPublic($published = true, $static = false, $sticky = false, $untagged = false)
	{
		$list = $this->getPublicList(1, -1, $published, $static, $sticky, $untagged);
		return is_array($list) ? count($list) : 0;
	}

	// Returns the amount of pages.
	public function count($onlyPublished = true)
	{
		if ($onlyPublished) {
			$db = $this->getPublishedDB(false);
			return count($db);
		}
		return count($this->db);
	}

	// Returns the count matching the same filters used by getList().
	public function countByType($published = true, $static = false, $sticky = false, $draft = false, $scheduled = false, $untagged = false, $username = null)
	{
		$filters = $this->normalizeListFilters($published, $static, $sticky, $draft, $scheduled, $untagged);
		if ($filters === false) return 0;
		$list = $this->filterKeysByUsername($this->keysMatchingFilters($filters), $username);
		return is_array($list) ? count($list) : 0;
	}

	private function filterKeysByUsername($list, $username)
	{
		if (!is_array($list)) return false;
		if ($username === null) return $list;
		if (!is_string($username) || $username === '' || strlen($username) > 255) return false;

		$result = array();
		foreach ($list as $key) {
			if (!is_string($key) || !isset($this->db[$key]) || !is_array($this->db[$key])) continue;
			if (isset($this->db[$key]['username']) && is_string($this->db[$key]['username']) && $this->db[$key]['username'] === $username) {
				$result[] = $key;
			}
		}
		return $result;
	}

	private function normalizeListFilters($published, $static, $sticky, $draft, $scheduled, $untagged)
	{
		$input = array(
			'published' => $published, 'static' => $static, 'sticky' => $sticky,
			'draft' => $draft, 'scheduled' => $scheduled, 'untagged' => $untagged
		);
		$result = array();
		foreach ($input as $key => $value) {
			$normalized = brendyBooleanValue($value, null);
			if ($normalized === null) return false;
			$result[$key] = $normalized;
		}
		return $result;
	}

	private function keysMatchingFilters($filters)
	{
		$untagged = $filters['untagged'];
		$list = array();
		$allowedTypes = array('published', 'static', 'sticky', 'draft', 'scheduled');
		foreach ($this->db as $key => $fields) {
			if (!is_string($key) || !is_array($fields) || !isset($fields['type']) || !is_string($fields['type'])) continue;
			if (!in_array($fields['type'], $allowedTypes, true) || !$filters[$fields['type']]) continue;
			if ($untagged) {
				$tags = isset($fields['tags']) && is_array($fields['tags']) ? $fields['tags'] : array();
				if (!empty($tags)) continue;
			}
			$list[] = $key;
		}
		return $list;
	}

	// Returns an array with all parents pages key. A parent page is not a child
	public function getParents()
	{
		$db = $this->getPublishedDB();
		foreach ($db as $key => $pageKey) {
			// if the key has slash then is a child
			if (Text::stringContains($pageKey, '/')) {
				unset($db[$key]);
			}
		}
		return $db;
	}

	// A public row is not enough by itself: every ancestor in its hierarchy must
	// also be public. This prevents a published child from leaking through a
	// draft/scheduled/autosave (or missing/corrupt) parent after legacy imports,
	// scheduler transitions or partial external edits.
	public function isPubliclyAccessible($key)
	{
		if (!is_string($key) || $key === '') return false;
		$publicTypes = array('published', 'sticky', 'static');
		$current = $key;
		for ($depth = 0; $depth < 200; $depth++) {
			if (!$this->exists($current)) return false;
			$row = $this->db[$current];
			if (!isset($row['type']) || !is_string($row['type']) || !in_array($row['type'], $publicTypes, true)) return false;
			$separator = strrpos($current, '/');
			if ($separator === false) return true;
			$current = substr($current, 0, $separator);
			if ($current === '') return false;
		}
		return false;
	}

	// Preserve the historical public API: getChildren() returns every descendant
	// below a page, not only the next hierarchy level. Core code that needs a
	// structural tree uses getDirectChildren() explicitly.
	public function getChildren($parentKey)
	{
		return $this->getDescendants($parentKey);
	}

	// Return only the immediate next hierarchy level.
	public function getDirectChildren($parentKey)
	{
		if (!is_string($parentKey) || $parentKey === '') return array();
		$prefix = $parentKey . '/';
		$list = array();
		foreach ($this->db as $key => $fields) {
			if (!is_string($key) || !Text::startsWith($key, $prefix)) continue;
			$relative = substr($key, strlen($prefix));
			if ($relative !== '' && strpos($relative, '/') === false) $list[] = $key;
		}
		return $list;
	}

	// Return only immediate children whose content type is explicitly allowed.
	// Public renderers use this so drafts, scheduled pages and autosaves cannot
	// leak through a published navigation tree.
	public function getDirectChildrenByTypes($parentKey, $types)
	{
		if (!is_string($parentKey) || $parentKey === '' || !is_array($types) || empty($types)) return array();
		$allowedTypes = array('published', 'static', 'sticky', 'draft', 'scheduled', 'autosave');
		$types = array_values(array_unique(array_filter($types, function ($type) use ($allowedTypes) {
			return is_string($type) && in_array($type, $allowedTypes, true);
		})));
		if (empty($types)) return array();
		$prefix = $parentKey . '/';
		$list = array();
		foreach ($this->db as $key => $fields) {
			if (!is_string($key) || !is_array($fields) || !isset($fields['type']) || !is_string($fields['type']) ||
				!in_array($fields['type'], $types, true) || !Text::startsWith($key, $prefix)) continue;
			$relative = substr($key, strlen($prefix));
			if ($relative !== '' && strpos($relative, '/') === false) $list[] = $key;
		}
		return $list;
	}

	// Return every descendant at any depth. Destructive operations use this
	// method when they need to know whether a complete subtree exists.
	public function getDescendants($parentKey)
	{
		if (!is_string($parentKey) || $parentKey === '') return array();
		$prefix = $parentKey . '/';
		$list = array();
		foreach ($this->db as $key => $fields) {
			if (is_string($key) && Text::startsWith($key, $prefix)) $list[] = $key;
		}
		return $list;
	}

	public function sortBy()
	{
		if (ORDER_BY === 'date') {
			return $this->sortByDate(true);
		}
		return $this->sortByPosition(false);
	}

	// Sort pages by position
	public function sortByPosition($HighToLow = false)
	{
		if ($HighToLow) {
			uasort($this->db, array($this, 'sortByPositionHighToLow'));
		} else {
			uasort($this->db, array($this, 'sortByPositionLowToHigh'));
		}
		return true;
	}

	private function sortByPositionLowToHigh($a, $b)
	{
		$positionA = is_array($a) && isset($a['position']) && is_int($a['position']) ? $a['position'] : 0;
		$positionB = is_array($b) && isset($b['position']) && is_int($b['position']) ? $b['position'] : 0;
		if ($positionA < $positionB) {
			return -1;
		} elseif ($positionA > $positionB) {
			return 1;
		} else {
			return 0;
		}
	}

	private function sortByPositionHighToLow($a, $b)
	{
		$positionA = is_array($a) && isset($a['position']) && is_int($a['position']) ? $a['position'] : 0;
		$positionB = is_array($b) && isset($b['position']) && is_int($b['position']) ? $b['position'] : 0;
		if ($positionA > $positionB) {
			return -1;
		} elseif ($positionA < $positionB) {
			return 1;
		} else {
			return 0;
		}
	}

	// Sort pages by date
	public function sortByDate($HighToLow = true)
	{
		if ($HighToLow) {
			uasort($this->db, array($this, 'sortByDateHighToLow'));
		} else {
			uasort($this->db, array($this, 'sortByDateLowToHigh'));
		}
		return true;
	}

	private function sortByDateLowToHigh($a, $b)
	{
		$dateA = is_array($a) && isset($a['date']) && is_string($a['date']) ? $a['date'] : '';
		$dateB = is_array($b) && isset($b['date']) && is_string($b['date']) ? $b['date'] : '';
		return $dateA <=> $dateB;
	}
	private function sortByDateHighToLow($a, $b)
	{
		$dateA = is_array($a) && isset($a['date']) && is_string($a['date']) ? $a['date'] : '';
		$dateB = is_array($b) && isset($b['date']) && is_string($b['date']) ? $b['date'] : '';
		return $dateB <=> $dateA;
	}

	private function hasType($fields, $type)
	{
		return is_array($fields) && isset($fields['type']) && is_string($fields['type']) && $fields['type'] === $type;
	}

	private function normalizeRowField($field, $input, $default, $sanitize, &$valid)
	{
		$valid = false;
		if (is_string($default)) {
			if (!is_string($input)) return false;
			$limits = array(
				'title' => 500, 'description' => 5000, 'username' => 200,
				'date' => 32, 'dateModified' => 32, 'coverImage' => 2048,
				'category' => 200, 'md5file' => 64, 'contentHash' => 64, 'uuid' => 41, 'template' => 200,
				'type' => 20
			);
			$limit = isset($limits[$field]) ? $limits[$field] : 10000;
			if (strlen($input) > $limit) return false;
			if ($field === 'coverImage' && $input !== '') {
				$rawImage = Sanitize::htmlDecode($input);
				$urlParts = parse_url($rawImage);
				$isExternal = filter_var($rawImage, FILTER_VALIDATE_URL) && is_array($urlParts) &&
					isset($urlParts['scheme'], $urlParts['host']) &&
					in_array(strtolower($urlParts['scheme']), array('http', 'https'), true) &&
					!isset($urlParts['user']) && !isset($urlParts['pass']) && !preg_match('/[\x00-\x20\x7F]/', $rawImage);
				$extension = Text::lowercase(Filesystem::extension($rawImage));
				$isLocal = basename($rawImage) === $rawImage && strpos($rawImage, "\0") === false &&
					in_array($extension, $GLOBALS['ALLOWED_IMG_EXTENSION'], true);
				if (!$isExternal && !$isLocal) return false;
			}
			$valid = true;
			return $sanitize ? Sanitize::html($input) : $input;
		}
		if (is_int($default)) {
			$integer = is_scalar($input) ? filter_var($input, FILTER_VALIDATE_INT) : false;
			if ($integer === false || $integer < 0 || $integer > 1000000000) return false;
			$valid = true;
			return (int)$integer;
		}
		if (is_bool($default)) {
			if (is_bool($input)) {
				$valid = true;
				return $input;
			}
			if (is_string($input)) {
				$normalized = strtolower(trim($input));
				if (in_array($normalized, array('true', '1', 'on'), true)) {
					$valid = true;
					return true;
				}
				if (in_array($normalized, array('false', '0', 'off'), true)) {
					$valid = true;
					return false;
				}
			}
			return false;
		}
		if (is_array($default) && is_array($input) && !$sanitize && $field === 'tags' && count($input) <= 1000) {
			foreach ($input as $key => $value) {
				if (!is_string($key) || !is_string($value) || strlen($key) > 200 || strlen($value) > 500) {
					return false;
				}
			}
			$valid = true;
			return $input;
		}
		return false;
	}

	private function normalizeCustomValues($input, $existing = array())
	{
		global $site;
		$result = is_array($existing) ? $existing : array();
		$definitions = $site->customFields();
		if (!is_array($input) || !is_array($definitions)) {
			return $result;
		}

		foreach ($definitions as $field => $options) {
			if (!is_string($field) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $field) !== 1 ||
				!is_array($options) || !isset($options['type']) ||
				!in_array($options['type'], array('string', 'bool'), true)) {
				continue;
			}
			if (array_key_exists($field, $input)) {
				$value = $input[$field];
			} elseif (!isset($result[$field]) && array_key_exists('default', $options)) {
				$value = $options['default'];
			} else {
				continue;
			}
			if ($options['type'] === 'bool') {
				$value = brendyBooleanValue($value, null);
				if ($value === null) return false;
			} else {
				if (!is_scalar($value)) return false;
				$value = (string)$value;
				if (strlen($value) > 10000) return false;
				$value = Sanitize::html($value);
			}
			$result[$field] = array('value' => $value);
		}
		return $result;
	}

	private function writeContentFile($path, $content)
	{
		if (!is_string($path) || !is_string($content) || !is_dir(dirname($path)) ||
			is_link(dirname($path)) || is_link($path) || (file_exists($path) && !is_file($path))) {
			return false;
		}
		$permissions = file_exists($path) ? (fileperms($path) & 0777) : 0644;
		return Filesystem::atomicWrite($path, $content, $permissions);
	}

	private function rollbackPageEdit($oldKey, $newKey, $moved, $oldContent)
	{
		$currentContent = PATH_PAGES . $newKey . DS . FILENAME;
		if ($oldContent !== null && !$this->writeContentFile($currentContent, $oldContent)) {
			Log::set(__METHOD__ . LOG_SEP . 'Unable to restore page content during rollback.', LOG_TYPE_ERROR);
		}
		if ($moved && !Filesystem::mv(PATH_PAGES . $newKey, PATH_PAGES . $oldKey)) {
			Log::set(__METHOD__ . LOG_SEP . 'Unable to restore page directory during rollback.', LOG_TYPE_ERROR);
		}
	}

	function generateUUID()
	{
		return bin2hex(random_bytes(16));
	}

	// Returns the UUID of a page, by the page key
	function getUUID($key)
	{
		if ($this->exists($key) && isset($this->db[$key]['uuid']) && is_string($this->db[$key]['uuid']) &&
			preg_match('/^(?:autosave-)?[a-f0-9]{32}$/iD', $this->db[$key]['uuid']) === 1) {
			return $this->db[$key]['uuid'];
		}
		return false;
	}

	// Returns the page key by the uuid
	// if the UUID doesn't exist, returns FALSE
	function getByUUID($uuid)
	{
		if (!is_string($uuid) || preg_match('/^(?:autosave-)?[a-f0-9]{32}$/iD', $uuid) !== 1) return false;
		foreach ($this->db as $key => $value) {
			if (is_string($key) && is_array($value) && isset($value['uuid']) &&
				is_string($value['uuid']) && hash_equals($value['uuid'], $uuid)) {
				return $key;
			}
		}
		return false;
	}


	// Returns string without HTML tags and truncated
	private function generateSlug($text, $truncateLength = 60)
	{
		$tmpslug = Text::removeHTMLTags($text);
		$tmpslug = Text::removeLineBreaks($tmpslug);
		$tmpslug = Text::truncate($tmpslug, $truncateLength, '');
		return $tmpslug;
	}

	private function scheduledPageIsPublishable($pageKey, $fields, $currentDate)
	{
		if (!is_string($pageKey) || !is_array($fields) || !is_string($currentDate) ||
			!isset($fields['type'], $fields['date']) || !is_string($fields['type']) || !is_string($fields['date']) ||
			$fields['type'] !== 'scheduled' || !Valid::date($fields['date'], DB_DATE_FORMAT) || $fields['date'] > $currentDate) {
			return false;
		}

		// A scheduled child must never become public before a non-public parent.
		// Scheduled ancestors that are due in this same pass are considered eligible,
		// so a complete due chain can publish atomically regardless of database order.
		$ancestor = $pageKey;
		for ($depth = 0; $depth < 200; $depth++) {
			$separator = strrpos($ancestor, '/');
			if ($separator === false) return true;
			$ancestor = substr($ancestor, 0, $separator);
			if ($ancestor === '' || !$this->exists($ancestor)) return false;
			$ancestorRow = $this->db[$ancestor];
			$ancestorType = isset($ancestorRow['type']) && is_string($ancestorRow['type']) ? $ancestorRow['type'] : '';
			if (in_array($ancestorType, array('published', 'sticky', 'static'), true)) continue;
			if ($ancestorType === 'scheduled' && isset($ancestorRow['date']) && is_string($ancestorRow['date']) &&
				Valid::date($ancestorRow['date'], DB_DATE_FORMAT) && $ancestorRow['date'] <= $currentDate) continue;
			return false;
		}

		// Page keys are bounded well below this depth in normal operation. If a
		// malformed/cyclic structure ever exceeds the guard, fail closed.
		return false;
	}

	// Cheap read-only preflight used by the frontend scheduler. It prevents every
	// request from taking the global content-mutation lock when nothing is due.
	public function hasPublishableScheduledPages()
	{
		$currentDate = Date::current(DB_DATE_FORMAT);
		foreach ($this->db as $pageKey => $fields) {
			if ($this->scheduledPageIsPublishable($pageKey, $fields, $currentDate)) return true;
		}
		return false;
	}

	// Read-only diagnostics helper. A small grace period avoids warning while a
	// scheduled page is merely waiting for the next normal frontend request.
	public function overduePublishableScheduledCount($graceSeconds = 300)
	{
		$grace = filter_var($graceSeconds, FILTER_VALIDATE_INT);
		if ($grace === false) $grace = 300;
		$grace = max(0, min(86400, (int)$grace));
		$currentDate = Date::current(DB_DATE_FORMAT);
		$threshold = date(DB_DATE_FORMAT, time() - $grace);
		$count = 0;
		foreach ($this->db as $pageKey => $fields) {
			if (!is_array($fields) || !isset($fields['date']) || !is_string($fields['date']) ||
				!Valid::date($fields['date'], DB_DATE_FORMAT) || $fields['date'] > $threshold) continue;
			if ($this->scheduledPageIsPublishable($pageKey, $fields, $currentDate)) $count++;
		}
		return $count;
	}

	// Returns TRUE if there are new pages published, FALSE otherwise
	public function scheduler()
	{
		$currentDate = Date::current(DB_DATE_FORMAT);
		$saveDatabase = false;
		$databaseBefore = $this->db;

		// Scan every row. A newer published page can sort before an older scheduled
		// page that has just become due; stopping at the first published row would
		// leave that scheduled page stuck forever.
		foreach ($this->db as $pageKey => $fields) {
			if (!$this->scheduledPageIsPublishable($pageKey, $fields, $currentDate)) continue;
			$this->db[$pageKey]['type'] = 'published';
			$saveDatabase = true;
		}

		if ($saveDatabase) {
			if ($this->save() === false) {
				$this->db = $databaseBefore;
				Log::set(__METHOD__ . LOG_SEP . 'Error occurred when trying to save the database file.');
				return false;
			}

			Log::set(__METHOD__ . LOG_SEP . 'New pages published from the scheduler.');
			return true;
		}

		return false;
	}

	// Generate a valid Key/Slug
	public function generateKey($text, $parent = false, $returnSlug = false, $oldKey = '')
	{
		global $L;

		if (!is_string($text) || strlen($text) > 1000 ||
			(!is_string($parent) && $parent !== false) ||
			(is_string($parent) && strlen($parent) > 200) ||
			!is_string($oldKey) || strlen($oldKey) > 200) return false;

		$slug = Text::cleanUrl(Text::isEmpty($text) ? $L->g('empty') : $text);
		if ($slug === '') $slug = Text::cleanUrl($L->g('empty'));
		$parentKey = Text::isEmpty($parent) ? '' : $parent;
		if ($parentKey !== '' && !$this->exists($parentKey)) return false;
		$available = 180 - ($parentKey === '' ? 0 : strlen($parentKey) + 1);
		if ($available < 1) return false;
		$slug = mb_strcut($slug, 0, $available, CHARSET);
		if ($slug === '') return false;
		$newKey = $parentKey === '' ? $slug : $parentKey . '/' . $slug;

		if ($newKey !== $oldKey) {
			// Verify if the key is already been used
			if (isset($this->db[$newKey])) {
				$baseKey = $newKey;
				$found = false;
				for ($i = 0; $i < 100000; $i++) {
					$candidate = $baseKey . '-' . $i;
					if (!isset($this->db[$candidate])) {
						$newKey = $candidate;
						$found = true;
						break;
					}
				}
				if (!$found) return false;
			}
		}
		if (strlen($newKey) > 200) return false;

		if ($returnSlug) {
			$explode = explode('/', $newKey);
			return end($explode);
		}

		return $newKey;
	}

	// Returns an Array, array('tagSlug'=>'tagName')
	// (string) $tags, tag list separated by comma.
	public function generateTags($tags)
	{
		$tmp = array();
		if (!is_string($tags) || strlen($tags) > 100000) return false;
		$tags = trim($tags);
		if ($tags === '') {
			return $tmp;
		}

		$tags = explode(',', $tags, 1001);
		if (count($tags) > 1000) return false;
		foreach ($tags as $tag) {
			$tag = trim(Sanitize::removeTags($tag));
			if ($tag === '') {
				continue;
			}
			if (strlen($tag) > 500) return false;
			$tagKey = Text::cleanUrl($tag);
			if ($tagKey === '') {
				continue;
			}
			$tmp[$tagKey] = htmlspecialchars($tag, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, CHARSET);
		}
		return $tmp;
	}

	// Change all pages with the old category key to the new category key
	public function changeCategory($oldCategoryKey, $newCategoryKey)
	{
		if (!is_string($oldCategoryKey) || !is_string($newCategoryKey) || strlen($oldCategoryKey) > 200 ||
			strlen($newCategoryKey) > 200) return false;
		$before = $this->db;
		foreach ($this->db as $key => $value) {
			if (is_array($value) && isset($value['category']) && $value['category'] === $oldCategoryKey) {
				$this->db[$key]['category'] = $newCategoryKey;
			}
		}
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return true;
	}

	// Insert custom fields to all the pages in the database
	// The structure for the custom fields need to be a valid JSON format
	// The custom fields are incremental, this means the custom fields are never deleted
	// The pages only store the value of the custom field, the structure of the custom fields are in the database site.php
	public function setCustomFields($fields)
	{
		if (!is_string($fields)) {
			return false;
		}
		$customFields = json_decode($fields, true, 64);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($customFields) || count($customFields) > 100) {
			return false;
		}
		foreach ($customFields as $customField => $customValues) {
			if (!is_string($customField) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $customField) !== 1 ||
				!is_array($customValues) || !isset($customValues['type']) ||
				!in_array($customValues['type'], array('string', 'bool'), true)) return false;
		}
		$before = $this->db;
		foreach ($this->db as $pageKey => $pageFields) {
			if (!is_string($pageKey) || !is_array($pageFields)) {
				$this->db = $before;
				return false;
			}
			if (!isset($pageFields['custom']) || !is_array($pageFields['custom'])) {
				$this->db[$pageKey]['custom'] = array();
				$pageFields['custom'] = array();
			}
			foreach ($customFields as $customField => $customValues) {
				if (!isset($pageFields['custom'][$customField])) {
					$defaultValue = '';
					if (isset($customValues['default'])) {
						$defaultValue = $customValues['default'];
					}
					if ($customValues['type'] === 'bool') {
						$defaultValue = brendyBooleanValue($defaultValue, null);
						if ($defaultValue === null) {
							$this->db = $before;
							return false;
						}
					} else {
						if (!is_scalar($defaultValue) || strlen((string)$defaultValue) > 10000) {
							$this->db = $before;
							return false;
						}
						$defaultValue = Sanitize::html((string)$defaultValue);
					}
					$this->db[$pageKey]['custom'][$customField]['value'] = $defaultValue;
				}
			}
		}

		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return true;
	}
}
