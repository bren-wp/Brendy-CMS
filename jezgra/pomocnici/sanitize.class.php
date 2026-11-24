<?php defined('BRENDY') or die('Brendy CMS.');

class Sanitize {

	public static function removeTags($text) {
		return is_scalar($text) ? strip_tags((string)$text) : '';
	}

	// Convert special characters to HTML entities
	public static function html($text)
	{
		$flags = ENT_QUOTES | ENT_SUBSTITUTE;

		if (defined('ENT_HTML5')) {
			$flags |= ENT_HTML5;
		}

		return htmlspecialchars(is_scalar($text) ? (string)$text : '', $flags, CHARSET);
	}

	// Convert special HTML entities back to characters
	public static function htmlDecode($text)
	{
		$flags = ENT_QUOTES;

		if(defined('ENT_HTML5')) {
			$flags |= ENT_HTML5;
		}

		return htmlspecialchars_decode(is_scalar($text) ? (string)$text : '', $flags);
	}

	public static function pathFile($path, $file=false)
	{
		if (!is_string($path) || ($file !== false && !is_string($file)) ||
			strpos($path, "\0") !== false || ($file !== false && strpos($file, "\0") !== false)) {
			return false;
		}
		if ($file!==false){
			$fullPath = $path.$file;
		} else {
			$fullPath = $path;
			$normalized = str_replace('\\', '/', $fullPath);
			if (preg_match('#(^|/)\.\.(/|$)#', $normalized)) {
				return false;
			}
		}

		// Fix for Windows on paths. eg: $path = c:\diego/page/subpage convert to c:\diego\page\subpages
		$fullPath = str_replace('/', DS, $fullPath);

		$real = realpath($fullPath);

		// If $real is FALSE the file does not exist.
		if ($real===false) {
			return false;
		}

		// Resolve the base directory to validate against path traversal.
		$basePath = ($file === false) ? $real : realpath($path);
		if ($basePath===false) {
			return false;
		}

		// If the resolved path does not start with the base directory then this is Path Traversal.
		if ($real !== $basePath && strpos($real, $basePath . DS) !== 0) {
			return false;
		}

		return true;
	}

	// Returns the email without illegal characters.
	public static function email($email)
	{
		return( filter_var($email, FILTER_SANITIZE_EMAIL) );
	}

	public static function url($url)
	{
		return( filter_var($url, FILTER_SANITIZE_URL) );
	}

	public static function int($value)
	{
		$value = (int)$value;

		if($value>=0)
			return $value;
		else
			return 0;
	}

}
