<?php defined('BRENDY') or die('Brendy CMS.');

class Syslog extends BrendyJsonDatabase {

	public $dbFields = array(
		'date'=>array('inFile'=>false, 'value'=>''),
		'dictionaryKey'=>array('inFile'=>false, 'value'=>''),
		'notes'=>array('inFile'=>false, 'value'=>''),
		'username'=>array('inFile'=>false, 'value'=>''),
		'idExecution'=>array('inFile'=>false, 'value'=>''),
		'method'=>array('inFile'=>false, 'value'=>''),
		'sequence'=>array('inFile'=>false, 'value'=>0),
		'prevHash'=>array('inFile'=>false, 'value'=>''),
		'chainHash'=>array('inFile'=>false, 'value'=>'')
	);

	function __construct()
	{
		parent::__construct(DB_SYSLOG);
		$this->migrateAuditChainIfNeeded();
	}

	private function auditKey()
	{
		if (!isset($GLOBALS['security']) || !is_object($GLOBALS['security']) ||
			!method_exists($GLOBALS['security'], 'auditLogHmacKey')) return false;
		$keyHex = $GLOBALS['security']->auditLogHmacKey();
		if (!is_string($keyHex) || preg_match('/^[a-f0-9]{64}$/D', $keyHex) !== 1) return false;
		$key = hex2bin($keyHex);
		return is_string($key) && strlen($key) === 32 ? $key : false;
	}

	private function canonicalRow($row, $withSequence = true)
	{
		if (!is_array($row)) return false;
		$canonical = array();
		foreach (array('date', 'dictionaryKey', 'notes', 'username', 'idExecution', 'method') as $field) {
			$value = isset($row[$field]) && is_scalar($row[$field]) ? (string)$row[$field] : '';
			if (strlen($value) > 10000 || preg_match('/\x00/', $value)) return false;
			$canonical[$field] = $value;
		}
		if ($withSequence) {
			$sequence = isset($row['sequence']) && is_int($row['sequence']) ? $row['sequence'] : 0;
			if ($sequence < 1 || $sequence > PHP_INT_MAX - 1000) return false;
			$canonical['sequence'] = $sequence;
		}
		try {
			return json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			return false;
		}
	}

	private function calculateChainHash($row, $prevHash)
	{
		$key = $this->auditKey();
		$canonical = $this->canonicalRow($row, true);
		if (!is_string($key) || !is_string($canonical) || !is_string($prevHash) ||
			($prevHash !== '' && preg_match('/^[a-f0-9]{64}$/D', $prevHash) !== 1)) return false;
		return hash_hmac('sha256', 'Brendy-Audit-v2' . "\n" . $prevHash . "\n" . $canonical, $key);
	}

	private function calculateLegacyChainHash($row, $prevHash)
	{
		$key = $this->auditKey();
		$canonical = $this->canonicalRow($row, false);
		if (!is_string($key) || !is_string($canonical) || !is_string($prevHash) ||
			($prevHash !== '' && preg_match('/^[a-f0-9]{64}$/D', $prevHash) !== 1)) return false;
		return hash_hmac('sha256', 'Brendy-Audit-v1' . "\n" . $prevHash . "\n" . $canonical, $key);
	}

	private function verifyLegacyRows($rows)
	{
		if (!is_array($rows) || empty($rows) || !is_string($this->auditKey())) return false;
		$oldest = $rows[count($rows) - 1];
		if (!is_array($oldest)) return false;
		$prev = isset($oldest['prevHash']) && is_string($oldest['prevHash']) ? $oldest['prevHash'] : '';
		if ($prev !== '' && preg_match('/^[a-f0-9]{64}$/D', $prev) !== 1) return false;
		for ($i = count($rows) - 1; $i >= 0; $i--) {
			$row = $rows[$i];
			if (!is_array($row) || !isset($row['prevHash'], $row['chainHash']) ||
				!is_string($row['prevHash']) || !is_string($row['chainHash']) ||
				preg_match('/^[a-f0-9]{64}$/D', $row['chainHash']) !== 1 || !hash_equals($prev, $row['prevHash'])) return false;
			$expected = $this->calculateLegacyChainHash($row, $prev);
			if (!is_string($expected) || !hash_equals($expected, $row['chainHash'])) return false;
			$prev = $row['chainHash'];
		}
		return true;
	}

	private function buildVersionTwoChain($rows)
	{
		if (!is_array($rows)) return false;
		$prev = '';
		$sequence = 1;
		for ($i = count($rows) - 1; $i >= 0; $i--, $sequence++) {
			if (!is_array($rows[$i])) return false;
			$rows[$i]['sequence'] = $sequence;
			$rows[$i]['prevHash'] = $prev;
			$hash = $this->calculateChainHash($rows[$i], $prev);
			if (!is_string($hash)) return false;
			$rows[$i]['chainHash'] = $hash;
			$prev = $hash;
		}
		return array_values($rows);
	}

	private function setAnchorForRows($rows)
	{
		if (!isset($GLOBALS['security']) || !is_object($GLOBALS['security']) ||
			!method_exists($GLOBALS['security'], 'updateAuditLogAnchor')) return false;
		$rows = is_array($rows) ? array_values($rows) : array();
		if (empty($rows)) return $GLOBALS['security']->updateAuditLogAnchor('', 0, 0, '', 0);
		$head = $rows[0];
		$tail = $rows[count($rows) - 1];
		if (!is_array($head) || !is_array($tail) || !isset($head['sequence'], $head['chainHash'], $tail['sequence'], $tail['prevHash']) ||
			!is_int($head['sequence']) || !is_string($head['chainHash']) || !is_int($tail['sequence']) || !is_string($tail['prevHash'])) return false;
		return $GLOBALS['security']->updateAuditLogAnchor($head['chainHash'], $head['sequence'],
			$tail['sequence'], $tail['prevHash'], count($rows));
	}

	private function migrateAuditChainIfNeeded()
	{
		$lockPath = DB_SYSLOG . '.lock';
		$lock = Filesystem::acquireLock($lockPath, LOCK_EX, 0600);
		if ($lock === false) return false;
		try {
			// Another request may have completed the migration while this request
			// waited. Re-read the authenticated database before deciding anything.
			if (!$this->reloadFromDisk()) return false;
			return $this->migrateAuditChainIfNeededUnlocked();
		} finally {
			Filesystem::releaseLock($lock);
		}
	}

	private function migrateAuditChainIfNeededUnlocked()
	{
		$rows = array_values(array_filter($this->db, static function ($row) { return is_array($row); }));
		if (count($rows) !== count($this->db) || !is_string($this->auditKey())) return false;

		if (empty($rows)) {
			$anchor = isset($GLOBALS['security']) && is_object($GLOBALS['security']) && method_exists($GLOBALS['security'], 'auditLogAnchor')
				? $GLOBALS['security']->auditLogAnchor() : false;
			if ($anchor === false) $this->setAnchorForRows(array());
			return true;
		}

		$protected = 0;
		$sequenced = 0;
		foreach ($rows as $row) {
			if (isset($row['chainHash']) && is_string($row['chainHash']) && preg_match('/^[a-f0-9]{64}$/D', $row['chainHash']) === 1) $protected++;
			if (isset($row['sequence']) && is_int($row['sequence']) && $row['sequence'] > 0) $sequenced++;
		}

		$migrated = false;
		if ($protected === 0) {
			$migrated = $this->buildVersionTwoChain($rows);
		} elseif ($protected === count($rows) && $sequenced === 0 && $this->verifyLegacyRows($rows)) {
			$migrated = $this->buildVersionTwoChain($rows);
		}
		if (is_array($migrated)) {
			$this->db = $migrated;
			if (!$this->save()) {
				Log::set(__METHOD__ . LOG_SEP . 'Unable to migrate the activity log to HMAC chain v2.', LOG_TYPE_ERROR);
				return false;
			}
			if (!$this->setAnchorForRows($migrated)) {
				Log::set(__METHOD__ . LOG_SEP . 'Audit v2 rows were migrated but the head anchor could not be persisted.', LOG_TYPE_ERROR);
				return false;
			}
			return true;
		}

		// Existing v2 rows may come from a 2.0 install whose anchor was not yet
		// initialized (for example immediately after an atomic upgrade). Initialize
		// only when the complete v2 chain validates without requiring an anchor.
		if ($protected === count($rows) && $sequenced === count($rows)) {
			$structure = $this->verifyRows($rows, false);
			$anchor = isset($GLOBALS['security']) && is_object($GLOBALS['security']) && method_exists($GLOBALS['security'], 'auditLogAnchor')
				? $GLOBALS['security']->auditLogAnchor() : false;
			if (!empty($structure['ok']) && $anchor === false) return $this->setAnchorForRows($rows);
		}
		return false;
	}

	private function verifyRows($rows, $requireAnchor = true)
	{
		$rows = is_array($rows) ? array_values($rows) : array();
		$result = array('ok'=>true, 'entries'=>count($rows), 'protected'=>0, 'reason'=>'ok', 'headSequence'=>0);
		if (!is_string($this->auditKey())) return array('ok'=>false, 'entries'=>count($rows), 'protected'=>0, 'reason'=>'key', 'headSequence'=>0);
		if (empty($rows)) {
			if ($requireAnchor && isset($GLOBALS['security']) && is_object($GLOBALS['security']) && method_exists($GLOBALS['security'], 'auditLogAnchor')) {
				$anchor = $GLOBALS['security']->auditLogAnchor();
				if (!is_array($anchor) || $anchor['sequence'] !== 0 || $anchor['hash'] !== '' ||
					$anchor['tailSequence'] !== 0 || $anchor['tailPrevHash'] !== '' || $anchor['retainedCount'] !== 0) {
					$result['ok'] = false; $result['reason'] = 'anchor';
				}
			}
			return $result;
		}

		$oldest = $rows[count($rows) - 1];
		if (!is_array($oldest)) return array('ok'=>false, 'entries'=>count($rows), 'protected'=>0, 'reason'=>'row', 'headSequence'=>0);
		$prev = isset($oldest['prevHash']) && is_string($oldest['prevHash']) ? $oldest['prevHash'] : '';
		if ($prev !== '' && preg_match('/^[a-f0-9]{64}$/D', $prev) !== 1) {
			$result['ok'] = false; $result['reason'] = 'anchor-link'; return $result;
		}
		$expectedSequence = isset($oldest['sequence']) && is_int($oldest['sequence']) ? $oldest['sequence'] : 0;
		if ($expectedSequence < 1) { $result['ok'] = false; $result['reason'] = 'sequence'; return $result; }

		for ($i = count($rows) - 1; $i >= 0; $i--) {
			$row = $rows[$i];
			if (!is_array($row) || !isset($row['prevHash'], $row['chainHash'], $row['sequence']) ||
				!is_string($row['prevHash']) || !is_string($row['chainHash']) || !is_int($row['sequence']) ||
				$row['sequence'] !== $expectedSequence || preg_match('/^[a-f0-9]{64}$/D', $row['chainHash']) !== 1 ||
				!hash_equals($prev, $row['prevHash'])) {
				$result['ok'] = false; $result['reason'] = 'structure'; return $result;
			}
			$expected = $this->calculateChainHash($row, $prev);
			if (!is_string($expected) || !hash_equals($expected, $row['chainHash'])) {
				$result['ok'] = false; $result['reason'] = 'hash'; return $result;
			}
			$result['protected']++;
			$prev = $row['chainHash'];
			$expectedSequence++;
		}

		$head = $rows[0];
		$result['headSequence'] = $head['sequence'];
		if ($requireAnchor) {
			$anchor = isset($GLOBALS['security']) && is_object($GLOBALS['security']) && method_exists($GLOBALS['security'], 'auditLogAnchor')
				? $GLOBALS['security']->auditLogAnchor() : false;
			if (!is_array($anchor) || $anchor['sequence'] !== $head['sequence'] || !hash_equals($anchor['hash'], $head['chainHash'])) {
				$result['ok'] = false; $result['reason'] = 'head-anchor'; return $result;
			}
			if ($anchor['tailSequence'] !== $oldest['sequence'] || !hash_equals($anchor['tailPrevHash'], $oldest['prevHash']) ||
				$anchor['retainedCount'] !== count($rows)) {
				$result['ok'] = false; $result['reason'] = 'tail-anchor'; return $result;
			}
		}
		return $result;
	}

	public function verifyChain()
	{
		return $this->verifyRows(array_values($this->db), true);
	}

	public function exists($idExecution)
	{
		if (!is_string($idExecution) || preg_match('/^[a-f0-9]{32}$/iD', $idExecution) !== 1) return false;
		foreach ($this->db as $field) {
			if (is_array($field) && isset($field['idExecution']) && is_string($field['idExecution']) && hash_equals($field['idExecution'], $idExecution)) return true;
		}
		return false;
	}

	public function get($idExecution)
	{
		if (!is_string($idExecution) || preg_match('/^[a-f0-9]{32}$/iD', $idExecution) !== 1) return false;
		foreach ($this->db as $field) {
			if (is_array($field) && isset($field['idExecution']) && is_string($field['idExecution']) && hash_equals($field['idExecution'], $idExecution)) return $field;
		}
		return false;
	}

	public function add($args)
	{
		if (!is_array($args) || !isset($args['dictionaryKey'], $args['notes']) || !is_scalar($args['dictionaryKey']) || !is_scalar($args['notes'])) return false;
		$data = array();
		$data['date'] = Date::current(DB_DATE_FORMAT);
		$data['dictionaryKey'] = mb_substr(Sanitize::removeTags((string)$args['dictionaryKey']), 0, 500, CHARSET);
		$data['notes'] = mb_substr(Sanitize::removeTags((string)$args['notes']), 0, 5000, CHARSET);
		$data['idExecution'] = isset($GLOBALS['ID_EXECUTION']) && is_string($GLOBALS['ID_EXECUTION']) ? $GLOBALS['ID_EXECUTION'] : '';
		$data['method'] = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? mb_substr($_SERVER['REQUEST_METHOD'], 0, 20, CHARSET) : '';
		$data['username'] = isset($args['username']) && is_string($args['username']) && $args['username'] !== '' ? $args['username'] : Session::get('username');
		if (!is_string($data['username']) || Text::isEmpty($data['username'])) return false;

		$lockPath = DB_SYSLOG . '.lock';
		$lock = Filesystem::acquireLock($lockPath, LOCK_EX, 0600);
		if ($lock === false) return false;
		try {
			$fresh = new BrendyJsonDatabase(DB_SYSLOG);
			$current = is_array($fresh->db) ? array_values(array_filter($fresh->db, static function ($row) { return is_array($row); })) : array();
			if (count($current) !== count($fresh->db)) return false;
			$integrity = $this->verifyRows($current, true);
			if (empty($integrity['ok'])) {
				Log::set(__METHOD__ . LOG_SEP . 'Refusing to append to an invalid or truncated audit chain.', LOG_TYPE_ERROR);
				return false;
			}
			$anchor = isset($GLOBALS['security']) && is_object($GLOBALS['security']) && method_exists($GLOBALS['security'], 'auditLogAnchor')
				? $GLOBALS['security']->auditLogAnchor() : false;
			if (!is_array($anchor)) return false;
			$nextSequence = $anchor['sequence'] + 1;
			if ($nextSequence < 1 || $nextSequence > PHP_INT_MAX - 1000) return false;
			$prevHash = empty($current) ? '' : $current[0]['chainHash'];
			$beforeCurrent = $current;
			$data['sequence'] = $nextSequence;
			$data['prevHash'] = $prevHash;
			$data['chainHash'] = $this->calculateChainHash($data, $prevHash);
			if (!is_string($data['chainHash'])) return false;
			array_unshift($current, $data);
			$current = array_slice($current, 0, SYSLOG_RETENTION_AMOUNT);
			$fresh->db = $current;
			if (!$fresh->save()) return false;

			if (!$this->setAnchorForRows($current)) {
				// Keep the audit file and external head synchronized. If the anchor write
				// fails, roll the syslog file back while its lock is still held.
				$fresh->db = $beforeCurrent;
				if (!$fresh->save()) {
					Log::set(__METHOD__ . LOG_SEP . 'Audit anchor update failed and syslog rollback also failed.', LOG_TYPE_ERROR);
				}
				return false;
			}
			if (!$this->reloadFromDisk()) {
				Log::set(__METHOD__ . LOG_SEP . 'Audit log was written but could not be reloaded safely.', LOG_TYPE_ERROR);
				return false;
			}
			return true;
		} finally {
			Filesystem::releaseLock($lock);
		}
	}
}
