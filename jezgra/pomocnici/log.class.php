<?php defined('BRENDY') or die('Brendy CMS.');

class Log {

	private static function requestPathForLog($uri)
	{
		if (!is_string($uri)) return '';
		$path = substr($uri, 0, strcspn($uri, '?#'));
		return preg_replace('/[\x00-\x1F\x7F]/', ' ', substr($path, 0, 2048));
	}

	public static function set($text, $type=LOG_TYPE_INFO)
	{
		if (!DEBUG_MODE) {
			return false;
		}

		$messageType = 0;
		if (!is_scalar($text)) {
			$text = json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
			if (!is_string($text)) $text = '[unprintable]';
		}
		$requestUri = self::requestPathForLog($_SERVER['REQUEST_URI'] ?? '');
		$text = preg_replace('/[\x00-\x1F\x7F]/', ' ', mb_substr((string)$text, 0, 10000, CHARSET));
		error_log((string)$type . ' [Brendy ' . BRENDY_VERSION . '] [' . $requestUri . '] ' . $text, $messageType);

		if (DEBUG_TYPE === 'TRACE') {
			error_log(print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20), true));
		}
	}

}
