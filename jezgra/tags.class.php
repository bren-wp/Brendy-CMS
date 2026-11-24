<?php defined('BRENDY') or die('Brendy CMS.');

class Tags extends BrendyListDatabase {

	function __construct()
	{
		parent::__construct(DB_TAGS);
	}

	function numberOfPages($key)
	{
		$list = $this->currentPublicList($key);
		return is_array($list) ? count($list) : 0;
	}

	public function getList($key, $pageNumber, $numberOfItems)
	{
		return $this->paginateCanonicalPublicList($key, 'tags', $pageNumber, $numberOfItems);
	}

	// Tag JSON is a derived index; canonical page state remains authoritative.
	private function currentPublicList($key)
	{
		return $this->canonicalPublicList($key, 'tags');
	}

	public function reindex()
	{
		global $pages;
		$before = $this->db;
		$db = $pages->getDB(false);
		$tagsIndex = array();
		foreach ($db as $pageKey=>$pageFields) {
			if (is_string($pageKey) && is_array($pageFields) && isset($pageFields['type'], $pageFields['tags']) &&
				is_string($pageFields['type']) && is_array($pageFields['tags']) &&
				in_array($pageFields['type'], $GLOBALS['DB_TAGS_TYPES'], true) &&
				$pages->isPubliclyAccessible($pageKey)) {
				$tags = $pageFields['tags'];
				foreach (array_slice($tags, 0, 1000, true) as $tagKey=>$tagName) {
					if (!is_string($tagKey) || !is_string($tagName) || strlen($tagKey) > 200 || strlen($tagName) > 500) continue;
					if (isset($tagsIndex[$tagKey])) {
						array_push($tagsIndex[$tagKey]['list'], $pageKey);
					} else {
						$tagsIndex[$tagKey]['name'] = $tagName;
						$tagsIndex[$tagKey]['list'] = array($pageKey);
					}
				}
			}
		}

		$this->db = $tagsIndex;
		$this->sortAlphanumeric();
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return true;
	}

}
