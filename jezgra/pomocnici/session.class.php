<?php defined('BRENDY') or die('Brendy CMS.');

class Session {

	private static $started = false;
	private static $sessionName = 'BRENDY-KEY';
	private static $isolatedSavePath = false;

	public static function cookieName($secure=false)
	{
		return $secure ? '__Secure-' . self::$sessionName : self::$sessionName;
	}

	private static function validSessionId($value)
	{
		return is_string($value) && strlen($value) >= 16 && strlen($value) <= 256 &&
			preg_match('/^[A-Za-z0-9,-]+$/D', $value) === 1;
	}

	public static function hasCookie()
	{
		foreach (array(false, true) as $secure) {
			$name = self::cookieName($secure);
			if (isset($_COOKIE[$name]) && self::validSessionId($_COOKIE[$name])) return true;
		}
		return false;
	}

	// Rotate an authenticated session identifier without changing session data.
	// Credential-state mutations call this after rotating tokenAuth so a copied
	// pre-change session ID cannot continue sharing the newly-authorized state.
	public static function regenerate($deleteOldSession = true)
	{
		if (!self::$started || session_status() !== PHP_SESSION_ACTIVE) return false;
		$oldId = session_id();
		if (!is_string($oldId) || $oldId === '' || !session_regenerate_id($deleteOldSession === true)) return false;
		$newId = session_id();
		return is_string($newId) && $newId !== '' && !hash_equals($oldId, $newId);
	}

	public static function start($path, $secure)
	{
		if (self::$started) {
			return session_status() === PHP_SESSION_ACTIVE;
		}
		// Never adopt an externally/automatically started PHP session. Doing so
		// would bypass Brendy's strict cookie parameters and isolated file store.
		if (session_status() === PHP_SESSION_ACTIVE) {
			Log::set(__METHOD__.LOG_SEP.'Refusing to adopt a PHP session that Brendy did not start.', LOG_TYPE_ERROR);
			return false;
		}
		if (session_status() === PHP_SESSION_DISABLED || headers_sent()) {
			return false;
		}
		if (!is_string($path) || strpos($path, '/') !== 0 || preg_match('/[\x00-\x1F\x7F]/', $path)) {
			$path = '/';
		}
		$secure = (bool)$secure;

		// On shared hosting the default PHP file-session directory can be shared by
		// unrelated applications. Isolate Brendy's session files inside its already
		// protected private tmp tree whenever the standard files handler is in use.
		self::$isolatedSavePath = false;
		if (defined('PATH_TMP') && strtolower((string)ini_get('session.save_handler')) === 'files' &&
			is_dir(PATH_TMP) && !is_link(PATH_TMP)) {
			$sessionDirectory = PATH_TMP . 'sessions' . (defined('DS') ? DS : DIRECTORY_SEPARATOR);
			if ((!is_dir($sessionDirectory) && @mkdir($sessionDirectory, 0700, true)) || is_dir($sessionDirectory)) {
				$realTmp = realpath(PATH_TMP);
				$realSessions = realpath($sessionDirectory);
				if ($realTmp !== false && $realSessions !== false && !is_link($sessionDirectory) && is_writable($realSessions) &&
					strpos($realSessions . DIRECTORY_SEPARATOR, rtrim($realTmp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0) {
					@chmod($realSessions, 0700);
					session_save_path($realSessions);
					self::$isolatedSavePath = realpath((string)session_save_path()) === $realSessions;
				}
			}
		}

		// With the standard file handler, never fall back to PHP's process/global
		// session directory. On shared hosting that location may be shared by other
		// applications under the same account. Brendy's private tmp tree is part of
		// the authenticated-session security boundary, so inability to isolate it is
		// a startup error rather than a silent degradation.
		if (strtolower((string)ini_get('session.save_handler')) === 'files' && !self::$isolatedSavePath) {
			Log::set(__METHOD__.LOG_SEP.'Unable to establish the isolated Brendy file-session directory.', LOG_TYPE_ERROR);
			return false;
		}

		// Try to set the session timeout on server side, 1 hour of timeout
		ini_set('session.gc_maxlifetime', SESSION_GC_MAXLIFETIME);
		ini_set('session.use_strict_mode', '1');
		ini_set('session.use_only_cookies', '1');
		ini_set('session.use_cookies', '1');
		ini_set('session.use_trans_sid', '0');
		ini_set('session.cookie_httponly', '1');
		ini_set('session.cookie_samesite', 'Strict');
		ini_set('session.cookie_secure', $secure ? '1' : '0');
		ini_set('session.cache_limiter', 'nocache');

		// Use a host-only session cookie. Inheriting php.ini's session.cookie_domain
		// could unintentionally expose the admin session to sibling subdomains.
		session_set_cookie_params(array(
			'lifetime' => SESSION_COOKIE_LIFE_TIME,
			'path' => $path,
			'domain' => '',
			'secure' => $secure,
			'httponly' => true,
			'samesite' => 'Strict'
		));

		// Do not silently continue if hosting policy prevents Brendy from enforcing
		// the cookie/session properties that protect authenticated administration.
		$requiredIni = array(
			'session.use_strict_mode' => '1',
			'session.use_only_cookies' => '1',
			'session.use_cookies' => '1',
			'session.use_trans_sid' => '0',
			'session.cookie_httponly' => '1'
		);
		foreach ($requiredIni as $key => $expected) {
			if ((string)ini_get($key) !== $expected) {
				Log::set(__METHOD__.LOG_SEP.'Required PHP session policy could not be enforced: '.$key, LOG_TYPE_ERROR);
				return false;
			}
		}
		$params = session_get_cookie_params();
		if (!is_array($params) || empty($params['httponly']) ||
			!isset($params['samesite']) || strcasecmp((string)$params['samesite'], 'Strict') !== 0 ||
			(bool)($params['secure'] ?? false) !== $secure ||
			!isset($params['path']) || (string)$params['path'] !== $path ||
			(isset($params['domain']) && (string)$params['domain'] !== '')) {
			Log::set(__METHOD__.LOG_SEP.'Required Brendy session cookie policy could not be enforced.', LOG_TYPE_ERROR);
			return false;
		}

		// Sets the session name to the one set above.
		// Use the __Secure- prefix when served over HTTPS to prevent cookie hijacking.
		$sessionName = self::cookieName($secure);
		// Do not pass malformed or attacker-crafted session identifiers to PHP's
		// session module. Besides array-valued Cookie headers, reject characters and
		// lengths outside PHP's supported file-session identifier alphabet.
		if (isset($_COOKIE[$sessionName]) && !self::validSessionId($_COOKIE[$sessionName])) {
			unset($_COOKIE[$sessionName]);
		}
		session_name($sessionName);

		// Start session.
		self::$started = session_start();

		// When a site moves from HTTP to HTTPS, an old unprefixed session cookie can
		// remain in the browser. It is ignored by PHP on HTTPS but used to confuse
		// lightweight Session::hasCookie() checks in public comment/auth helpers. Expire it
		// once the secure session has started.
		if (self::$started && $secure) {
			$legacyName = self::cookieName(false);
			if (isset($_COOKIE[$legacyName])) {
				unset($_COOKIE[$legacyName]);
				if (!headers_sent()) {
					setcookie($legacyName, '', array(
						'expires' => time() - 3600, 'path' => $path, 'domain' => '',
						'secure' => true, 'httponly' => true, 'samesite' => 'Strict'
					));
				}
			}
		}

		if (!self::$started) {
			Log::set(__METHOD__.LOG_SEP.'Error occurred when trying to start the session.');
		}
		return self::$started;
	}

	public static function started()
	{
		return self::$started;
	}

	public static function usesIsolatedSavePath()
	{
		return self::$isolatedSavePath;
	}

	public static function destroy()
	{
		$params = session_get_cookie_params();
		$currentName = session_name();
		$_SESSION = array();
		$destroyed = true;
		if (session_status() === PHP_SESSION_ACTIVE) {
			$destroyed = session_destroy();
		}
		unset($_SESSION);

		// Clear the current cookie and, on HTTPS, the legacy alternate name too.
		// This prevents stale pre-migration cookies from influencing hasCookie().
		$names = array_values(array_unique(array($currentName, self::cookieName(false), self::cookieName(true))));
		foreach ($names as $name) unset($_COOKIE[$name]);
		if (!headers_sent()) {
			foreach ($names as $name) {
				$isSecurePrefix = strpos($name, '__Secure-') === 0;
				// A __Secure- cookie may only be modified over a secure response.
				if ($isSecurePrefix && empty($params['secure'])) continue;
				setcookie($name, '', array(
					'expires' => time() - 3600,
					'path' => isset($params['path']) && is_string($params['path']) ? $params['path'] : '/',
					'domain' => isset($params['domain']) && is_string($params['domain']) ? $params['domain'] : '',
					'secure' => $isSecurePrefix ? true : !empty($params['secure']),
					'httponly' => true,
					'samesite' => 'Strict'
				));
			}
		}
		self::$started = false;
		if (!$destroyed) {
			Log::set(__METHOD__.LOG_SEP.'PHP could not destroy the server-side session.', LOG_TYPE_ERROR);
			return false;
		}
		Log::set(__METHOD__.LOG_SEP.'Session destroyed.');
		return true;
	}

	public static function set($key, $value)
	{
		if (!self::$started || !is_string($key) || $key === '') return false;
		$key = 's_'.$key;

		$_SESSION[$key] = $value;
		return true;
	}

	public static function get($key)
	{
		if (!self::$started || !is_string($key) || $key === '') return false;
		$key = 's_'.$key;

		if (isset($_SESSION[$key])) {
			return $_SESSION[$key];
		}
		return false;
	}

	public static function remove($key)
	{
		if (!self::$started || !is_string($key) || $key === '') return false;
		$key = 's_'.$key;

		unset($_SESSION[$key]);
		return true;
	}
}
