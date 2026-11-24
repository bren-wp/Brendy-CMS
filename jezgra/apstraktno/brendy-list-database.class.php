<?php defined('BRENDY') or die('Brendy CMS.');

/*
Database structure

{
	"videos": {
		"name": "Videos",
		"template: "",
		"description: "",
		"list": [ "my-page", "second-page" ]
	},
	"pets": {
		"name": "Pets",
		"template: "",
		"description: "",
		"list": [ "cats-and-dogs" ]
	}
}
*/

class BrendyListDatabase extends BrendyJsonDatabase
{
	public $db = array();

	function __construct($file)
	{
		parent::__construct($file);
	}

	public function keys()
	{
		return array_keys($this->db);
	}

	// Returns the list of keys filter by pageNumber
	// $pageNumber start in 1
	public function getList($key, $pageNumber, $numberOfItems)
	{
		if (!is_string($key) || !isset($this->db[$key]) || !is_array($this->db[$key]) ||
			!isset($this->db[$key]['list']) || !is_array($this->db[$key]['list'])) {
			Log::set(__METHOD__.LOG_SEP.'Error key does not exist '.$key);
			return false;
		}

		return $this->paginateListItems($this->db[$key]['list'], $pageNumber, $numberOfItems);
	}

	// Shared bounded pagination for derived list databases. Subclasses can first
	// validate their cached keys against the canonical pages DB, then reuse the
	// exact same pagination contract without copying arithmetic/validation logic.
	protected function paginateListItems($list, $pageNumber, $numberOfItems)
	{
		if (!is_array($list) || count($list) > 100000) return false;
		$pageNumber = filter_var($pageNumber, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 1000000)));
		$numberOfItems = filter_var($numberOfItems, FILTER_VALIDATE_INT);
		if ($pageNumber === false || $numberOfItems === false) return false;
		if ($numberOfItems === -1) return array_values($list);
		if ($numberOfItems < 1 || $numberOfItems > 10000) return false;

		$offset = ($pageNumber - 1) * $numberOfItems;
		if ($offset >= count($list)) return $pageNumber === 1 ? array() : false;
		return array_slice(array_values($list), $offset, $numberOfItems);
	}

	/**
	 * Validate a derived taxonomy list against canonical page state. Category and
	 * tag databases are indexes, not authorities; stale membership must never
	 * expose a drafted/moved page or corrupt public paginator totals.
	 */
	protected function canonicalPublicList($key, $membershipField)
	{
		global $pages;
		if (!is_string($key) || !in_array($membershipField, array('category', 'tags'), true) ||
			!isset($this->db[$key]) || !is_array($this->db[$key]) ||
			!isset($this->db[$key]['list']) || !is_array($this->db[$key]['list']) ||
			!is_object($pages) || !method_exists($pages, 'isPubliclyAccessible') ||
			!method_exists($pages, 'getPageDB')) return false;

		$list = array();
		$seen = array();
		foreach (array_slice($this->db[$key]['list'], 0, 100000) as $pageKey) {
			if (!is_string($pageKey) || $pageKey === '' || strlen($pageKey) > 200 || isset($seen[$pageKey]) ||
				!$pages->isPubliclyAccessible($pageKey)) continue;
			$row = $pages->getPageDB($pageKey);
			if (!is_array($row)) continue;
			$matches = $membershipField === 'category'
				? (isset($row['category']) && is_string($row['category']) && $row['category'] === $key)
				: (isset($row['tags']) && is_array($row['tags']) && array_key_exists($key, $row['tags']));
			if (!$matches) continue;
			$seen[$pageKey] = true;
			$list[] = $pageKey;
		}
		return $list;
	}

	// Derived index pagination shares the same validation and failure contract.
	protected function paginateCanonicalPublicList($key, $membershipField, $pageNumber, $numberOfItems)
	{
		$list = $this->canonicalPublicList($key, $membershipField);
		if ($list === false) return false;
		return $this->paginateListItems($list, $pageNumber, $numberOfItems);
	}

	public function generateKey($name)
	{
		global $L;
		if (!is_string($name) || strlen($name) > 500) return false;

		$key = Text::cleanUrl($name);
		if (Text::isEmpty($key)) {
			$key = Text::cleanUrl($L->g('empty'));
		}
		$base = mb_strcut($key, 0, 180, CHARSET);
		if ($base === '') return false;
		$key = $base;
		if (isset($this->db[$key])) {
			$found = false;
			for ($suffix = 0; $suffix < 100000; $suffix++) {
				$candidate = $base . '-' . $suffix;
				if (!isset($this->db[$candidate])) {
					$key = $candidate;
					$found = true;
					break;
				}
			}
			if (!$found) return false;
		}
		return $key;
	}

	// Add a new item to the dblist
	// $args => 'name', 'template', 'description', 'list'
	public function add($args)
	{
		if (!is_array($args) || !isset($args['name']) || !is_string($args['name']) ||
			(isset($args['template']) && !is_string($args['template'])) ||
			(isset($args['description']) && !is_string($args['description'])) ||
			(isset($args['list']) && !is_array($args['list'])) || strlen($args['name']) > 500 ||
			(isset($args['template']) && strlen($args['template']) > 200) ||
			(isset($args['description']) && strlen($args['description']) > 5000) ||
			(isset($args['list']) && count($args['list']) > 100000)) {
			return false;
		}
		if (isset($args['list'])) {
			foreach ($args['list'] as $item) if (!is_string($item) || strlen($item) > 200) return false;
		}
		$key = $this->generateKey($args['name']);
		if ($key === false) return false;

		$before = $this->db;
		$this->db[$key]['name'] 	= Sanitize::removeTags($args['name']);
		$this->db[$key]['template'] 	= isset($args['template'])?Sanitize::removeTags($args['template']):'';
		$this->db[$key]['description'] 	= isset($args['description'])?Sanitize::removeTags($args['description']):'';
		$this->db[$key]['list'] 	= isset($args['list'])?$args['list']:array();

		$this->sortAlphanumeric();
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return $key;
	}

	public function remove($key)
	{
		if (!is_string($key) || !isset($this->db[$key])) {
			Log::set(__METHOD__.LOG_SEP.'The key does not exist, key: '.$key);
			return false;
		}

		$before = $this->db;
		unset($this->db[$key]);
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return true;
	}

	// Edit an item to the dblist
	// $args => 'name', 'oldkey', 'newKey', 'template', 'description'
	public function edit($args)
	{
		if (!is_array($args) || !isset($args['name'], $args['oldKey'], $args['newKey']) ||
			!is_string($args['name']) || !is_string($args['oldKey']) || !is_string($args['newKey']) ||
			!isset($this->db[$args['oldKey']]) || !is_array($this->db[$args['oldKey']]) ||
			!isset($this->db[$args['oldKey']]['list']) || !is_array($this->db[$args['oldKey']]['list']) ||
			(isset($args['template']) && !is_string($args['template'])) ||
			(isset($args['description']) && !is_string($args['description'])) || strlen($args['name']) > 500 ||
			(isset($args['template']) && strlen($args['template']) > 200) ||
			(isset($args['description']) && strlen($args['description']) > 5000)) {
			return false;
		}
		$args['newKey'] = mb_substr(Text::cleanUrl($args['newKey']), 0, 200, CHARSET);
		if ($args['newKey'] === '') return false;
		if (isset($this->db[$args['newKey']]) && ($args['newKey'] !== $args['oldKey'])) {
			Log::set(__METHOD__.LOG_SEP.'The new key already exists. Key: '.$args['newKey'], LOG_TYPE_WARN);
			return false;
		}

		$before = $this->db;
		$this->db[$args['newKey']]['name'] 	= Sanitize::removeTags($args['name']);
		$this->db[$args['newKey']]['template'] 	= isset($args['template'])?Sanitize::removeTags($args['template']):'';
		$this->db[$args['newKey']]['description'] = isset($args['description'])?Sanitize::removeTags($args['description']):'';
		$this->db[$args['newKey']]['list'] 	= $this->db[$args['oldKey']]['list'];

		// Remove the old category
		if ($args['oldKey'] !== $args['newKey']) {
			unset( $this->db[$args['oldKey']] );
		}

		$this->sortAlphanumeric();
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return $args['newKey'];
	}

	// Sort the categories by "Natural order"
	public function sortAlphanumeric()
	{
		// Sort key alphanumeric strings, a01, a10, b10, c02
		return ksort($this->db);
	}

	// Returns the name associated to the key, FALSE if the key doesn't exist
	public function getName($key)
	{
		if (is_string($key) && isset($this->db[$key]) && is_array($this->db[$key]) &&
			isset($this->db[$key]['name']) && is_string($this->db[$key]['name'])) {
			return $this->db[$key]['name'];
		}
		return false;
	}

	// Returns an array with key=>name of the list
	public function getKeyNameArray()
	{
		$tmp = array();
		foreach($this->db as $key=>$fields) {
			if (is_string($key) && is_array($fields) && isset($fields['name']) && is_string($fields['name'])) {
				$tmp[$key] = $fields['name'];
			}
		}
		return $tmp;
	}

	// Returns the number of items in the list
	public function countItems($key)
	{
		if (is_string($key) && isset($this->db[$key]) && is_array($this->db[$key]) &&
			isset($this->db[$key]['list']) && is_array($this->db[$key]['list'])) {
			return count($this->db[$key]['list']);
		}
		return 0;
	}

	public function exists($key)
	{
		return $this->hasArrayRow($key);
	}

	public function existsName($name)
	{
		if (!is_string($name)) return false;
		foreach ($this->db as $key=>$fields) {
			if (is_array($fields) && isset($fields['name']) && is_string($fields['name']) && $name === $fields['name']) {
				return true;
			}
		}
		return false;
	}

	// Returns an array with a portion of the database filtered by key
	// Returns array( 'key'=>'', 'name'=>'', 'template'=>'', 'description'=>'', list'=>array() )
	public function getMap($key)
	{
		if (is_string($key) && isset($this->db[$key]) && is_array($this->db[$key])) {
			$tmp = $this->db[$key];
			// Use the public virtual list reader instead of returning the raw derived
			// JSON membership. Categories/Tags override getList() to validate every
			// page against canonical state, so API/maps cannot expose stale keys.
			$list = $this->getList($key, 1, -1);
			$tmp['list'] = is_array($list) ? $list : array();
			$tmp['key'] = $key;
			return $tmp;
		}
		return false;
	}

}
