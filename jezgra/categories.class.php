<?php defined('BRENDY') or die('Brendy CMS.');

class Categories extends BrendyListDatabase {

	function __construct()
	{
		parent::__construct(DB_CATEGORIES);
	}

	function numberOfPages($key)
	{
		$list = $this->currentPublicList($key);
		return is_array($list) ? count($list) : 0;
	}

	public function getList($key, $pageNumber, $numberOfItems)
	{
		return $this->paginateCanonicalPublicList($key, 'category', $pageNumber, $numberOfItems);
	}

	// Category JSON is a derived index; canonical page state remains authoritative.
	private function currentPublicList($key)
	{
		return $this->canonicalPublicList($key, 'category');
	}

	public function reindex()
	{
		global $pages;
		$before = $this->db;

		// Foreach category
		foreach ($this->db as $key=>$value) {
			if (is_string($key) && is_array($value)) $this->db[$key]['list'] = array();
		}

		// Get pages database
		$db = $pages->getDB(false);
		foreach ($db as $pageKey=>$pageFields) {
			if (is_string($pageKey) && is_array($pageFields) && isset($pageFields['category'], $pageFields['type']) &&
				is_string($pageFields['category']) && is_string($pageFields['type']) && $pageFields['category'] !== '') {
				$categoryKey = $pageFields['category'];
				if (isset($this->db[$categoryKey]['list']) &&
					in_array($pageFields['type'], array('published', 'sticky', 'static'), true) &&
					$pages->isPubliclyAccessible($pageKey)) {
					array_push($this->db[$categoryKey]['list'], $pageKey);
				}
			}
		}

		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return true;
	}
}
