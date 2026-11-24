<?php defined('BRENDY') or die('Brendy CMS.');

class Login
{
	protected $users;
	protected $site;

	function __construct()
	{
		if (isset($GLOBALS['users'])) {
			$this->users = $GLOBALS['users'];
		} else {
			$this->users = new Users();
		}

		if (isset($GLOBALS['site'])) {
			$this->site = $GLOBALS['site'];
		} else {
			$this->site = new Site();
		}

		// Start the Session
		if (!Session::started()) {
			$sessionPath = defined('HTML_PATH_ROOT') ? HTML_PATH_ROOT : $this->site->urlPath();
			if (!Session::start($sessionPath, $this->site->isHTTPS())) {
				Log::set(__METHOD__ . LOG_SEP . 'Unable to start a secure session.', LOG_TYPE_ERROR);
			}
		}
	}

	// Returns the username of the user logged
	public function username()
	{
		return Session::get('username');
	}

	// Returns the role of the user logged
	public function role()
	{
		return Session::get('role');
	}

	// Returns TRUE if the user is logged, FALSE otherwise
	public function isLogged()
	{
		if (!Session::started()) {
			return false;
		}

		$sessionFingerprint = Session::get('fingerPrint');
		$username = Session::get('username');

		// A fresh anonymous session legitimately contains only the CSRF token.
		// Keep it alive so the login form can validate CSRF and then establish the
		// authenticated state. Destroy only partial/tampered authentication state.
		$hasAuthenticationState = $sessionFingerprint !== false || $username !== false ||
			Session::get('role') !== false || Session::get('tokenAuth') !== false ||
			Session::get('sessionTime') !== false;
		if (!$hasAuthenticationState) {
			return false;
		}

		if (is_string($sessionFingerprint) && hash_equals($sessionFingerprint, $this->fingerPrint())) {
			if (is_string($username) && $username !== '') {
				$userDB = $this->users->getUserDB($username);
				if ($userDB === false || !$this->users->isEnabled($username)) {
					Log::set(__METHOD__ . LOG_SEP . 'User no longer exists or is disabled, destroying the session.');
					Session::destroy();
					return false;
				}

				$now = time();
				$sessionTime = (int)Session::get('sessionTime');
				if ($sessionTime <= 0 || ($now - $sessionTime) > SESSION_GC_MAXLIFETIME) {
					Log::set(__METHOD__ . LOG_SEP . 'Session expired after idle timeout.');
					Session::destroy();
					return false;
				}

				$sessionCreated = Session::get('sessionCreated');
				// Modern authenticated sessions always carry an absolute creation time.
				// Do not synthesize it from the last-activity timestamp: doing so would
				// let a copied pre-hardening session reset its absolute lifetime after an
				// upgrade. Legacy authenticated sessions must sign in again instead.
				if ($sessionCreated === false) {
					Log::set(__METHOD__ . LOG_SEP . 'Authenticated session is missing its creation timestamp; forcing re-authentication.', LOG_TYPE_WARN);
					Session::destroy();
					return false;
				}
				$sessionCreated = (int)$sessionCreated;
				if ($sessionCreated <= 0 || $sessionCreated > ($now + 60) ||
					($now - $sessionCreated) > SESSION_ABSOLUTE_MAXLIFETIME) {
					Log::set(__METHOD__ . LOG_SEP . 'Session expired after absolute lifetime.');
					Session::destroy();
					return false;
				}

				$sessionAuthToken = Session::get('tokenAuth');
				// tokenAuth is the revocation binding for password/role/2FA changes.
				// Accepting a legacy authenticated session without that binding and
				// filling in the *current* token would let an old copied session survive a
				// credential rotation. Fail closed and require a fresh login instead.
				if (!is_string($sessionAuthToken) || $sessionAuthToken === '' ||
					!hash_equals((string)$userDB['tokenAuth'], $sessionAuthToken)) {
					Log::set(__METHOD__ . LOG_SEP . 'Session revoked by account change.');
					Session::destroy();
					return false;
				}

				// Periodically rotate the session identifier without destroying the session.
				// This narrows the useful lifetime of a copied session ID while preserving UX.
				$sessionRotated = (int)Session::get('sessionRotated');
				if ($sessionRotated <= 0) {
					$sessionRotated = $sessionCreated;
					Session::set('sessionRotated', $sessionRotated);
				}
				if (($now - $sessionRotated) >= SESSION_ID_ROTATE_INTERVAL) {
					if (!Session::regenerate(true)) {
						Log::set(__METHOD__ . LOG_SEP . 'Unable to rotate authenticated session ID.', LOG_TYPE_WARN);
						Session::destroy();
						return false;
					}
					Session::set('sessionRotated', $now);
				}

				// Always use the role currently stored in the user database.
				Session::set('role', $userDB['role']);
				Session::set('sessionTime', $now);
				return true;
			} else {
				Log::set(__METHOD__ . LOG_SEP . 'Session username empty, destroying the session.');
				Session::destroy();
				return false;
			}
		}

		Log::set(__METHOD__ . LOG_SEP . 'FingerPrints are different. [' . Session::get('fingerPrint') . '] != [' . $this->fingerPrint() . ']');
		if (Session::started()) Session::destroy();
		return false;
	}

	// Set the authenticated session. Password/TOTP logins establish a short
	// recent-authentication window for sensitive mutations; persistent
	// Remember-me logins deliberately do not.
	public function setLogin($username, $role, $secondFactorVerified = false, $authenticationMethod = 'password')
	{
		if (!Session::started() || !is_string($username) ||
			!in_array($authenticationMethod, array('password', 'two-factor', 'remember'), true)) return false;
		$userDB = $this->users->getUserDB($username);
		if ($userDB === false || !isset($userDB['password'], $userDB['role'], $userDB['tokenAuth']) ||
			!is_string($userDB['role']) || !is_string($userDB['tokenAuth']) || !$this->users->isEnabled($username)) {
			return false;
		}
		// Fail closed for protected accounts. Ordinary callers cannot accidentally
		// establish a session after only the password step. The dedicated second-
		// factor controller is the only first-party path that passes TRUE here.
		if ($this->users->twoFactorEnabled($username) && $secondFactorVerified !== true) return false;

		// Regenerate session ID to prevent session fixation attacks.
		if (!Session::regenerate(true)) return false;

		Session::set('username', $username);
		Session::set('role', $userDB['role']);
		Session::set('tokenAuth', $userDB['tokenAuth']);
		Session::set('fingerPrint', $this->fingerPrint());
		$now = time();
		Session::set('sessionTime', $now);
		Session::set('sessionCreated', $now);
		Session::set('sessionRotated', $now);
		if ($authenticationMethod === 'remember') {
			$this->clearRecentAuthentication();
		} else {
			$this->markRecentAuthentication($authenticationMethod);
		}

		Log::set(__METHOD__ . LOG_SEP . 'User logged, fingerprint [' . $this->fingerPrint() . ']');
		return true;
	}

	public function markRecentAuthentication($method = 'password')
	{
		if (!Session::started() || !in_array($method, array('password', 'two-factor'), true)) return false;
		$now = time();
		return Session::set('recentAuthAt', $now) &&
			Session::set('recentAuthFingerprint', $this->fingerPrint()) &&
			Session::set('recentAuthMethod', $method) &&
			Session::set('recentAuthAttempts', 0);
	}

	public function clearRecentAuthentication()
	{
		if (!Session::started()) return false;
		foreach (array('recentAuthAt', 'recentAuthFingerprint', 'recentAuthMethod', 'recentAuthAttempts') as $key) {
			Session::remove($key);
		}
		return true;
	}

	public function recentAuthenticationValid($maxAge = STEP_UP_AUTH_TTL)
	{
		if (!$this->isLogged()) return false;
		$maxAge = max(60, min(3600, (int)$maxAge));
		$at = (int)Session::get('recentAuthAt');
		$fingerprint = Session::get('recentAuthFingerprint');
		$method = Session::get('recentAuthMethod');
		$now = time();
		if ($at <= 0 || $at > ($now + 30) || ($now - $at) > $maxAge ||
			!is_string($fingerprint) || !hash_equals($this->fingerPrint(), $fingerprint) ||
			!in_array($method, array('password', 'two-factor'), true)) {
			$this->clearRecentAuthentication();
			return false;
		}
		return true;
	}

	private function rememberCookieOptions()
	{
		$path = defined('HTML_PATH_ADMIN_ROOT') ? HTML_PATH_ADMIN_ROOT :
			(defined('HTML_PATH_ROOT') ? HTML_PATH_ROOT : '/');
		if (!is_string($path) || $path === '' || $path[0] !== '/' || preg_match('/[\x00-\x1F\x7F]/', $path)) {
			$path = '/';
		}
		$secure = method_exists($this->site, 'isHTTPS') ? (bool)$this->site->isHTTPS() : false;
		return array('path' => $path, 'samesite' => 'Strict', 'httponly' => true, 'secure' => $secure);
	}

	public static function rememberCookieNames($secure = null)
	{
		if ($secure === null) {
			$secure = isset($GLOBALS['site']) && is_object($GLOBALS['site']) && method_exists($GLOBALS['site'], 'isHTTPS')
				? (bool)$GLOBALS['site']->isHTTPS()
				: ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
					(isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443));
		}
		$prefix = $secure ? '__Secure-' : '';
		return array(
			'username' => $prefix . REMEMBER_COOKIE_USERNAME, // legacy cleanup only
			'token' => $prefix . REMEMBER_COOKIE_TOKEN
		);
	}

	public static function hasRememberCookies()
	{
		foreach (array(true, false) as $secure) {
			$names = self::rememberCookieNames($secure);
			if (!Cookie::isEmpty($names['token'])) return true;
		}
		return false;
	}

	private function clearRememberCookies()
	{
		$cookieOptions = $this->rememberCookieOptions();
		$rootOptions = array(
			'path' => defined('HTML_PATH_ROOT') ? HTML_PATH_ROOT : '/',
			'samesite' => 'Strict',
			'httponly' => true,
			'secure' => !empty($cookieOptions['secure'])
		);
		foreach (array(true, false) as $secureName) {
			$names = self::rememberCookieNames($secureName);
			// Clearing a __Secure- cookie must itself use Secure. Legacy unprefixed
			// cookies are cleared with the current transport attributes too.
			$options = $cookieOptions;
			if ($secureName) $options['secure'] = true;
			Cookie::remove($names['username'], $options);
			Cookie::remove($names['token'], $options);
			if ($rootOptions['path'] !== $cookieOptions['path']) {
				$root = $rootOptions;
				if ($secureName) $root['secure'] = true;
				Cookie::remove($names['username'], $root);
				Cookie::remove($names['token'], $root);
			}
		}
	}

	public function setRememberMe($username)
	{
		$username = trim((string)$username);
		$userDB = $this->users->getUserDB($username);
		if ($userDB === false || !$this->users->isEnabled($username) || $this->users->twoFactorEnabled($username)) return false;

		$token = $this->users->generateRememberToken();
		if (!$this->users->setRememberToken($username, $token)) return false;

		$cookieOptions = $this->rememberCookieOptions();
		$names = self::rememberCookieNames(!empty($cookieOptions['secure']));

		// Brendy 1.6 stores only the opaque rotating token. The username cookie was
		// redundant because the server can resolve the token itself and unnecessarily
		// disclosed the account name to browser cookie storage.
		$this->clearRememberCookies();
		if (!Cookie::set($names['token'], $token, REMEMBER_COOKIE_EXPIRE_IN_DAYS, $cookieOptions)) {
			$this->users->invalidateRememberToken($username);
			$this->clearRememberCookies();
			return false;
		}

		Log::set(__METHOD__ . LOG_SEP . 'Rotating Remember Me token cookie set.');
		return true;
	}

	public function invalidateRememberMe($invalidateServerToken = true)
	{
		$sessionUsername = $this->username();
		$presentedToken = false;
		foreach (array(true, false) as $secureName) {
			$names = self::rememberCookieNames($secureName);
			$value = Cookie::get($names['token']);
			if (is_string($value) && $value !== '') {
				$presentedToken = $value;
				break;
			}
		}

		if ($invalidateServerToken) {
			if (is_string($sessionUsername) && $sessionUsername !== '') {
				$this->users->invalidateRememberToken($sessionUsername);
			} elseif (is_string($presentedToken)) {
				$username = $this->users->getByRememberToken($presentedToken);
				if (is_string($username) && $username !== '') {
					$this->users->invalidateRememberToken($username, $presentedToken);
				}
			}
		}
		$this->clearRememberCookies();
	}

	// Check if the username and the password are valid
	// Returns TRUE if valid and set the session
	// Returns FALSE for invalid username or password
	public function verifyPrimaryCredentials($username, $password)
	{
		if (!is_string($username) || !is_string($password)) return false;
		$username = trim($username);
		if ($username === '' || strlen($username) > 254 || preg_match('/[\x00-\x1F\x7F]/', $username)) return false;
		if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
			$resolvedUsername = $this->users->getByEmail($username);
			if (is_string($resolvedUsername) && $resolvedUsername !== '') $username = $resolvedUsername;
		}
		if ($username === '' || $password === '' || strlen($password) > 4096) return false;

		$userDB = $this->users->getUserDB($username);
		if ($userDB === false || !$this->users->isEnabled($username) || !isset($userDB['password']) || !is_string($userDB['password'])) {
			$this->users->burnPasswordVerificationCost($password);
			return false;
		}
		if (!$this->users->verifyPassword($username, $password)) return false;
		return $username;
	}

	// Compatibility helper for code paths that do not support a second-factor
	// challenge. Accounts protected by 2FA deliberately fail closed here.
	public function verifyUser($username, $password)
	{
		$resolvedUsername = $this->verifyPrimaryCredentials($username, $password);
		if (!is_string($resolvedUsername) || $resolvedUsername === '' || $this->users->twoFactorEnabled($resolvedUsername)) {
			return false;
		}
		$userDB = $this->users->getUserDB($resolvedUsername);
		if (!is_array($userDB) || !$this->setLogin($resolvedUsername, $userDB['role'])) return false;
		Log::set(__METHOD__ . LOG_SEP . 'Successful user login - Username [' . $resolvedUsername . ']');
		return true;
	}

	// Authenticate a rotating persistent token. The account name is derived on
	// the server and is no longer trusted/stored in a browser cookie.
	public function verifyUserByRemember()
	{
		$token = false;
		foreach (array(true, false) as $secureName) {
			$names = self::rememberCookieNames($secureName);
			$value = Cookie::get($names['token']);
			if (is_string($value) && $value !== '') {
				$token = trim($value);
				break;
			}
		}
		if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/iD', $token) !== 1) {
			if ($token !== false) $this->invalidateRememberMe(false);
			return false;
		}

		$username = $this->users->getByRememberToken($token);
		if (!is_string($username) || $username === '') {
			$this->invalidateRememberMe(false);
			Log::set(__METHOD__ . LOG_SEP . 'Remember Me token is invalid.');
			return false;
		}

		$user = $this->users->getUserDB($username);
		if ($user === false || !$this->users->isEnabled($username) || $this->users->twoFactorEnabled($username)) {
			$this->invalidateRememberMe(false);
			if ($this->users->twoFactorEnabled($username)) $this->users->invalidateRememberToken($username, $token);
			return false;
		}
		if (!$this->setLogin($username, $user['role'], false, 'remember')) {
			$this->invalidateRememberMe(false);
			return false;
		}
		// One successful replay consumes the old token. A fresh token is persisted
		// before continuing, reducing the useful window of a stolen cookie.
		if (!$this->setRememberMe($username)) {
			$this->logout();
			return false;
		}
		Log::set(__METHOD__ . LOG_SEP . 'User authenticated via rotating Remember Me token.');
		return true;
	}

	public function fingerPrint()
	{
		// HTTP headers belong to the request server array. getenv() is not reliable
		// across every PHP-FPM/shared-hosting configuration (for example clear_env),
		// and falling back too early would collapse unrelated browser sessions onto
		// the same generic fingerprint. Keep getenv() only as a compatibility fallback.
		$agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
			? trim($_SERVER['HTTP_USER_AGENT']) : '';
		if ($agent === '') {
			$environmentAgent = getenv('HTTP_USER_AGENT');
			$agent = is_string($environmentAgent) ? trim($environmentAgent) : '';
		}
		if ($agent === '' || strlen($agent) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $agent)) {
			$agent = 'Brendy-CMS/' . (defined('BRENDY_VERSION') ? BRENDY_VERSION : '1');
		}
		return hash('sha256', $agent);
	}

	public function logout()
	{
		$this->invalidateRememberMe();
		Session::destroy();
		return true;
	}
}
