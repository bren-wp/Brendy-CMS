<?php defined('BRENDY') or die('Brendy CMS.');

class Users extends BrendyJsonDatabase {

	public $dbFields = array(
		'firstName'=>'',
		'lastName'=>'',
		'nickname'=>'',
		'description'=>'',
		'role'=>'author', // admin, editor, author
		'enabled'=>true,
		'password'=>'',
		'salt'=>'!Pink Floyd!Welcome to the machine!',
		'passwordHistory'=>array(),
		'passwordChangedAt'=>0,
		'lastLoginAt'=>0,
		'lastLoginMethod'=>'',
		'email'=>'',
		'registered'=>'1985-03-15 10:00',
		'tokenRemember'=>'',
		'tokenRememberExpires'=>0,
		'tokenAuth'=>'',
		'twoFactorEnabled'=>false,
		'twoFactorSecret'=>'',
		'twoFactorRecoveryCodes'=>array(),
		'twoFactorLastCounter'=>0,
		'twitter'=>'',
		'facebook'=>'',
		'codepen'=>'',
		'instagram'=>'',
		'github'=>'',
		'gitlab'=>'',
		'linkedin'=>'',
		'xing'=>'',
		'telegram'=>'',
		'mastodon'=>'',
		'vk'=>'',
		'youtube'=>'',
		'bluesky'=>''
	);

	function __construct()
	{
		parent::__construct(DB_USERS);
		$changed = false;
		foreach ($this->db as $username => $row) {
			if (!$this->isValidUsername($username) || !is_array($row)) {
				continue;
			}
			$hadRememberExpiry = array_key_exists('tokenRememberExpires', $row);
			// tokenAuthTTL je mrtvo legacy polje: tokenAuth nije vremenski token i
			// ovo se polje godinama nije čitalo ni u jednom runtime putu.
			if (array_key_exists('tokenAuthTTL', $this->db[$username])) {
				unset($this->db[$username]['tokenAuthTTL']);
				$changed = true;
			}
			$legacyDisabledPassword = isset($row['password']) && is_string($row['password']) && $row['password'] === '!';
			foreach ($this->dbFields as $field => $default) {
				if (!array_key_exists($field, $row) || gettype($row[$field]) !== gettype($default)) {
					// A malformed password must fail closed. Brendy 2.2 separates account
					// status from the password hash, but keeps the historical ! marker as
					// a one-way legacy-disabled state until an administrator sets a new
					// password.
					if ($field === 'password') {
						$this->db[$username][$field] = '!';
						$this->db[$username]['enabled'] = false;
					} elseif ($field === 'enabled') {
						$this->db[$username][$field] = !$legacyDisabledPassword;
					} else {
						$this->db[$username][$field] = $default;
					}
					$changed = true;
				}
			}
			if ($legacyDisabledPassword && $this->db[$username]['enabled'] !== false) {
				$this->db[$username]['enabled'] = false;
				$changed = true;
			}
			if ($username === 'admin' && $this->db[$username]['enabled'] !== true) {
				// The primary administrator is the recovery account and cannot be
				// disabled through normal administration paths.
				$this->db[$username]['enabled'] = true;
				$changed = true;
			}
			if (!in_array($this->db[$username]['role'], array('admin', 'editor', 'author'), true)) {
				$this->db[$username]['role'] = ($username === 'admin') ? 'admin' : 'author';
				$changed = true;
			}
			$profileFields = array('firstName', 'lastName', 'nickname', 'description', 'email', 'twitter', 'facebook',
				'codepen', 'instagram', 'github', 'gitlab', 'linkedin', 'xing', 'telegram', 'mastodon', 'vk', 'youtube', 'bluesky');
			foreach ($profileFields as $field) {
				$valid = false;
				$normalized = $this->normalizeProfileField($field, Sanitize::htmlDecode($this->db[$username][$field]), $valid);
				if (!$valid) $normalized = $this->dbFields[$field];
				if ($normalized !== $this->db[$username][$field]) {
					$this->db[$username][$field] = $normalized;
					$changed = true;
				}
			}
			$token = isset($row['tokenAuth']) ? $row['tokenAuth'] : '';
			if (!is_string($token) || preg_match('/^[a-f0-9]{64,128}$/iD', $token) !== 1) {
				$this->db[$username]['tokenAuth'] = $this->generateAuthToken();
				$this->db[$username]['tokenRemember'] = '';
				$this->db[$username]['tokenRememberExpires'] = 0;
				$changed = true;
			}
			$remember = $this->db[$username]['tokenRemember'];
			if ($remember !== '' && (!is_string($remember) ||
				preg_match('/^(?:sha256:)?[a-f0-9]{64}$/iD', $remember) !== 1)) {
				$this->db[$username]['tokenRemember'] = '';
				$changed = true;
			} elseif (is_string($remember) && $remember !== '') {
				// Persistent-login tokens are verifier material, not configuration.
				// Migrate the final plaintext legacy form to a one-way digest and
				// canonicalize already-hashed values so runtime never needs a plaintext
				// comparison branch.
				if (preg_match('/^sha256:([a-f0-9]{64})$/iD', $remember, $rememberMatch) === 1) {
					$canonicalRemember = 'sha256:' . strtolower($rememberMatch[1]);
				} else {
					$canonicalRemember = 'sha256:' . hash('sha256', $remember);
				}
				if (!hash_equals($remember, $canonicalRemember)) {
					$this->db[$username]['tokenRemember'] = $canonicalRemember;
					$changed = true;
				}
			}
			$remember = $this->db[$username]['tokenRemember'];
			$rememberExpiry = isset($this->db[$username]['tokenRememberExpires']) ? (int)$this->db[$username]['tokenRememberExpires'] : 0;
			if ($remember === '') {
				if ($rememberExpiry !== 0) { $this->db[$username]['tokenRememberExpires'] = 0; $changed = true; }
			} elseif (!$hadRememberExpiry) {
				// Existing Brendy <=1.5 persistent tokens gain a bounded server TTL on first load.
				$this->db[$username]['tokenRememberExpires'] = time() + (REMEMBER_COOKIE_EXPIRE_IN_DAYS * 86400);
				$changed = true;
			} elseif ($rememberExpiry < 0 || $rememberExpiry > (time() + ((REMEMBER_COOKIE_EXPIRE_IN_DAYS + 1) * 86400))) {
				$this->db[$username]['tokenRemember'] = '';
				$this->db[$username]['tokenRememberExpires'] = 0;
				$changed = true;
			}


			// Brendy 1.8 keeps a short history of slow, salted password hashes so a
			// password change cannot immediately recycle one of the most recently
			// used credentials. No plaintext or fast password verifier is stored.
			$passwordHistory = is_array($this->db[$username]['passwordHistory'])
				? $this->db[$username]['passwordHistory'] : array();
			$normalizedHistory = array();
			foreach (array_slice($passwordHistory, 0, PASSWORD_HISTORY_LIMIT) as $historyHash) {
				if (!is_string($historyHash) || strlen($historyHash) < 20 || strlen($historyHash) > 512) continue;
				$info = password_get_info($historyHash);
				if (!empty($info['algo'])) {
					$normalizedHistory[] = $historyHash;
					continue;
				}
				if (preg_match('/^legacy-sha1:([a-f0-9]{0,64}):([a-f0-9]{40})$/iD', $historyHash) === 1) {
					$normalizedHistory[] = strtolower($historyHash);
				}
			}
			if ($normalizedHistory !== $passwordHistory) {
				$this->db[$username]['passwordHistory'] = $normalizedHistory;
				$changed = true;
			}
			$passwordChangedAt = (int)$this->db[$username]['passwordChangedAt'];
			if ($passwordChangedAt < 0 || $passwordChangedAt > (time() + 300)) {
				$this->db[$username]['passwordChangedAt'] = 0;
				$changed = true;
			}
			$lastLoginAt = (int)$this->db[$username]['lastLoginAt'];
			if ($lastLoginAt < 0 || $lastLoginAt > (time() + 300)) {
				$this->db[$username]['lastLoginAt'] = 0;
				$changed = true;
			}
			$lastLoginMethod = $this->db[$username]['lastLoginMethod'];
			if (!in_array($lastLoginMethod, array('', 'password', 'two-factor', 'remember'), true)) {
				$this->db[$username]['lastLoginMethod'] = '';
				$changed = true;
			}

			$twoFactorEnabled = $this->db[$username]['twoFactorEnabled'] === true;
			$twoFactorSecret = $this->db[$username]['twoFactorSecret'];
			$twoFactorCounter = (int)$this->db[$username]['twoFactorLastCounter'];
			$recoveryCodes = is_array($this->db[$username]['twoFactorRecoveryCodes'])
				? $this->db[$username]['twoFactorRecoveryCodes'] : array();
			$normalizedRecovery = array();
			foreach (array_slice($recoveryCodes, 0, 12) as $recoveryHash) {
				if (is_string($recoveryHash) && preg_match('/^[a-f0-9]{64}$/D', $recoveryHash) === 1) {
					$normalizedRecovery[$recoveryHash] = true;
				}
			}
			$normalizedRecovery = array_keys($normalizedRecovery);
			$secretValid = is_string($twoFactorSecret) && strlen($twoFactorSecret) <= 8192 &&
				preg_match('/^(?:sb1|og1)\.[A-Za-z0-9_-]{20,8000}$/D', $twoFactorSecret) === 1;
			if ($twoFactorEnabled && $secretValid) {
				if ($twoFactorCounter < 0) { $this->db[$username]['twoFactorLastCounter'] = 0; $changed = true; }
				if ($normalizedRecovery !== $recoveryCodes) { $this->db[$username]['twoFactorRecoveryCodes'] = $normalizedRecovery; $changed = true; }
				// Persistent login cookies intentionally do not bypass the second factor.
				if ($this->db[$username]['tokenRemember'] !== '' || $this->db[$username]['tokenRememberExpires'] !== 0) {
					$this->db[$username]['tokenRemember'] = '';
					$this->db[$username]['tokenRememberExpires'] = 0;
					$changed = true;
				}
			} else {
				if ($twoFactorEnabled || $twoFactorSecret !== '' || !empty($recoveryCodes) || $twoFactorCounter !== 0) {
					$this->db[$username]['twoFactorEnabled'] = false;
					$this->db[$username]['twoFactorSecret'] = '';
					$this->db[$username]['twoFactorRecoveryCodes'] = array();
					$this->db[$username]['twoFactorLastCounter'] = 0;
					$changed = true;
				}
			}
		}
		if ($changed && !$this->save()) {
			// A concurrent writer may have won after this object was loaded. Reload
			// persisted state instead of authenticating against a local repair that
			// never reached disk.
			$this->recoverPersistedState();
			Log::set(__METHOD__ . LOG_SEP . 'Unable to persist repaired user records; persisted state reloaded.', LOG_TYPE_ERROR);
		}
	}

	public function getDefaultFields()
	{
		return $this->dbFields;
	}

	private function isValidUsername($username)
	{
		return is_string($username) && $username !== '' && strlen($username) <= 100 &&
			$username !== '.' && $username !== '..' && basename($username) === $username &&
			strpos($username, "\0") === false && strpos($username, '/') === false &&
			strpos($username, '\\') === false &&
			preg_match('/^[\pL\pN][\pL\pN._@-]{0,99}$/uD', $username) === 1;
	}

	private function normalizeProfileField($field, $value, &$valid)
	{
		$valid = false;
		if (!is_string($value)) return false;
		$limits = array('description' => 5000, 'email' => 254, 'role' => 20);
		$limit = isset($limits[$field]) ? $limits[$field] : 2048;
		if (strlen($value) > $limit) return false;
		if ($field === 'role' && !in_array($value, array('admin', 'editor', 'author'), true)) return false;
		if ($field === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) return false;
		$socialFields = array('twitter', 'facebook', 'codepen', 'instagram', 'github', 'gitlab', 'linkedin',
			'xing', 'telegram', 'mastodon', 'vk', 'youtube', 'bluesky');
		if (in_array($field, $socialFields, true) && $value !== '') {
			$parts = parse_url($value);
			if (preg_match('/[\x00-\x20\x7F]/', $value) || !filter_var($value, FILTER_VALIDATE_URL) ||
				!is_array($parts) || !isset($parts['scheme'], $parts['host']) ||
				!in_array(strtolower($parts['scheme']), array('http', 'https'), true) ||
				isset($parts['user']) || isset($parts['pass'])) return false;
		}
		$valid = true;
		return $field === 'role' ? $value : Sanitize::html(Sanitize::removeTags($value));
	}

	// Return an array with the database of the user, FALSE otherwise
	public function getUserDB($username)
	{
		if ($this->exists($username)) {
			return $this->db[$username];
		}
		return false;
	}

	// Return TRUE if the user exists, FALSE otherwise
	public function exists($username)
	{
		return $this->isValidUsername($username) && isset($this->db[$username]) && is_array($this->db[$username]);
	}

	public function isEnabled($username)
	{
		if (!$this->exists($username)) return false;
		$row = $this->db[$username];
		return isset($row['enabled']) && $row['enabled'] === true &&
			isset($row['password']) && is_string($row['password']) && $row['password'] !== '' && $row['password'] !== '!';
	}

	public function hasUsablePassword($username)
	{
		if (!$this->exists($username)) return false;
		$password = isset($this->db[$username]['password']) ? $this->db[$username]['password'] : null;
		return is_string($password) && $password !== '' && $password !== '!';
	}

	// Disable the user without destroying the password hash. All sessions and
	// persistent logins are revoked immediately by rotating tokenAuth.
	public function disableUser($username)
	{
		if (!$this->exists($username) || $username === 'admin' || !$this->isEnabled($username)) {
			return false;
		}
		$this->db[$username]['enabled'] = false;
		$this->db[$username]['tokenRemember'] = '';
		$this->db[$username]['tokenRememberExpires'] = 0;
		$this->db[$username]['tokenAuth'] = $this->generateAuthToken();
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	// Re-enable a previously disabled user. Legacy accounts disabled by older
	// releases used password='!' and must receive a new password before they can
	// be enabled because the old password hash no longer exists.
	public function enableUser($username)
	{
		if (!$this->exists($username) || $username === 'admin' || $this->isEnabled($username) ||
			!$this->hasUsablePassword($username)) {
			return false;
		}
		$this->db[$username]['enabled'] = true;
		$this->db[$username]['tokenRemember'] = '';
		$this->db[$username]['tokenRememberExpires'] = 0;
		$this->db[$username]['tokenAuth'] = $this->generateAuthToken();
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	public function isEmailAvailable($email, $exceptUsername = '')
	{
		if (!is_string($email) || !is_string($exceptUsername)) return false;
		$email = strtolower(trim($email));
		if ($email === '') return true;
		if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
		foreach ($this->db as $username => $values) {
			if ($username === $exceptUsername || !is_array($values) || !isset($values['email']) || !is_string($values['email'])) continue;
			if (hash_equals(strtolower(Sanitize::htmlDecode($values['email'])), $email)) return false;
		}
		return true;
	}

	// Add a new user
	public function add($args)
	{
		if (!is_array($args) || !isset($args['username'], $args['password']) ||
			!is_string($args['username']) || !is_string($args['password']) ||
			!$this->isValidUsername($args['username']) || strlen($args['password']) < PASSWORD_LENGTH ||
			strlen($args['password']) > PASSWORD_MAX_LENGTH) {
			return false;
		}
		if (isset($args['role']) && (!is_string($args['role']) ||
			!in_array($args['role'], array('admin', 'editor', 'author'), true))) {
			return false;
		}
		// The username is store as key and not as field
		$username = $args['username'];
		if ($username === '' || $this->exists($username)) {
			return false;
		}

		// The password is hashed, the password doesn't need to be sanitized in the next step
		$password = $args['password'];
		$policyEmail = isset($args['email']) && is_string($args['email']) ? $args['email'] : '';
		if ($this->passwordPolicyStatus($password, $username, $policyEmail) !== 'ok') return false;

		$row = $this->dbFields;
		foreach ($this->dbFields as $field=>$value) {
			if (!array_key_exists($field, $args) || in_array($field, array('enabled', 'password', 'salt', 'passwordHistory', 'passwordChangedAt', 'lastLoginAt', 'lastLoginMethod', 'registered', 'tokenRemember', 'tokenRememberExpires', 'tokenAuth', 'twoFactorEnabled', 'twoFactorSecret', 'twoFactorRecoveryCodes', 'twoFactorLastCounter'), true)) continue;
			$valid = false;
			$finalValue = $this->normalizeProfileField($field, $args[$field], $valid);
			if (!$valid) return false;
			$row[$field] = $finalValue;
		}

		if (isset($row['email']) && !$this->isEmailAvailable(Sanitize::htmlDecode($row['email']))) return false;
		if (isset($row['email']) && $row['email'] !== '') $row['email'] = Sanitize::html(strtolower(Sanitize::htmlDecode($row['email'])));

		$row['registered'] = Date::current(DB_DATE_FORMAT);
		$row['salt'] = $this->generateSalt();
		$row['passwordHistory'] = array();
		$row['passwordChangedAt'] = time();
		$row['lastLoginAt'] = 0;
		$row['lastLoginMethod'] = '';
		$row['password'] = $this->generatePasswordHash($password, $row['salt']);
		if ($row['password'] === false) {
			return false;
		}
		$row['tokenAuth'] = $this->generateAuthToken();

		// Save the database
		$this->db[$username] = $row;
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	// Edit a user
	public function set($args)
	{
		if (!is_array($args) || !isset($args['username']) || !is_string($args['username'])) return false;
		if (isset($args['role']) && (!is_string($args['role']) ||
			!in_array($args['role'], array('admin', 'editor', 'author'), true))) {
			return false;
		}
		// The username is store as key and not as field
		$username = $args['username'];
		if (!$this->exists($username)) {
			return false;
		}

		// Current database of the user
		$row = $this->db[$username];
		foreach ($this->dbFields as $field=>$value) {
			if (!array_key_exists($field, $args) || in_array($field, array('enabled', 'password', 'salt', 'passwordHistory', 'passwordChangedAt', 'lastLoginAt', 'lastLoginMethod', 'registered', 'tokenRemember', 'tokenRememberExpires', 'tokenAuth', 'twoFactorEnabled', 'twoFactorSecret', 'twoFactorRecoveryCodes', 'twoFactorLastCounter'), true)) continue;
			$valid = false;
			$finalValue = $this->normalizeProfileField($field, $args[$field], $valid);
			if (!$valid) return false;
			$row[$field] = $finalValue;
		}

		if (isset($row['email']) && !$this->isEmailAvailable(Sanitize::htmlDecode($row['email']), $username)) return false;
		if (isset($row['email']) && $row['email'] !== '') $row['email'] = Sanitize::html(strtolower(Sanitize::htmlDecode($row['email'])));

		// A role change must immediately revoke existing sessions and persistent
		// logins. Login::isLogged() binds sessions to tokenAuth.
		if (isset($args['role']) && $row['role'] !== $this->db[$username]['role']) {
			$row['tokenAuth'] = $this->generateAuthToken();
			$row['tokenRemember'] = '';
			$row['tokenRememberExpires'] = 0;
		}

		// Save the database
		$this->db[$username] = $row;
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	// Delete a user
	public function delete($username)
	{
		if (!$this->exists($username) || $username === 'admin') return false;
		unset($this->db[$username]);
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}


	public function twoFactorEnabled($username)
	{
		return $this->exists($username) && isset($this->db[$username]['twoFactorEnabled']) &&
			$this->db[$username]['twoFactorEnabled'] === true;
	}

	public function twoFactorData($username)
	{
		if (!$this->twoFactorEnabled($username)) return false;
		$row = $this->db[$username];
		return array(
			'secret' => isset($row['twoFactorSecret']) && is_string($row['twoFactorSecret']) ? $row['twoFactorSecret'] : '',
			'recoveryCodes' => isset($row['twoFactorRecoveryCodes']) && is_array($row['twoFactorRecoveryCodes']) ? $row['twoFactorRecoveryCodes'] : array(),
			'lastCounter' => isset($row['twoFactorLastCounter']) ? (int)$row['twoFactorLastCounter'] : 0
		);
	}

	public function enableTwoFactor($username, $encryptedSecret, $recoveryHashes, $initialCounter)
	{
		if (!$this->exists($username) || !is_string($encryptedSecret) || strlen($encryptedSecret) > 8192 ||
			preg_match('/^(?:sb1|og1)\.[A-Za-z0-9_-]{20,8000}$/D', $encryptedSecret) !== 1 ||
			!is_array($recoveryHashes) || count($recoveryHashes) < 4 || count($recoveryHashes) > 12 ||
			!is_int($initialCounter) || $initialCounter < 0) return false;
		$normalized = array();
		foreach ($recoveryHashes as $hash) {
			if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) return false;
			$normalized[$hash] = true;
		}
		if (count($normalized) !== count($recoveryHashes)) return false;
		$newToken = $this->generateAuthToken();
		$this->db[$username]['twoFactorEnabled'] = true;
		$this->db[$username]['twoFactorSecret'] = $encryptedSecret;
		$this->db[$username]['twoFactorRecoveryCodes'] = array_keys($normalized);
		$this->db[$username]['twoFactorLastCounter'] = $initialCounter;
		$this->db[$username]['tokenRemember'] = '';
		$this->db[$username]['tokenRememberExpires'] = 0;
		$this->db[$username]['tokenAuth'] = $newToken;
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return $newToken;
	}

	public function disableTwoFactor($username)
	{
		if (!$this->exists($username)) return false;
		$newToken = $this->generateAuthToken();
		$this->db[$username]['twoFactorEnabled'] = false;
		$this->db[$username]['twoFactorSecret'] = '';
		$this->db[$username]['twoFactorRecoveryCodes'] = array();
		$this->db[$username]['twoFactorLastCounter'] = 0;
		$this->db[$username]['tokenRemember'] = '';
		$this->db[$username]['tokenRememberExpires'] = 0;
		$this->db[$username]['tokenAuth'] = $newToken;
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return $newToken;
	}

	public function replaceTwoFactorRecoveryCodes($username, $recoveryHashes)
	{
		if (!$this->twoFactorEnabled($username) || !is_array($recoveryHashes) ||
			count($recoveryHashes) < 4 || count($recoveryHashes) > 12) return false;
		$normalized = array();
		foreach ($recoveryHashes as $hash) {
			if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) return false;
			$normalized[$hash] = true;
		}
		if (count($normalized) !== count($recoveryHashes)) return false;
		$this->db[$username]['twoFactorRecoveryCodes'] = array_keys($normalized);
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	// Consume a TOTP moving counter. BrendyJsonDatabase::save() uses an optimistic
	// compare-and-replace check, so parallel replay attempts cannot both commit.
	public function consumeTwoFactorCounter($username, $counter)
	{
		if (!$this->twoFactorEnabled($username) || !is_int($counter) || $counter < 0) return false;
		$last = isset($this->db[$username]['twoFactorLastCounter']) ? (int)$this->db[$username]['twoFactorLastCounter'] : 0;
		if ($counter <= $last) return false;
		$this->db[$username]['twoFactorLastCounter'] = $counter;
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	public function consumeTwoFactorRecoveryCode($username, $code)
	{
		if (!$this->twoFactorEnabled($username)) return false;
		$hash = Totp::hashRecoveryCode($code);
		if (!is_string($hash)) return false;
		$codes = isset($this->db[$username]['twoFactorRecoveryCodes']) && is_array($this->db[$username]['twoFactorRecoveryCodes'])
			? $this->db[$username]['twoFactorRecoveryCodes'] : array();
		$matchIndex = false;
		foreach ($codes as $index => $stored) {
			if (is_string($stored) && strlen($stored) === 64 && hash_equals($stored, $hash)) {
				$matchIndex = $index;
				break;
			}
		}
		if ($matchIndex === false) return false;
		unset($codes[$matchIndex]);
		$this->db[$username]['twoFactorRecoveryCodes'] = array_values($codes);
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	public function generateAuthToken()
	{
		return bin2hex(random_bytes(32));
	}

	public function generateRememberToken()
	{
		return $this->generateAuthToken();
	}

	// Rotate the API/authentication token and revoke persistent logins. The new
	// token is returned so the caller can keep the current interactive session
	// valid when a user intentionally rotates their own token.
	public function rotateAuthToken($username)
	{
		if (!$this->exists($username)) {
			return false;
		}
		$token = $this->generateAuthToken();
		$this->db[$username]['tokenAuth'] = $token;
		$this->db[$username]['tokenRemember'] = '';
		$this->db[$username]['tokenRememberExpires'] = 0;
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return $token;
	}

	public function generateSalt()
	{
		return bin2hex(random_bytes(SALT_LENGTH));
	}

	private function preferredPasswordHashConfig()
	{
		if (defined('PASSWORD_ARGON2ID')) {
			return array(
				'algo' => PASSWORD_ARGON2ID,
				'options' => array(
					'memory_cost' => PASSWORD_ARGON_MEMORY_COST,
					'time_cost' => PASSWORD_ARGON_TIME_COST,
					'threads' => PASSWORD_ARGON_THREADS
				)
			);
		}
		return array('algo' => PASSWORD_DEFAULT, 'options' => array());
	}

	// Server-side password policy. It deliberately allows long passphrases while
	// rejecting common credentials and short low-entropy patterns. UI validation
	// is convenience only; this method is the enforcement boundary.
	public function passwordPolicyStatus($password, $username = '', $email = '')
	{
		if (!is_string($password)) return 'invalid';
		if (preg_match('//u', $password) !== 1) return 'encoding';
		$charLength = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
		if ($charLength < PASSWORD_LENGTH) return 'length';
		if (strlen($password) > PASSWORD_MAX_LENGTH) return 'maximum';
		if (preg_match('/[\x00-\x1F\x7F]/', $password)) return 'control';

		$lower = function_exists('mb_strtolower') ? mb_strtolower($password, 'UTF-8') : strtolower($password);
		$compact = preg_replace('/[^\pL\pN]+/u', '', $lower);
		$common = array(
			'password', 'password123', 'password1234', '123456789012', '1234567890',
			'qwerty123456', 'qwertyuiop123', 'administrator', 'adminadmin123',
			'letmein123456', 'welcome123456', 'changeme123456', 'brendy123456',
			'brendigo123456'
		);
		if (is_string($compact) && in_array($compact, $common, true)) return 'common';

		$identityParts = array();
		foreach (array($username, $email) as $identity) {
			if (!is_string($identity) || trim($identity) === '') continue;
			$identity = trim($identity);
			if (strpos($identity, '@') !== false) $identity = explode('@', $identity, 2)[0];
			$identity = function_exists('mb_strtolower') ? mb_strtolower($identity, 'UTF-8') : strtolower($identity);
			$identity = preg_replace('/[^\pL\pN]+/u', '', $identity);
			if (is_string($identity) && strlen($identity) >= 4) $identityParts[] = $identity;
		}
		if (is_string($compact)) {
			foreach (array_unique($identityParts) as $identity) {
				if ($identity !== '' && strpos($compact, $identity) !== false) return 'identity';
			}
		}

		if ($username !== '' && $this->exists($username) && $this->passwordWasUsedRecently($username, $password)) {
			return 'history';
		}

		if ($charLength < 16) {
			$classes = 0;
			if (preg_match('/\p{Ll}/u', $password)) $classes++;
			if (preg_match('/\p{Lu}/u', $password)) $classes++;
			if (preg_match('/\pN/u', $password)) $classes++;
			if (preg_match('/[^\pL\pN\s]/u', $password)) $classes++;
			if ($classes < 3) return 'weak';
		}
		return 'ok';
	}


	private function currentPasswordHistoryEntry($username)
	{
		if (!$this->exists($username)) return false;
		$row = $this->db[$username];
		$hash = isset($row['password']) && is_string($row['password']) ? $row['password'] : '';
		if ($hash === '' || $hash === '!') return false;
		$info = password_get_info($hash);
		if (!empty($info['algo'])) return $hash;
		if (preg_match('/^[a-f0-9]{40}$/iD', $hash) !== 1) return false;
		$salt = isset($row['salt']) && is_string($row['salt']) ? $row['salt'] : '';
		if (strlen($salt) > 64 || preg_match('/[^a-f0-9]/i', $salt)) return false;
		return 'legacy-sha1:' . strtolower($salt) . ':' . strtolower($hash);
	}

	private function passwordMatchesHistoryEntry($password, $entry)
	{
		if (!is_string($password) || !is_string($entry) || $entry === '') return false;
		$info = password_get_info($entry);
		if (!empty($info['algo'])) return password_verify($password, $entry);
		if (preg_match('/^legacy-sha1:([a-f0-9]{0,64}):([a-f0-9]{40})$/iD', $entry, $matches) === 1) {
			return hash_equals(strtolower($matches[2]), sha1($password . $matches[1]));
		}
		return false;
	}

	public function passwordWasUsedRecently($username, $password)
	{
		if (!$this->exists($username) || !is_string($password)) return false;
		$current = $this->currentPasswordHistoryEntry($username);
		if (is_string($current) && $this->passwordMatchesHistoryEntry($password, $current)) return true;
		$history = isset($this->db[$username]['passwordHistory']) && is_array($this->db[$username]['passwordHistory'])
			? array_slice($this->db[$username]['passwordHistory'], 0, PASSWORD_HISTORY_LIMIT) : array();
		foreach ($history as $entry) {
			if ($this->passwordMatchesHistoryEntry($password, $entry)) return true;
		}
		return false;
	}

	public function passwordHistoryCount($username)
	{
		if (!$this->exists($username)) return 0;
		return isset($this->db[$username]['passwordHistory']) && is_array($this->db[$username]['passwordHistory'])
			? min(PASSWORD_HISTORY_LIMIT, count($this->db[$username]['passwordHistory'])) : 0;
	}

	public function passwordChangedAt($username)
	{
		return $this->exists($username) ? max(0, (int)$this->db[$username]['passwordChangedAt']) : 0;
	}

	public function recordSuccessfulLogin($username, $method)
	{
		if (!$this->exists($username) || !is_string($method) ||
			!in_array($method, array('password', 'two-factor', 'remember'), true)) return false;
		$this->db[$username]['lastLoginAt'] = time();
		$this->db[$username]['lastLoginMethod'] = $method;
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	public function generatePasswordHash($password, $salt)
	{
		if (!is_string($password)) return false;
		$config = $this->preferredPasswordHashConfig();
		try {
			$hash = password_hash($password, $config['algo'], $config['options']);
			if (is_string($hash) && $hash !== '') return $hash;
		} catch (Throwable $e) {
			Log::set(__METHOD__ . LOG_SEP . 'Preferred password hash unavailable; using portable fallback.', LOG_TYPE_ERROR);
		}
		try {
			return password_hash($password, PASSWORD_DEFAULT);
		} catch (Throwable $e) {
			return false;
		}
	}

	// Consume a realistic password-verification cost for missing/disabled users so
	// authentication timing does not cheaply reveal whether an account exists.
	public function burnPasswordVerificationCost($password)
	{
		if (!is_string($password)) $password = '';
		if (defined('PASSWORD_ARGON2ID')) {
			return password_verify($password, '$argon2id$v=19$m=19456,t=2,p=1$UEJCcjR0NWUzVEtmVk1UdA$SFlJoVvQUw5EHQy8u6efRPlyxfsswuS1F9LMsGvQ8yU');
		}
		return password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
	}

	// Verify modern hashes and transparently migrate historical SHA-1 hashes.
	public function verifyPassword($username, $password)
	{
		if (!is_string($username) || !is_string($password)) {
			return false;
		}
		$row = $this->getUserDB($username);
		if ($row === false || !isset($row['password']) || !is_string($row['password']) || $row['password'] === '!') {
			return false;
		}

		$storedHash = (string)$row['password'];
		$info = password_get_info($storedHash);
		if (!empty($info['algo'])) {
			if (!password_verify($password, $storedHash)) return false;
			$config = $this->preferredPasswordHashConfig();
			if (password_needs_rehash($storedHash, $config['algo'], $config['options'])) {
				$this->upgradePasswordHash($username, $password);
			}
			return true;
		}

		$salt = isset($row['salt']) && is_string($row['salt']) ? $row['salt'] : '';
		$legacyHash = sha1($password . $salt);
		if (!hash_equals($storedHash, $legacyHash)) {
			// Legacy SHA-1 comparison is much faster than a modern password hash.
			// Burn one bcrypt verification on failure to reduce timing disclosure
			// until the account is migrated after its next successful login.
			$this->burnPasswordVerificationCost($password);
			return false;
		}

		$this->upgradePasswordHash($username, $password);
		return true;
	}

	private function upgradePasswordHash($username, $password)
	{
		$newHash = $this->generatePasswordHash($password, '');
		if ($newHash === false) {
			return false;
		}
		$this->db[$username]['password'] = $newHash;
		$this->db[$username]['salt'] = '';
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	public function setRememberToken($username, $token)
	{
		if (!$this->exists($username) || !is_string($token) || preg_match('/^[a-f0-9]{64}$/iD', $token) !== 1) {
			return false;
		}
		$this->db[$username]['tokenRemember'] = 'sha256:' . hash('sha256', $token);
		$this->db[$username]['tokenRememberExpires'] = time() + (REMEMBER_COOKIE_EXPIRE_IN_DAYS * 86400);
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	// Change user password
	// args => array( username, password )
	public function setPassword($args)
	{
		if (!is_array($args) || !isset($args['username'], $args['password']) ||
			!is_string($args['username']) || !is_string($args['password']) || !$this->exists($args['username']) ||
			strlen($args['password']) < PASSWORD_LENGTH || strlen($args['password']) > PASSWORD_MAX_LENGTH) {
			return false;
		}
		$username = $args['username'];
		$email = isset($this->db[$username]['email']) && is_string($this->db[$username]['email'])
			? Sanitize::htmlDecode($this->db[$username]['email']) : '';
		if ($this->passwordPolicyStatus($args['password'], $username, $email) !== 'ok') return false;
		$hash = $this->generatePasswordHash($args['password'], '');
		if ($hash === false) return false;
		$history = isset($this->db[$username]['passwordHistory']) && is_array($this->db[$username]['passwordHistory'])
			? $this->db[$username]['passwordHistory'] : array();
		$currentEntry = $this->currentPasswordHistoryEntry($username);
		if (is_string($currentEntry) && $currentEntry !== '') array_unshift($history, $currentEntry);
		$this->db[$username]['passwordHistory'] = array_slice($history, 0, PASSWORD_HISTORY_LIMIT);
		$this->db[$username]['passwordChangedAt'] = time();
		$this->db[$username]['password'] = $hash;
		$this->db[$username]['salt'] = '';
		$this->db[$username]['tokenAuth'] = $this->generateAuthToken();
		$this->db[$username]['tokenRemember'] = '';
		$this->db[$username]['tokenRememberExpires'] = 0;
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	// Return the username associated to an email, FALSE otherwise
	public function getByEmail($email)
	{
		if (!is_string($email)) return false;
		$email = strtolower(trim($email));
		if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
		foreach ($this->db as $username=>$values) {
			if (!$this->isValidUsername($username) || !is_array($values) || !isset($values['email']) || !is_string($values['email'])) continue;
			$stored = strtolower(Sanitize::htmlDecode($values['email']));
			if ($stored !== '' && hash_equals($stored, $email)) return $username;
		}
		return false;
	}

	// Returns the username with the authentication token assigned, FALSE otherwise
	public function getByAuthToken($token)
	{
		if (!is_string($token) || preg_match('/^[a-f0-9]{64,128}$/iD', $token) !== 1) {
			return false;
		}
		foreach ($this->db as $username=>$fields) {
			if ($this->isValidUsername($username) && is_array($fields) && isset($fields['tokenAuth']) && is_string($fields['tokenAuth']) &&
				$fields['tokenAuth'] !== '' && preg_match('/^[a-f0-9]{64,128}$/iD', $fields['tokenAuth']) === 1 &&
				hash_equals(strtolower($fields['tokenAuth']), strtolower($token))) {
				return $username;
			}
		}
		return false;
	}

	// Returns the username with the remember token assigned, FALSE otherwise
	public function getByRememberToken($token)
	{
		if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/iD', $token) !== 1) {
			return false;
		}
		$tokenHash = hash('sha256', $token);
		foreach ($this->db as $username=>$fields) {
			if (!$this->isValidUsername($username) || !is_array($fields)) continue;
			$expiry = isset($fields['tokenRememberExpires']) ? (int)$fields['tokenRememberExpires'] : 0;
			if ($expiry <= time()) continue;
			$stored = isset($fields['tokenRemember']) && is_string($fields['tokenRemember'])
				? $fields['tokenRemember'] : '';
			if (preg_match('/^sha256:([a-f0-9]{64})$/D', $stored, $storedMatch) === 1 &&
				hash_equals($storedMatch[1], $tokenHash)) {
				return $username;
			}
		}
		return false;
	}

	// This function clean all tokens for Remember me
	// This function is used when some hacker try to use an invalid remember token
	public function invalidateAllRememberTokens()
	{
		foreach ($this->db as $username=>$values) {
			if (is_array($values)) {
				$this->db[$username]['tokenRemember'] = '';
				$this->db[$username]['tokenRememberExpires'] = 0;
			}
		}
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	public function invalidateRememberToken($username, $presentedToken = null)
	{
		if (!$this->exists($username)) {
			return false;
		}
		if ($presentedToken !== null && $this->getByRememberToken($presentedToken) !== $username) {
			return false;
		}
		$this->db[$username]['tokenRemember'] = '';
		$this->db[$username]['tokenRememberExpires'] = 0;
		if (!$this->save()) {
			$this->recoverPersistedState();
			return false;
		}
		return true;
	}

	public function keys()
	{
		return array_values(array_filter(array_keys($this->db), array($this, 'isValidUsername')));
	}
}
