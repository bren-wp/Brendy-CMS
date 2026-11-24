<?php defined('BRENDY') or die('Brendy CMS.');

class Cookie {

	private static function booleanOption($value, $default)
	{
		if (is_bool($value)) return $value;
		if (is_int($value) && ($value === 0 || $value === 1)) return (bool)$value;
		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) return true;
			if (in_array($normalized, array('0', 'false', 'no', 'off'), true)) return false;
		}
		return (bool)$default;
	}

	public static function get($key)
	{
		if (is_string($key) && isset($_COOKIE[$key]) && is_string($_COOKIE[$key])) {
			return $_COOKIE[$key];
		}
		return false;
	}

	public static function set($key, $value, $daysToExpire=30, $options=array())
	{
		if (!is_string($key) || $key === '' || preg_match('/[=,; \t\r\n\x0B\x0C]/', $key) ||
			!is_string($value) || !is_numeric($daysToExpire) || !is_array($options)) {
			return false;
		}
		$daysToExpire = max(-36500, min(36500, (int)$daysToExpire));
		$expire = time() + 60 * 60 * 24 * $daysToExpire;
		$secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
			(isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
		if (isset($GLOBALS['site']) && method_exists($GLOBALS['site'], 'isHTTPS')) {
			$secure = $secure || $GLOBALS['site']->isHTTPS();
		}

		$defaults = array(
			'expires'  => $expire,
			'path'     => defined('HTML_PATH_ROOT') ? HTML_PATH_ROOT : '/',
			'domain'   => '',
			'secure'   => $secure,
			'httponly'  => true,
			'samesite' => 'Lax'
		);

		$allowedOptions = array_intersect_key($options, $defaults);
		$finalOptions = array_merge($defaults, $allowedOptions);

		// setcookie() is strict about option types on PHP 8+. Normalize every
		// supported override so malformed internal/plugin input cannot surface as
		// a TypeError on an authentication path.
		$expires = isset($finalOptions['expires']) && is_scalar($finalOptions['expires'])
			? filter_var($finalOptions['expires'], FILTER_VALIDATE_INT) : false;
		$finalOptions['expires'] = ($expires !== false && $expires >= 0) ? (int)$expires : $expire;

		// Cookie attributes eventually become HTTP headers. Restrict caller
		// overrides to safe values even though current callers are internal.
		$path = isset($finalOptions['path']) && is_string($finalOptions['path']) ? $finalOptions['path'] : '/';
		if ($path === '' || $path[0] !== '/' || preg_match('/[\x00-\x20\x7F;,]/', $path)) $path = '/';
		$finalOptions['path'] = $path;
		$sameSite = isset($finalOptions['samesite']) && is_string($finalOptions['samesite'])
			? ucfirst(strtolower($finalOptions['samesite'])) : 'Lax';
		if (!in_array($sameSite, array('Lax', 'Strict', 'None'), true)) $sameSite = 'Lax';
		$finalOptions['samesite'] = $sameSite;
		$finalOptions['httponly'] = self::booleanOption($finalOptions['httponly'], true);
		$finalOptions['secure'] = self::booleanOption($finalOptions['secure'], $secure);
		if ($sameSite === 'None') $finalOptions['secure'] = true;
		$domain = isset($finalOptions['domain']) && is_string($finalOptions['domain']) ? $finalOptions['domain'] : '';
		if ($domain !== '' && (strlen($domain) > 253 || preg_match('/[\x00-\x20\x7F;,\/\\\\]/', $domain))) $domain = '';
		$finalOptions['domain'] = $domain;

		return setcookie($key, $value, $finalOptions);
	}

	public static function remove($key, $options=array())
	{
		if (!is_string($key) || $key === '' || !is_array($options)) return false;
		unset($_COOKIE[$key]);
		return self::set($key, '', -1, $options);
	}

	public static function isEmpty($key)
	{
		return !is_string($key) || !isset($_COOKIE[$key]) || !is_string($_COOKIE[$key]) || $_COOKIE[$key] === '';
	}
}
