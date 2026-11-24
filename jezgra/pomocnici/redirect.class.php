<?php defined('BRENDY') or die('Brendy CMS.');

class Redirect {

	public static function url($url, $httpCode=302)
	{
		if (!is_string($url) || $url === '' || strlen($url) > 8192 ||
			preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
			$url = defined('HTML_PATH_ROOT') ? HTML_PATH_ROOT : '/';
		}
		$safe = false;
		if ($url[0] === '/' && strpos($url, '//') !== 0) {
			$safe = true;
		} else {
			$parts = parse_url($url);
			$base = defined('DOMAIN_BASE') ? parse_url(DOMAIN_BASE) : false;
			if (is_array($parts) && is_array($base) && isset($parts['scheme'], $parts['host'], $base['scheme'], $base['host']) &&
				in_array(strtolower($parts['scheme']), array('http', 'https'), true) &&
				strtolower($parts['scheme']) === strtolower($base['scheme']) &&
				strtolower($parts['host']) === strtolower($base['host']) && !isset($parts['user']) && !isset($parts['pass'])) {
				$urlPort = isset($parts['port']) ? (int)$parts['port'] : (strtolower($parts['scheme']) === 'https' ? 443 : 80);
				$basePort = isset($base['port']) ? (int)$base['port'] : (strtolower($base['scheme']) === 'https' ? 443 : 80);
				$safe = $urlPort === $basePort;
			}
		}
		if (!$safe) {
			Log::set(__METHOD__ . LOG_SEP . 'Blocked unsafe redirect target.', LOG_TYPE_WARN);
			$url = defined('HTML_PATH_ROOT') ? HTML_PATH_ROOT : '/';
		}
		$httpCode = in_array((int)$httpCode, array(301, 302, 303, 307, 308), true) ? (int)$httpCode : 302;
		if (!headers_sent()) {
			header("Location: ".$url, TRUE, $httpCode);
			exit(0);
		}

		exit('<meta http-equiv="refresh" content="0; url='.htmlspecialchars($url, ENT_QUOTES, CHARSET).'"/>');
	}

	public static function page($page)
	{
		self::url(HTML_PATH_ADMIN_ROOT.$page);
	}

	public static function home()
	{
		self::url(HTML_PATH_ROOT);
	}

	public static function admin()
	{
		self::url(HTML_PATH_ADMIN_ROOT);
	}
}
