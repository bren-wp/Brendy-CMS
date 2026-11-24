<?php defined('BRENDY') or die('Brendy CMS.');

/**
 * Shared immutable view of a taxonomy record. Category and Tag used to carry
 * separate copies of the same accessors/JSON logic; keeping that contract here
 * prevents the two models from drifting while preserving their public API.
 */
abstract class BrendyTaxonomyItem
{
	protected $vars = array();

	protected function hydrateTaxonomy($key, $database, $domain, $label, $extraFields = array())
	{
		if (!is_string($key) || $key === '' || strlen($key) > 255 || strpos($key, "\0") !== false ||
			!is_object($database) || !isset($database->db) || !is_array($database->db) ||
			!array_key_exists($key, $database->db) || !is_array($database->db[$key]) ||
			!is_string($domain) || !is_string($label)) {
			$errorMessage = $label . ' not found in database by key [' . (is_scalar($key) ? (string)$key : 'invalid') . ']';
			Log::set(static::class . LOG_SEP . $errorMessage);
			throw new InvalidArgumentException($errorMessage);
		}

		$row = $database->db[$key];
		$name = isset($row['name']) && is_scalar($row['name']) ? (string)$row['name'] : '';
		$list = isset($row['list']) && is_array($row['list']) ? $row['list'] : array();
		if (method_exists($database, 'getList')) {
			$currentList = $database->getList($key, 1, -1);
			$list = is_array($currentList) ? $currentList : array();
		}
		$this->vars = array(
			'key' => $key,
			'name' => $name,
			'permalink' => $domain . $key,
			'list' => $list
		);
		foreach ($extraFields as $field => $defaultValue) {
			if (!is_string($field) || $field === '' || strlen($field) > 64) continue;
			$value = array_key_exists($field, $row) ? $row[$field] : $defaultValue;
			if (is_string($defaultValue)) $value = is_scalar($value) ? (string)$value : $defaultValue;
			$this->vars[$field] = $value;
		}
	}

	public function getValue($field)
	{
		return is_string($field) && array_key_exists($field, $this->vars) ? $this->vars[$field] : false;
	}

	public function key() { return $this->getValue('key'); }
	public function name() { return $this->getValue('name'); }
	public function permalink() { return $this->getValue('permalink'); }
	public function pages() { return $this->getValue('list'); }

	protected function jsonFields()
	{
		return array(
			'key' => $this->key(),
			'name' => $this->name(),
			'permalink' => $this->permalink(),
			'pages' => $this->pages()
		);
	}

	public function json($returnsArray = false)
	{
		$data = $this->jsonFields();
		if ($returnsArray) return $data;
		$json = json_encode($data);
		return is_string($json) ? $json : '{}';
	}
}
