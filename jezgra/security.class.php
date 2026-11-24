<?php defined('BRENDY') or die('Brendy CMS.');

class Security extends BrendyJsonDatabase
{
	public $dbFields = array(
		'minutesBlocked'=>5,
		'numberFailuresAllowed'=>10,
		'blackList'=>array(),
		'accountFailures'=>array()
	);

	function __construct()
	{
		parent::__construct(DB_SECURITY);
		$changed = false;
		$minutes = isset($this->db['minutesBlocked']) && is_int($this->db['minutesBlocked'])
			? $this->db['minutesBlocked'] : $this->dbFields['minutesBlocked'];
		$failures = isset($this->db['numberFailuresAllowed']) && is_int($this->db['numberFailuresAllowed'])
			? $this->db['numberFailuresAllowed'] : $this->dbFields['numberFailuresAllowed'];
		if ($minutes < 1 || $minutes > 1440) $minutes = $this->dbFields['minutesBlocked'];
		if ($failures < 1 || $failures > 1000) $failures = $this->dbFields['numberFailuresAllowed'];
		if (!isset($this->db['minutesBlocked']) || $this->db['minutesBlocked'] !== $minutes) $changed = true;
		if (!isset($this->db['numberFailuresAllowed']) || $this->db['numberFailuresAllowed'] !== $failures) $changed = true;
		$this->db['minutesBlocked'] = $minutes;
		$this->db['numberFailuresAllowed'] = $failures;

		// Throttle identifiers are keyed with a per-install HMAC secret rather than
		// plain SHA-256. IPv4 and common account identifiers have small enough
		// search spaces that an unsalted digest is not strong pseudonymization.
		// Existing <=1.8 throttle buckets cannot be re-keyed without the original
		// values, so the one-time upgrade creates a key and discards only those
		// short-lived counters. A present-but-malformed key is never auto-rotated:
		// loginProtectionAvailable() then fails closed instead of resetting limits.
		$identityKeyPresent = array_key_exists('identityHmacKey', $this->db);
		$identityKeyValid = $identityKeyPresent && is_string($this->db['identityHmacKey']) &&
			preg_match('/^[a-f0-9]{64}$/iD', $this->db['identityHmacKey']) === 1;
		if (!$identityKeyPresent) {
			$this->db['identityHmacKey'] = bin2hex(random_bytes(32));
			$this->db['blackList'] = array();
			$this->db['accountFailures'] = array();
			$identityKeyValid = true;
			$changed = true;
		} elseif (!$identityKeyValid) {
			Log::set(__METHOD__ . LOG_SEP . 'Identity HMAC key is invalid; login throttling will fail closed.', LOG_TYPE_ERROR);
		}

		$blackList = isset($this->db['blackList']) && is_array($this->db['blackList']) ? $this->db['blackList'] : array();
		$normalizedBlackList = $this->normalizeIpFailures($blackList, 10000);
		if ($normalizedBlackList !== $blackList) $changed = true;
		$this->db['blackList'] = $normalizedBlackList;

		// Account throttling deliberately stores only SHA-256 identifiers, never the
		// submitted username/e-mail. It complements the IP bucket and makes a
		// distributed credential-stuffing attempt substantially more expensive.
		$accountFailures = isset($this->db['accountFailures']) && is_array($this->db['accountFailures'])
			? $this->db['accountFailures'] : array();
		$normalizedAccounts = $this->normalizeAccountFailures($accountFailures, LOGIN_RATE_LIMIT_RETENTION);
		if ($normalizedAccounts !== $accountFailures) $changed = true;
		$this->db['accountFailures'] = $normalizedAccounts;

		// Older installations do not have a secret dedicated to preview links.
		if (!isset($this->db['previewTokenSecret']) || !is_string($this->db['previewTokenSecret']) ||
			preg_match('/^[a-f0-9]{64}$/iD', $this->db['previewTokenSecret']) !== 1) {
			$this->db['previewTokenSecret'] = bin2hex(random_bytes(32));
			$changed = true;
		}

		// Two-factor seeds are encrypted before they are stored in users.php. Never
		// silently replace malformed key material: doing so would destroy the chance
		// to repair it from a backup and make existing TOTP seeds undecryptable. A
		// missing key is generated only when no account is already protected by 2FA,
		// which is the normal <=1.6 upgrade path.
		$twoFactorKeyPresent = array_key_exists('twoFactorEncryptionKey', $this->db);
		$twoFactorKeyValid = $twoFactorKeyPresent && is_string($this->db['twoFactorEncryptionKey']) &&
			preg_match('/^[a-f0-9]{64}$/iD', $this->db['twoFactorEncryptionKey']) === 1;
		$protectedAccountExists = false;
		if (isset($GLOBALS['users']) && is_object($GLOBALS['users'])) {
			foreach ($GLOBALS['users']->db as $securityUserRow) {
				if (is_array($securityUserRow) && isset($securityUserRow['twoFactorEnabled']) && $securityUserRow['twoFactorEnabled'] === true) {
					$protectedAccountExists = true;
					break;
				}
			}
		}
		if (!$twoFactorKeyPresent && !$protectedAccountExists) {
			$this->db['twoFactorEncryptionKey'] = bin2hex(random_bytes(32));
			$changed = true;
		} elseif (!$twoFactorKeyValid) {
			Log::set(__METHOD__ . LOG_SEP . 'Two-factor encryption key is missing or invalid; refusing destructive key rotation.', LOG_TYPE_ERROR);
		}

		// The audit HMAC key follows the same non-destructive rule. Legacy unchained
		// logs can safely receive a new key; an already chained log with a missing or
		// malformed key must remain visibly broken instead of being silently re-keyed.
		$auditKeyPresent = array_key_exists('auditLogHmacKey', $this->db);
		$auditKeyValid = $auditKeyPresent && is_string($this->db['auditLogHmacKey']) &&
			preg_match('/^[a-f0-9]{64}$/iD', $this->db['auditLogHmacKey']) === 1;
		$protectedAuditExists = false;
		if (defined('DB_SYSLOG') && is_file(DB_SYSLOG) && !is_link(DB_SYSLOG)) {
			$auditDb = new BrendyJsonDatabase(DB_SYSLOG);
			foreach ($auditDb->db as $securityAuditRow) {
				if (is_array($securityAuditRow) && isset($securityAuditRow['chainHash']) && is_string($securityAuditRow['chainHash']) &&
					preg_match('/^[a-f0-9]{64}$/D', $securityAuditRow['chainHash']) === 1) {
					$protectedAuditExists = true;
					break;
				}
			}
		}
		if (!$auditKeyPresent && !$protectedAuditExists) {
			$this->db['auditLogHmacKey'] = bin2hex(random_bytes(32));
			$changed = true;
		} elseif (!$auditKeyValid) {
			Log::set(__METHOD__ . LOG_SEP . 'Audit HMAC key is missing or invalid; refusing destructive key rotation.', LOG_TYPE_ERROR);
		}

		// Runtime extension scan results are disposable performance data, but the
		// persistent cache is HMAC authenticated so a writable tmp/cache file cannot
		// be forged into an allow decision. Invalid/missing cache keys can be rotated
		// safely because doing so only invalidates the cache, never user data.
		if (!isset($this->db['extensionSecurityCacheKey']) || !is_string($this->db['extensionSecurityCacheKey']) ||
			preg_match('/^[a-f0-9]{64}$/iD', $this->db['extensionSecurityCacheKey']) !== 1) {
			$this->db['extensionSecurityCacheKey'] = bin2hex(random_bytes(32));
			$changed = true;
		}

		// Core-integrity signatures protect a local baseline of Brendy's own
		// runtime code. Do not silently rotate a malformed/missing key when an
		// authenticated baseline already exists: that would destroy evidence of
		// tampering. A new key is safe only before the first baseline is created.
		$coreIntegrityKeyPresent = array_key_exists('coreIntegrityHmacKey', $this->db);
		$coreIntegrityKeyValid = $coreIntegrityKeyPresent && is_string($this->db['coreIntegrityHmacKey']) &&
			preg_match('/^[a-f0-9]{64}$/iD', $this->db['coreIntegrityHmacKey']) === 1;
		$coreIntegrityBaselineExists = defined('DB_CORE_INTEGRITY') && (is_file(DB_CORE_INTEGRITY) || is_link(DB_CORE_INTEGRITY));
		if (!$coreIntegrityKeyPresent && !$coreIntegrityBaselineExists) {
			$this->db['coreIntegrityHmacKey'] = bin2hex(random_bytes(32));
			$changed = true;
		} elseif (!$coreIntegrityKeyValid) {
			Log::set(__METHOD__ . LOG_SEP . 'Core integrity HMAC key is missing or invalid; refusing destructive key rotation.', LOG_TYPE_ERROR);
		}
		if ($changed && !$this->save()) {
			// Randomly generated security keys are useful only after they are durable.
			// A failed repair write must not leave this request signing tokens with
			// key material that no later request can reproduce.
			$this->recoverPersistedState();
			Log::set(__METHOD__ . LOG_SEP . 'Unable to persist repaired security settings; persisted state reloaded.', LOG_TYPE_ERROR);
		}
	}

	private function normalizeIpFailures($list, $limit)
	{
		if (!is_array($list)) return array();
		$normalized = array();
		foreach (array_slice($list, 0, max(1, (int)$limit), true) as $storedKey => $entry) {
			if (!is_string($storedKey) || !is_array($entry) ||
				!isset($entry['lastFailure'], $entry['numberFailures']) || !is_numeric($entry['lastFailure']) ||
				!is_numeric($entry['numberFailures'])) continue;
			$key = preg_match('/^[a-f0-9]{64}$/D', strtolower($storedKey)) === 1
				? strtolower($storedKey) : $this->ipFailureKey($storedKey);
			if ($key === false) continue;
			$lastFailure = (int)$entry['lastFailure'];
			$numberFailures = (int)$entry['numberFailures'];
			if ($lastFailure < 0 || $numberFailures < 1 || $numberFailures > 1000000) continue;
			if (isset($normalized[$key])) {
				$lastFailure = max($lastFailure, (int)$normalized[$key]['lastFailure']);
				$numberFailures = max($numberFailures, (int)$normalized[$key]['numberFailures']);
			}
			$normalized[$key] = array('lastFailure' => $lastFailure, 'numberFailures' => $numberFailures);
		}
		return $normalized;
	}

	public function identityHmacKey()
	{
		$key = isset($this->db['identityHmacKey']) && is_string($this->db['identityHmacKey'])
			? strtolower($this->db['identityHmacKey']) : '';
		return preg_match('/^[a-f0-9]{64}$/D', $key) === 1 ? $key : false;
	}

	public function pseudonymizeSecurityIdentifier($value, $context)
	{
		if (!is_string($value) || !is_string($context) || $value === '' || strlen($value) > 1024 ||
			!in_array($context, array('login-ip', 'login-account', 'api-read', 'api-write'), true) ||
			preg_match('/[\x00-\x1F\x7F]/', $value)) return false;
		$keyHex = $this->identityHmacKey();
		$key = is_string($keyHex) ? hex2bin($keyHex) : false;
		if (!is_string($key) || strlen($key) !== 32) return false;
		return hash_hmac('sha256', "Brendy-Identity-v1\n" . $context . "\n" . $value, $key);
	}

	private function ipFailureKey($ip)
	{
		if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) return false;
		return $this->pseudonymizeSecurityIdentifier(strtolower($ip), 'login-ip');
	}

	private function normalizeAccountFailures($list, $limit)
	{
		if (!is_array($list)) return array();
		$normalized = array();
		foreach (array_slice($list, 0, max(1, (int)$limit), true) as $key => $entry) {
			if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || !is_array($entry) ||
				!isset($entry['lastFailure'], $entry['numberFailures']) || !is_numeric($entry['lastFailure']) ||
				!is_numeric($entry['numberFailures'])) continue;
			$lastFailure = (int)$entry['lastFailure'];
			$numberFailures = (int)$entry['numberFailures'];
			if ($lastFailure < 0 || $numberFailures < 1 || $numberFailures > 1000000) continue;
			$normalized[$key] = array('lastFailure' => $lastFailure, 'numberFailures' => $numberFailures);
		}
		return $normalized;
	}

	private function accountFailureKey($identifier)
	{
		if (!is_string($identifier)) return false;
		$identifier = trim($identifier);
		if ($identifier === '' || strlen($identifier) > 254 || preg_match('/[\x00-\x1F\x7F]/', $identifier)) return false;
		if (function_exists('mb_strtolower')) $identifier = mb_strtolower($identifier, CHARSET);
		else $identifier = strtolower($identifier);
		return $this->pseudonymizeSecurityIdentifier($identifier, 'login-account');
	}

	public function coreIntegrityHmacKey()
	{
		$key = isset($this->db['coreIntegrityHmacKey']) && is_string($this->db['coreIntegrityHmacKey'])
			? strtolower($this->db['coreIntegrityHmacKey']) : '';
		return preg_match('/^[a-f0-9]{64}$/D', $key) === 1 ? $key : false;
	}

	private function openSecurityLock()
	{
		$lockPath = DB_SECURITY . '.lock';
		return Filesystem::acquireLock($lockPath, LOCK_EX, 0600);
	}

	// ====================================================
	// TOKEN FOR CSRF
	// ====================================================

	public function generateTokenCSRF()
	{
		$token = bin2hex(random_bytes(32));
		if (!Session::set('tokenCSRF', $token)) return false;
		Log::set(__METHOD__.LOG_SEP.'New CSRF token generated.');
		return $token;
	}

	public function validateTokenCSRF($token)
	{
		$sessionToken = $this->getTokenCSRF();
		return is_string($sessionToken) && is_string($token) &&
			$sessionToken !== '' && hash_equals($sessionToken, $token);
	}

	public function getTokenCSRF()
	{
		return Session::get('tokenCSRF');
	}

	private function previewTokenSecret()
	{
		$key = isset($this->db['previewTokenSecret']) && is_string($this->db['previewTokenSecret'])
			? strtolower($this->db['previewTokenSecret']) : '';
		return preg_match('/^[a-f0-9]{64}$/D', $key) === 1 ? $key : false;
	}

	public function getPreviewToken($uuid)
	{
		if (!is_string($uuid) || preg_match('/^(?:autosave-)?[a-f0-9]{32}$/iD', $uuid) !== 1) return false;
		$secret = $this->previewTokenSecret();
		if ($secret === false) return false;
		$expires = time() + PREVIEW_TOKEN_TTL;
		$signature = hash_hmac('sha256', $uuid . '|' . $expires, $secret);
		return $expires . '.' . $signature;
	}

	public function validatePreviewToken($uuid, $token)
	{
		if (!is_string($uuid) || preg_match('/^(?:autosave-)?[a-f0-9]{32}$/iD', $uuid) !== 1 ||
			!is_string($token) || preg_match('/^([0-9]{10})\.([a-f0-9]{64})$/D', $token, $matches) !== 1) {
			return false;
		}
		$secret = $this->previewTokenSecret();
		if ($secret === false) return false;
		$expires = (int)$matches[1];
		$now = time();
		if ($expires < $now || $expires > ($now + PREVIEW_TOKEN_TTL + 60)) return false;
		$expected = hash_hmac('sha256', $uuid . '|' . $expires, $secret);
		return hash_equals($expected, $matches[2]);
	}


	// ====================================================
	// TWO-FACTOR CREDENTIAL PROTECTION
	// ====================================================

	public function extensionSecurityCacheKey()
	{
		$key = isset($this->db['extensionSecurityCacheKey']) && is_string($this->db['extensionSecurityCacheKey'])
			? strtolower($this->db['extensionSecurityCacheKey']) : '';
		return preg_match('/^[a-f0-9]{64}$/D', $key) === 1 ? $key : false;
	}

	public function auditLogHmacKey()
	{
		$key = isset($this->db['auditLogHmacKey']) && is_string($this->db['auditLogHmacKey']) ? strtolower($this->db['auditLogHmacKey']) : '';
		return preg_match('/^[a-f0-9]{64}$/D', $key) === 1 ? $key : false;
	}

	public function auditLogAnchor()
	{
		$hash = isset($this->db['auditLogHeadHash']) && is_string($this->db['auditLogHeadHash'])
			? strtolower($this->db['auditLogHeadHash']) : '';
		$sequence = isset($this->db['auditLogHeadSequence']) && is_int($this->db['auditLogHeadSequence'])
			? $this->db['auditLogHeadSequence'] : 0;
		$tailSequence = isset($this->db['auditLogTailSequence']) && is_int($this->db['auditLogTailSequence'])
			? $this->db['auditLogTailSequence'] : 0;
		$tailPrevHash = isset($this->db['auditLogTailPrevHash']) && is_string($this->db['auditLogTailPrevHash'])
			? strtolower($this->db['auditLogTailPrevHash']) : '';
		$retainedCount = isset($this->db['auditLogRetainedCount']) && is_int($this->db['auditLogRetainedCount'])
			? $this->db['auditLogRetainedCount'] : -1;
		$version = isset($this->db['auditLogAnchorVersion']) && is_int($this->db['auditLogAnchorVersion'])
			? $this->db['auditLogAnchorVersion'] : 0;
		if ($version !== 3 || $sequence < 0 || $tailSequence < 0 || $retainedCount < 0 ||
			($sequence > 0 && preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) ||
			($sequence === 0 && ($hash !== '' || $tailSequence !== 0 || $tailPrevHash !== '' || $retainedCount !== 0)) ||
			($sequence > 0 && ($tailSequence < 1 || $tailSequence > $sequence || $retainedCount < 1 ||
				$retainedCount > SYSLOG_RETENTION_AMOUNT || ($sequence - $tailSequence + 1) !== $retainedCount)) ||
			($tailPrevHash !== '' && preg_match('/^[a-f0-9]{64}$/D', $tailPrevHash) !== 1)) return false;
		return array('hash'=>$hash, 'sequence'=>$sequence, 'tailSequence'=>$tailSequence,
			'tailPrevHash'=>$tailPrevHash, 'retainedCount'=>$retainedCount, 'version'=>$version);
	}

	// Store both boundaries of the retained audit window outside syslog.php.
	// Head anchoring detects removal of newest rows; tail sequence/prevHash and
	// retained count detect removal at the oldest boundary. Interior removal is
	// already detected by the HMAC prevHash chain. The update merges under the
	// security lock so concurrent throttle/crypto state is preserved.
	public function updateAuditLogAnchor($hash, $sequence, $tailSequence = 0, $tailPrevHash = '', $retainedCount = 0)
	{
		if (!is_int($sequence) || !is_int($tailSequence) || !is_int($retainedCount) ||
			$sequence < 0 || $sequence > PHP_INT_MAX - 1000 || $tailSequence < 0 || $retainedCount < 0 ||
			($sequence === 0 && ($hash !== '' || $tailSequence !== 0 || $tailPrevHash !== '' || $retainedCount !== 0)) ||
			($sequence > 0 && (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', strtolower($hash)) !== 1 ||
				$tailSequence < 1 || $tailSequence > $sequence || $retainedCount < 1 ||
				$retainedCount > SYSLOG_RETENTION_AMOUNT || ($sequence - $tailSequence + 1) !== $retainedCount ||
				!is_string($tailPrevHash) || ($tailPrevHash !== '' && preg_match('/^[a-f0-9]{64}$/D', strtolower($tailPrevHash)) !== 1)))) return false;
		$hash = $sequence === 0 ? '' : strtolower($hash);
		$tailPrevHash = $sequence === 0 ? '' : strtolower($tailPrevHash);
		$lock = $this->openSecurityLock();
		if ($lock === false) return false;
		try {
			$fresh = new BrendyJsonDatabase(DB_SECURITY);
			$db = is_array($fresh->db) ? $fresh->db : array();
			$db['auditLogHeadHash'] = $hash;
			$db['auditLogHeadSequence'] = $sequence;
			$db['auditLogTailSequence'] = $tailSequence;
			$db['auditLogTailPrevHash'] = $tailPrevHash;
			$db['auditLogRetainedCount'] = $retainedCount;
			$db['auditLogAnchorVersion'] = 3;
			$fresh->db = $db;
			$ok = $fresh->save();
			if ($ok) {
				$this->db = $db;
				$this->reloadFromDisk();
			}
			return $ok;
		} finally {
			Filesystem::releaseLock($lock);
		}
	}

	public function twoFactorCryptoAvailable()
	{
		return function_exists('sodium_crypto_secretbox') ||
			(function_exists('openssl_encrypt') && function_exists('openssl_decrypt'));
	}

	private function base64UrlEncode($binary)
	{
		return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
	}

	private function base64UrlDecode($value)
	{
		if (!is_string($value) || $value === '' || strlen($value) > 8192 || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) return false;
		$padding = strlen($value) % 4;
		if ($padding) $value .= str_repeat('=', 4 - $padding);
		$decoded = base64_decode(strtr($value, '-_', '+/'), true);
		return is_string($decoded) ? $decoded : false;
	}

	public function encryptTwoFactorSecret($secret)
	{
		if (!is_string($secret) || strlen($secret) < 16 || strlen($secret) > 256 ||
			preg_match('/^[A-Z2-7]+$/D', $secret) !== 1) return false;
		$keyHex = isset($this->db['twoFactorEncryptionKey']) && is_string($this->db['twoFactorEncryptionKey'])
			? $this->db['twoFactorEncryptionKey'] : '';
		$key = preg_match('/^[a-f0-9]{64}$/iD', $keyHex) === 1 ? hex2bin($keyHex) : false;
		if (!is_string($key) || strlen($key) !== 32) return false;

		if (function_exists('sodium_crypto_secretbox')) {
			$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$cipher = sodium_crypto_secretbox($secret, $nonce, $key);
			return 'sb1.' . $this->base64UrlEncode($nonce . $cipher);
		}
		if (function_exists('openssl_encrypt')) {
			$iv = random_bytes(12);
			$tag = '';
			$cipher = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'Brendy-2FA-v1', 16);
			if (!is_string($cipher) || !is_string($tag) || strlen($tag) !== 16) return false;
			return 'og1.' . $this->base64UrlEncode($iv . $tag . $cipher);
		}
		return false;
	}

	public function decryptTwoFactorSecret($encrypted)
	{
		if (!is_string($encrypted) || strlen($encrypted) > 8192) return false;
		$keyHex = isset($this->db['twoFactorEncryptionKey']) && is_string($this->db['twoFactorEncryptionKey'])
			? $this->db['twoFactorEncryptionKey'] : '';
		$key = preg_match('/^[a-f0-9]{64}$/iD', $keyHex) === 1 ? hex2bin($keyHex) : false;
		if (!is_string($key) || strlen($key) !== 32) return false;
		$parts = explode('.', $encrypted, 2);
		if (count($parts) !== 2) return false;
		$payload = $this->base64UrlDecode($parts[1]);
		if (!is_string($payload)) return false;
		$secret = false;

		if ($parts[0] === 'sb1' && function_exists('sodium_crypto_secretbox_open')) {
			$nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
			if (strlen($payload) <= $nonceLength + SODIUM_CRYPTO_SECRETBOX_MACBYTES) return false;
			$nonce = substr($payload, 0, $nonceLength);
			$cipher = substr($payload, $nonceLength);
			$secret = sodium_crypto_secretbox_open($cipher, $nonce, $key);
		} elseif ($parts[0] === 'og1' && function_exists('openssl_decrypt')) {
			if (strlen($payload) <= 28) return false;
			$iv = substr($payload, 0, 12);
			$tag = substr($payload, 12, 16);
			$cipher = substr($payload, 28);
			$secret = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'Brendy-2FA-v1');
		}
		if (!is_string($secret) || strlen($secret) < 16 || strlen($secret) > 256 ||
			preg_match('/^[A-Z2-7]+$/D', $secret) !== 1) return false;
		return $secret;
	}

	// ====================================================
	// LOGIN ABUSE / BRUTE FORCE PROTECTION
	// ====================================================

	// Authentication must not silently continue when the persistence layer used
	// for brute-force throttling is unavailable. This is intentionally checked on
	// password/2FA POSTs, not on every public request.
	public function loginProtectionAvailable()
	{
		$directory = dirname(DB_SECURITY);
		if (!is_file(DB_SECURITY) || is_link(DB_SECURITY) || !is_readable(DB_SECURITY) || !is_writable(DB_SECURITY) ||
			!is_dir($directory) || is_link($directory) || !is_writable($directory)) return false;
		$integrity = BrendyJsonDatabase::inspectFile(DB_SECURITY, true, 2097152);
		if (!is_array($integrity) || empty($integrity['ok']) || $this->identityHmacKey() === false) return false;
		$lock = $this->openSecurityLock();
		if ($lock === false) return false;
		Filesystem::releaseLock($lock);
		return true;
	}

	public function isBlocked()
	{
		$key = $this->ipFailureKey($this->getUserIp());
		if ($key === false || !isset($this->db['blackList'][$key])) return false;
		$entry = $this->db['blackList'][$key];
		if (!is_array($entry) || !isset($entry['numberFailures'], $entry['lastFailure'])) return false;
		$lastFailure = (int)$entry['lastFailure'];
		if (time() > $lastFailure + ($this->db['minutesBlocked'] * 60)) return false;
		if ((int)$entry['numberFailures'] >= $this->db['numberFailuresAllowed']) {
			Log::set(__METHOD__.LOG_SEP.'IP login throttle active.');
			return true;
		}
		return false;
	}

	public function isAccountBlocked($identifier)
	{
		$key = $this->accountFailureKey($identifier);
		if ($key === false || !isset($this->db['accountFailures'][$key])) return false;
		$entry = $this->db['accountFailures'][$key];
		if (!is_array($entry) || !isset($entry['lastFailure'], $entry['numberFailures'])) return false;
		if (time() > (int)$entry['lastFailure'] + (LOGIN_ACCOUNT_BLOCK_MINUTES * 60)) return false;
		return (int)$entry['numberFailures'] >= LOGIN_ACCOUNT_FAILURES_ALLOWED;
	}

	public function isLoginBlocked($identifier = null)
	{
		return $this->isBlocked() || ($identifier !== null && $this->isAccountBlocked($identifier));
	}

	public function retryAfterSeconds($identifier = null)
	{
		$now = time();
		$retry = 0;
		$ipKey = $this->ipFailureKey($this->getUserIp());
		if ($ipKey !== false && isset($this->db['blackList'][$ipKey]) && is_array($this->db['blackList'][$ipKey])) {
			$entry = $this->db['blackList'][$ipKey];
			if (isset($entry['lastFailure'], $entry['numberFailures']) &&
				(int)$entry['numberFailures'] >= $this->db['numberFailuresAllowed']) {
				$retry = max($retry, ((int)$entry['lastFailure'] + ($this->db['minutesBlocked'] * 60)) - $now);
			}
		}
		if ($identifier !== null) {
			$key = $this->accountFailureKey($identifier);
			if ($key !== false && isset($this->db['accountFailures'][$key]) && is_array($this->db['accountFailures'][$key])) {
				$entry = $this->db['accountFailures'][$key];
				if (isset($entry['lastFailure'], $entry['numberFailures']) &&
					(int)$entry['numberFailures'] >= LOGIN_ACCOUNT_FAILURES_ALLOWED) {
					$retry = max($retry, ((int)$entry['lastFailure'] + (LOGIN_ACCOUNT_BLOCK_MINUTES * 60)) - $now);
				}
			}
		}
		return max(0, min(86400, (int)$retry));
	}

	public function addLoginFailure($identifier = null)
	{
		$ipResult = $this->addToBlacklist();
		$accountResult = $identifier === null ? true : $this->addAccountFailure($identifier);
		return $ipResult && $accountResult;
	}

	// Add or update the current client IP on the blacklist.
	public function addToBlacklist()
	{
		$ip = $this->getUserIp();
		$key = $this->ipFailureKey($ip);
		if ($key === false) return false;
		$lock = $this->openSecurityLock();
		if ($lock === false) return false;
		try {
			$fresh = new BrendyJsonDatabase(DB_SECURITY);
			$db = is_array($fresh->db) ? $fresh->db : $this->db;
			$blackList = $this->normalizeIpFailures(isset($db['blackList']) && is_array($db['blackList']) ? $db['blackList'] : array(), 10000);
			$minutesBlocked = isset($db['minutesBlocked']) && is_int($db['minutesBlocked']) &&
				$db['minutesBlocked'] >= 1 && $db['minutesBlocked'] <= 1440
				? $db['minutesBlocked'] : $this->dbFields['minutesBlocked'];
			$currentTime = time();
			$expiresBefore = $currentTime - ($minutesBlocked * 60);
			foreach ($blackList as $listedKey => $entry) {
				if (!is_string($listedKey) || preg_match('/^[a-f0-9]{64}$/D', $listedKey) !== 1 || !is_array($entry) ||
					!isset($entry['lastFailure'], $entry['numberFailures']) || !is_numeric($entry['lastFailure']) ||
					!is_numeric($entry['numberFailures']) || (int)$entry['lastFailure'] < $expiresBefore ||
					(int)$entry['numberFailures'] < 1 || (int)$entry['numberFailures'] > 1000000) {
					unset($blackList[$listedKey]);
				}
			}
			$numberFailures = 1;
			if (isset($blackList[$key]) && is_array($blackList[$key])) {
				$entry = $blackList[$key];
				if (isset($entry['lastFailure'], $entry['numberFailures']) &&
					$currentTime <= (int)$entry['lastFailure'] + ($minutesBlocked * 60)) {
					$numberFailures = min(1000000, (int)$entry['numberFailures'] + 1);
				}
			}
			if (count($blackList) >= 10000 && !isset($blackList[$key])) {
				uasort($blackList, function ($a, $b) { return ((int)$a['lastFailure']) <=> ((int)$b['lastFailure']); });
				array_shift($blackList);
			}
			$blackList[$key] = array('lastFailure'=>$currentTime, 'numberFailures'=>$numberFailures);
			$db['minutesBlocked'] = $minutesBlocked;
			$db['numberFailuresAllowed'] = isset($db['numberFailuresAllowed']) && is_int($db['numberFailuresAllowed']) &&
				$db['numberFailuresAllowed'] >= 1 && $db['numberFailuresAllowed'] <= 1000
				? $db['numberFailuresAllowed'] : $this->dbFields['numberFailuresAllowed'];
			$db['blackList'] = $blackList;
			$fresh->db = $db;
			$ok = $fresh->save();
			if ($ok) $this->db = $db;
			return $ok;
		} finally {
			Filesystem::releaseLock($lock);
		}
	}

	private function addAccountFailure($identifier)
	{
		$key = $this->accountFailureKey($identifier);
		if ($key === false) return false;
		$lock = $this->openSecurityLock();
		if ($lock === false) return false;
		try {
			$fresh = new BrendyJsonDatabase(DB_SECURITY);
			$db = is_array($fresh->db) ? $fresh->db : $this->db;
			$entries = isset($db['accountFailures']) && is_array($db['accountFailures']) ? $db['accountFailures'] : array();
			$now = time();
			$expiresBefore = $now - (LOGIN_ACCOUNT_BLOCK_MINUTES * 60);
			foreach ($entries as $entryKey => $entry) {
				if (!is_string($entryKey) || preg_match('/^[a-f0-9]{64}$/D', $entryKey) !== 1 || !is_array($entry) ||
					!isset($entry['lastFailure'], $entry['numberFailures']) || !is_numeric($entry['lastFailure']) ||
					!is_numeric($entry['numberFailures']) || (int)$entry['lastFailure'] < $expiresBefore ||
					(int)$entry['numberFailures'] < 1 || (int)$entry['numberFailures'] > 1000000) {
					unset($entries[$entryKey]);
				}
			}
			$count = 1;
			if (isset($entries[$key]) && is_array($entries[$key]) && isset($entries[$key]['lastFailure'], $entries[$key]['numberFailures']) &&
				$now <= (int)$entries[$key]['lastFailure'] + (LOGIN_ACCOUNT_BLOCK_MINUTES * 60)) {
				$count = min(1000000, (int)$entries[$key]['numberFailures'] + 1);
			}
			if (count($entries) >= LOGIN_RATE_LIMIT_RETENTION && !isset($entries[$key])) {
				uasort($entries, function ($a, $b) { return ((int)$a['lastFailure']) <=> ((int)$b['lastFailure']); });
				array_shift($entries);
			}
			$entries[$key] = array('lastFailure' => $now, 'numberFailures' => $count);
			$db['accountFailures'] = $entries;
			$fresh->db = $db;
			$ok = $fresh->save();
			if ($ok) $this->db = $db;
			return $ok;
		} finally {
			Filesystem::releaseLock($lock);
		}
	}

	// Clear IP and (optionally) account buckets after successful authentication.
	public function clearLoginFailures($ip=null, $identifier=null)
	{
		if ($ip === null) $ip = $this->getUserIp();
		if (!is_string($ip) || ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP))) return false;
		$ipKey = $ip === '' ? false : $this->ipFailureKey($ip);
		$accountKey = $identifier === null ? false : $this->accountFailureKey($identifier);
		$lock = $this->openSecurityLock();
		if ($lock === false) return false;
		try {
			$fresh = new BrendyJsonDatabase(DB_SECURITY);
			$db = is_array($fresh->db) ? $fresh->db : array();
			$originalBlackList = isset($db['blackList']) && is_array($db['blackList']) ? $db['blackList'] : array();
			$db['blackList'] = $this->normalizeIpFailures($originalBlackList, 10000);
			$changed = $db['blackList'] !== $originalBlackList;
			if ($ipKey !== false && isset($db['blackList'][$ipKey])) {
				unset($db['blackList'][$ipKey]);
				$changed = true;
			}
			if ($accountKey !== false && isset($db['accountFailures']) && is_array($db['accountFailures']) && isset($db['accountFailures'][$accountKey])) {
				unset($db['accountFailures'][$accountKey]);
				$changed = true;
			}
			if (!$changed) return true;
			$fresh->db = $db;
			if (!$fresh->save()) return false;
			$this->db = $db;
			return true;
		} finally {
			Filesystem::releaseLock($lock);
		}
	}

	public function getNumberFailures($ip=null)
	{
		if(empty($ip)) $ip = $this->getUserIp();
		$key = is_string($ip) ? $this->ipFailureKey($ip) : false;
		if($key !== false && isset($this->db['blackList'][$key])) {
			$entry = $this->db['blackList'][$key];
			return is_array($entry) && isset($entry['numberFailures']) ? (int)$entry['numberFailures'] : 0;
		}
		return 0;
	}

	public function getAccountFailureCount()
	{
		return isset($this->db['accountFailures']) && is_array($this->db['accountFailures']) ? count($this->db['accountFailures']) : 0;
	}

	/**
	 * Defense-in-depth same-origin check shared by admin and public mutation routes.
	 * CSRF/HMAC tokens remain mandatory at their respective call sites. Header
	 * absence is tolerated for privacy clients, while explicit cross-site signals
	 * and malformed Origin/Referer values fail closed.
	 */
	public function sameOriginRequest()
	{
		if (isset($_SERVER['HTTP_SEC_FETCH_SITE']) && is_string($_SERVER['HTTP_SEC_FETCH_SITE'])) {
			$fetchSite = strtolower(trim($_SERVER['HTTP_SEC_FETCH_SITE']));
			if ($fetchSite !== '' && !in_array($fetchSite, array('same-origin', 'none'), true)) return false;
		}

		$source = '';
		if (isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== '') {
			$source = $_SERVER['HTTP_ORIGIN'];
		} elseif (isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] !== '') {
			$source = $_SERVER['HTTP_REFERER'];
		}
		if ($source === '') return true;
		if (strlen($source) > 4096 || preg_match('/[\x00-\x20\x7F]/', $source)) return false;

		$actual = parse_url($source);
		$expected = defined('DOMAIN') ? parse_url(DOMAIN) : false;
		if (!is_array($actual) || !is_array($expected) ||
			!isset($actual['scheme'], $actual['host'], $expected['scheme'], $expected['host']) ||
			isset($actual['user']) || isset($actual['pass'])) return false;

		$actualScheme = strtolower((string)$actual['scheme']);
		$expectedScheme = strtolower((string)$expected['scheme']);
		if (!in_array($actualScheme, array('http', 'https'), true) || $actualScheme !== $expectedScheme) return false;
		$actualHost = strtolower(trim((string)$actual['host'], '[]'));
		$expectedHost = strtolower(trim((string)$expected['host'], '[]'));
		if ($actualHost === '' || $expectedHost === '' || !hash_equals($expectedHost, $actualHost)) return false;
		$actualPort = isset($actual['port']) ? (int)$actual['port'] : ($actualScheme === 'https' ? 443 : 80);
		$expectedPort = isset($expected['port']) ? (int)$expected['port'] : ($expectedScheme === 'https' ? 443 : 80);
		return $actualPort === $expectedPort;
	}

	// Single source of truth for the client IP across Brendy core and plugins.
	// Proxy headers are intentionally ignored because they are forgeable unless
	// a trusted reverse proxy rewrites REMOTE_ADDR at the web-server layer.
	public function getUserIp()
	{
		$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
	}
}
