<?php defined('BRENDY') or die('Brendy CMS.');

class BrendyJsonDatabase {

	private const JSON_MAX_DEPTH = 128;
	private const JSON_MAX_BYTES = 134217728;

	public $db;
	public $dbBackup;
	// Optional schema defaults used by subclasses such as Site. Defining the
	// property here prevents undefined-property notices when generic callers ask
	// for a missing field on a database without its own schema.
	public $dbFields = array();
	public $file;
	public $firstLine;
	private $loadedHash = null;
	private $loadValid = true;
	private $maxBytes = self::JSON_MAX_BYTES;

	// $file, the JSON file.
	// $firstLine, TRUE if you want to remove the first line, FALSE otherwise
	function __construct($file, $firstLine=true, $maxBytes=self::JSON_MAX_BYTES)
	{
		$this->file = $file;
		$this->db = array();
		$this->dbBackup = array();
		$this->firstLine = $firstLine;
		$this->maxBytes = is_scalar($maxBytes) ? max(1, min(self::JSON_MAX_BYTES, (int)$maxBytes)) : self::JSON_MAX_BYTES;

		if (file_exists($file) || is_link($file)) {
			$this->loadValid = false;
			$implode = Filesystem::readFileBounded($file, $this->maxBytes, $firstLine ? 2 : 0);
			if (!is_string($implode)) {
				Log::set(__METHOD__.LOG_SEP.'Invalid or unreadable JSON database file: '.$file, LOG_TYPE_ERROR);
				return;
			}
			$this->loadedHash = hash('sha256', $implode);

			// Remove the PHP guard line without allocating an array for every line.
			if ($firstLine) {
				$newline = strpos($implode, "\n");
				$header = ($newline === false) ? '' : rtrim(substr($implode, 0, $newline), "\r");
				$validHeaders = array(
					"<?php defined('BRENDY') or die('Brendy CMS.'); ?>"
				);
				if (!in_array($header, $validHeaders, true)) {
					Log::set(__METHOD__.LOG_SEP.'Invalid database guard line: '.$file, LOG_TYPE_ERROR);
					return;
				}
				$implode = substr($implode, $newline + 1);
			}

			// Unserialize, JSON to Array
			$array = $this->decodeJson($implode);
			if ($array === false) {
				return;
			}
			if (empty($array)) {
				$this->db = array();
				$this->dbBackup = array();
			} else {
				$this->db = $array;
				$this->dbBackup = $array;
			}
			$this->loadValid = true;
		}
	}


	// Reload the current JSON database from disk and refresh the optimistic-write
	// hash. State is replaced only after the complete file validates successfully.
	public function reloadFromDisk()
	{
		$raw = Filesystem::readFileBounded($this->file, $this->maxBytes, $this->firstLine ? 2 : 0);
		if (!is_string($raw)) return false;
		$loadedHash = hash('sha256', $raw);
		$json = $raw;
		if ($this->firstLine) {
			$newline = strpos($json, "\n");
			$header = ($newline === false) ? '' : rtrim(substr($json, 0, $newline), "\r");
			if ($header !== "<?php defined('BRENDY') or die('Brendy CMS.'); ?>") return false;
			$json = substr($json, $newline + 1);
		}
		$decoded = $this->decodeJson($json);
		if ($decoded === false || !is_array($decoded)) return false;

		$this->db = $decoded;
		$this->dbBackup = $decoded;
		$this->loadedHash = $loadedHash;
		$this->loadValid = true;
		return true;
	}


	// Read-only integrity inspection used by diagnostics and maintenance tools.
	// It never mutates the database and never returns stored values, only metadata.
	public static function inspectFile($file, $firstLine=true, $maxBytes=134217728)
	{
		$result = array('ok'=>false, 'reason'=>'invalid', 'bytes'=>0, 'entries'=>0);
		if (!is_string($file) || $file === '' || strpos($file, "\0") !== false ||
			!is_file($file) || is_link($file) || !is_readable($file)) {
			$result['reason'] = 'unreadable';
			return $result;
		}
		$maxBytes = max(1, min(134217728, (int)$maxBytes));
		// Diagnostics must describe the exact bytes they parse. A separate pathname
		// filesize() followed by a descriptor read can race an atomic replacement and
		// report stale metadata for newer contents. Use one descriptor snapshot for
		// both the byte count and payload. A preflight lstat is used only to preserve
		// the useful "size" reason for an obviously oversized file; its value is
		// never returned as trusted metadata.
		$pathStat = @lstat($file);
		if (is_array($pathStat) && isset($pathStat['size']) && is_int($pathStat['size']) &&
			($pathStat['size'] < 0 || $pathStat['size'] > $maxBytes)) {
			$result['reason'] = 'size';
			return $result;
		}
		$snapshot = Filesystem::readFileSnapshotBounded($file, $maxBytes, 0);
		if (!is_array($snapshot) || !isset($snapshot['data'], $snapshot['size']) ||
			!is_string($snapshot['data']) || !is_int($snapshot['size'])) {
			$result['reason'] = 'read';
			return $result;
		}
		$result['bytes'] = $snapshot['size'];
		$raw = $snapshot['data'];
		if ($firstLine) {
			$newline = strpos($raw, "\n");
			$header = ($newline === false) ? '' : rtrim(substr($raw, 0, $newline), "\r");
			if ($header !== "<?php defined('BRENDY') or die('Brendy CMS.'); ?>") {
				$result['reason'] = 'guard';
				return $result;
			}
			$raw = substr($raw, $newline + 1);
		}
		try {
			$decoded = json_decode($raw, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$result['reason'] = 'json';
			return $result;
		}
		if (!is_array($decoded)) {
			$result['reason'] = 'root';
			return $result;
		}
		$result['ok'] = true;
		$result['reason'] = 'ok';
		$result['entries'] = count($decoded);
		return $result;
	}

	// Whether the database file was absent (new store) or loaded and validated
	// successfully. Callers that execute extension/runtime code can use this to
	// fail closed instead of treating a corrupt JSON file as an empty database.
	public function loadedSuccessfully()
	{
		return $this->loadValid === true;
	}

	public function restoreDB()
	{
		$this->db = $this->dbBackup;
		return true;
	}

	// Repair-on-load callers may have normalized values in memory and then lose
	// an optimistic write race. Never continue the request with state that was not
	// actually persisted: prefer the newest valid file and fall back to the last
	// successfully loaded/saved snapshot if the file cannot be re-read.
	public function recoverPersistedState()
	{
		if ($this->reloadFromDisk()) return true;
		$this->db = $this->dbBackup;
		return false;
	}

	// Returns the number of rows in the database
	public function count()
	{
		return count($this->db);
	}

	// Returns the value from the field
	public function getField($field)
	{
		if (isset($this->db[$field])) {
			return $this->db[$field];
		}
		if (isset($this->dbFields[$field])) {
			return $this->dbFields[$field];
		}
		return null;
	}

	// Save the JSON file
	public function save()
	{
		if (!$this->loadValid) {
			Log::set(__METHOD__.LOG_SEP.'Refusing to overwrite a database that did not load successfully: '.$this->file, LOG_TYPE_ERROR);
			return false;
		}
		$data = '';
		if ($this->firstLine) {
			$data  = "<?php defined('BRENDY') or die('Brendy CMS.'); ?>".PHP_EOL;
		}

		// Serialize database and fail without touching the current database if
		// invalid data cannot be represented as JSON.
		$serialized = $this->encodeJson($this->db);
		if (!is_string($serialized)) {
			Log::set(__METHOD__.LOG_SEP.'Error encoding the JSON database file: '.$this->file, LOG_TYPE_ERROR);
			return false;
		}
		$data .= $serialized;
		if (strlen($data) > $this->maxBytes) {
			Log::set(__METHOD__.LOG_SEP.'Refusing to write a database larger than its configured byte limit: '.$this->file, LOG_TYPE_ERROR);
			return false;
		}

		$directory = dirname($this->file);
		if (!is_dir($directory) || is_link($directory) || is_link($this->file) ||
			(file_exists($this->file) && !is_file($this->file))) {
			Log::set(__METHOD__.LOG_SEP.'Database directory does not exist: '.$directory, LOG_TYPE_ERROR);
			return false;
		}

		// Serialize compare-and-replace operations. The hash check detects a newer
		// write made since this object was loaded, preventing stale requests from
		// silently discarding another request's changes.
		$lockPath = $this->file . '.write.lock';
		$lock = Filesystem::acquireLock($lockPath, LOCK_EX, 0640);
		if ($lock === false) return false;

		try {
			if (!is_dir($directory) || is_link($directory) || is_link($this->file) ||
				(file_exists($this->file) && !is_file($this->file))) return false;
			$currentExists = file_exists($this->file);
			$currentHash = null;
			if ($currentExists) {
				$currentData = Filesystem::readFileBounded($this->file, $this->maxBytes, $this->firstLine ? 2 : 0);
				if (!is_string($currentData)) return false;
				$currentHash = hash('sha256', $currentData);
			}
			$conflict = ($this->loadedHash === null)
				? $currentExists
				: (!$currentExists || !is_string($currentHash) || !hash_equals($this->loadedHash, $currentHash));
			if ($conflict) {
				Log::set(__METHOD__.LOG_SEP.'Concurrent database update detected: '.$this->file, LOG_TYPE_WARN);
				return false;
			}

			// Reuse the core durable atomic-write primitive instead of maintaining a
			// second staging implementation here. The optimistic hash check above is
			// still protected by this database's exclusive write lock.
			$existingPermissions = $currentExists ? @fileperms($this->file) : false;
			$permissions = is_int($existingPermissions) ? ($existingPermissions & 0777) : 0640;
			if ($permissions === 0) $permissions = 0640;
			if (!Filesystem::atomicWrite($this->file, $data, $permissions)) {
				Log::set(__METHOD__.LOG_SEP.'Error occurred when atomically replacing the database file.', LOG_TYPE_ERROR);
				return false;
			}

			$this->loadedHash = hash('sha256', $data);
			$this->dbBackup = $this->db;
			return true;
		} finally {
			Filesystem::releaseLock($lock);
		}
	}

	// Returns a JSON encoded string on success or FALSE on failure
	private function encodeJson($data)
	{
		if (!is_array($data)) return false;
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
		// Guarded JSON is stored in a PHP file. Never serialize a literal PHP
		// opening tag after the guard; decoded values remain byte-identical.
		if ($this->firstLine) $flags |= JSON_HEX_TAG;
		if (DEBUG_MODE) $flags |= JSON_PRETTY_PRINT;
		try {
			return json_encode($data, $flags, self::JSON_MAX_DEPTH);
		} catch (JsonException $e) {
			Log::set(__METHOD__.LOG_SEP.'Invalid JSON data; database write rejected: '.$this->file, LOG_TYPE_ERROR);
			return false;
		}
	}

	// Returns the value encoded in json in appropriate PHP type
	private function decodeJson($data)
	{
		if (!is_string($data)) return false;
		try {
			$decode = json_decode($data, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			Log::set(__METHOD__.LOG_SEP.'Error trying to read the JSON file: '.$this->file, LOG_TYPE_ERROR);
			return false;
		}
		if (!is_array($decode)) {
			Log::set(__METHOD__.LOG_SEP.'JSON database root must be an array: '.$this->file, LOG_TYPE_ERROR);
			return false;
		}
		return $decode;
	}

	// Shared row check for keyed list/page databases. Keep public API wrappers intact.
	protected function hasArrayRow($key)
	{
		return is_string($key) && isset($this->db[$key]) && is_array($this->db[$key]);
	}

	public function getDB()
	{
		return $this->db;
	}

	// Truncate all the rows
	public function truncate()
	{
		$this->db = array();
		if (!$this->save()) {
			// A conflict means another request has committed a newer database.
			// Restore the actual persisted state rather than this object's stale copy.
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

}
