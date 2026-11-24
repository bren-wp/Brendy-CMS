<?php defined('BRENDY') or die('Brendy CMS.');

class Language extends BrendyJsonDatabase {
	public $data;
	public $db;
	public $currentLanguage;
	public $dates;
	public $unicodeChars;

	function __construct($currentLanguage)
	{
		$this->data = array();
		$this->db = array();
		$this->currentLanguage = is_string($currentLanguage) &&
			preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8})?$/D', $currentLanguage) === 1
			? $currentLanguage : 'en';
		$this->dates = array();
		$this->unicodeChars = array();

		// Load default language
		$filename = PATH_LANGUAGES.DEFAULT_LANGUAGE_FILE;
		if ($this->isSafeLanguageFile($filename)) {
			$Tmp = new BrendyJsonDatabase($filename, false, 2097152);
			$this->db = array_merge($this->db, $Tmp->db);
		}

		// If the user defined a new language replace the content of the default language
		// If the new dictionary has missing keys, fallback values are those of the default language
		$filename = PATH_LANGUAGES.$this->currentLanguage.'.json';
		if ($this->isSafeLanguageFile($filename) &&
			(DEFAULT_LANGUAGE_FILE !== $this->currentLanguage.'.json')) {
			$Tmp = new BrendyJsonDatabase($filename, false, 2097152);
			$this->db = array_merge($this->db, $Tmp->db);
		}

		// Language-data
		$this->data = isset($this->db['language-data']) && is_array($this->db['language-data'])
			? $this->db['language-data'] : array();
		unset($this->db['language-data']);

		// Dates
		if (isset($this->db['dates']) && is_array($this->db['dates'])) {
			$this->dates = $this->db['dates'];
		}
		unset($this->db['dates']);

		// Unicode chars
		if (isset($this->db['unicode-chars']) && is_array($this->db['unicode-chars'])) {
			$this->unicodeChars = $this->db['unicode-chars'];
		}
		unset($this->db['unicode-chars']);
		$this->dates = array_slice($this->dates, 0, 500, true);
		$this->unicodeChars = array_slice($this->unicodeChars, 0, 500, true);
		$this->db = $this->normalizeDictionary($this->db);
	}

	private function isSafeLanguageFile($filename)
	{
		$root = realpath(PATH_LANGUAGES);
		$realFile = is_string($filename) ? realpath($filename) : false;
		if ($root === false || $realFile === false || !is_file($filename) || is_link($filename) ||
			strpos($realFile, $root . DS) !== 0) return false;
		$size = @filesize($filename);
		return is_int($size) && $size >= 2 && $size <= 2097152;
	}

	private function normalizeDictionary($dictionary)
	{
		$result = array();
		if (!is_array($dictionary)) return $result;
		foreach (array_slice($dictionary, 0, 10000, true) as $key => $value) {
			if (!is_string($key) || strlen($key) > 200 || !is_scalar($value)) continue;
			$value = (string)$value;
			if (strlen($value) > 10000) continue;
			$result[$key] = $value;
		}
		return $result;
	}

	public function locale()
	{
		if (isset($this->data['locale']) && is_string($this->data['locale']) &&
			strlen($this->data['locale']) <= 64) {
			return $this->data['locale'];
		}

		return $this->currentLanguage;
	}

	public function currentLanguage()
	{
		return $this->currentLanguage;
	}

	public function currentLanguageShortVersion()
	{
		$current = (string)$this->currentLanguage;
		$explode = explode('_', $current);
		return $explode[0];
	}

	// Return the translation, if the translation doesn't exist returns the English translation
	public function get($string)
	{
		if (!is_scalar($string)) return '';
		$string = (string)$string;
		if (strlen($string) > 10000) return '';
		$key = Text::lowercase($string);
		$key = Text::replace(' ', '-', $key);
		$key = Text::replace('.', '', $key);


		if (isset($this->db[$key]) && is_string($this->db[$key])) {
			return $this->db[$key];
		}

		return $string;
	}

	// Returns translation
	public function g($string)
	{
		return $this->get($string);
	}

	// Print translation
	public function printMe($string)
	{
		echo $this->get($string);
	}

	// Print translation
	public function p($string)
	{
		echo $this->get($string);
	}

	// Add keys=>values to the current dicionary
	// This method don't overwrite the current value
	public function add($array)
	{
		if (!is_array($array)) return false;
		$this->db = array_merge($this->db, $this->normalizeDictionary($array));
		return true;
	}

	// Returns an array with all dictionaries
	public function getLanguageList()
	{
		$files = Filesystem::listFiles(PATH_LANGUAGES, '*', 'json');
		$tmp = array();
		foreach(array_slice($files, 0, 1000) as $file) {
			if (!$this->isSafeLanguageFile($file)) continue;
			$t = new BrendyJsonDatabase($file, false, 2097152);
			if (isset($t->db['language-data']['native']) && is_string($t->db['language-data']['native'])) {
				$native = $t->db['language-data']['native'];
				$locale = basename($file, '.json');
				$tmp[$locale] = $native;
			}
		}
		return $tmp;
	}

	// Returns array with all the dates and months
	public function getDates()
	{
		return $this->dates;
	}

	// Returns array with all the special characters from this language
	public function getunicodeChars()
	{
		return $this->unicodeChars;
	}
}
