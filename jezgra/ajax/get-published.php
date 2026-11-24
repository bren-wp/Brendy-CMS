<?php defined('BRENDY') or die('Brendy CMS.');
requireAjaxMethod('GET');
requireAjaxRole(array('admin', 'editor', 'author'));

/*
| Returns a list of pages and the title contains the query string
| The returned list have published, sticky and statics pages
|
| @_GET['query']	string 	The string to search in the title of the pages
|
| @return	array
*/

// $_GET
// ----------------------------------------------------------------------------
// (string) $_GET['query']
$query = isset($_GET['query']) && is_string($_GET['query']) ? Text::lowercase(trim($_GET['query'])) : false;
// (boolean) $_GET['checkIsParent']
$checkIsParent = brendyBooleanParameter($_GET, 'checkIsParent', false);
// (string) $_GET['currentKey'] - supplied by the edit screen so the page and
// its descendants cannot be offered as their own parent.
$currentKey = isset($_GET['currentKey']) && is_string($_GET['currentKey']) ? $_GET['currentKey'] : '';
// ----------------------------------------------------------------------------
if ($query === false || Text::length($query) < 1 || Text::length($query) > 100 || $checkIsParent === null ||
	strlen($currentKey) > 200 || ($currentKey !== '' && !$pages->exists($currentKey))) {
	http_response_code(400);
	ajaxResponse(1, $L->g('Invalid query'));
}

$result = array();
$pagesKey = $pages->getDB();
$isAuthor = checkRole(array('author'), false);
foreach ($pagesKey as $pageKey) {
	if (!is_string($pageKey)) continue;
	try {
		$page = new Page($pageKey);

		if ($checkIsParent) {
			// Parent selection is an editing concern, not a public-content lookup.
			// Allow arbitrary hierarchy depth and draft/scheduled trees, but never
			// autosaves, the page itself or one of its descendants. Authors may only
			// attach content to a page they own (the mutation layer enforces this too).
			if ($page->autosave()) continue;
			if ($currentKey !== '' && ($pageKey === $currentKey || Text::startsWith($pageKey, $currentKey . '/'))) continue;
			if ($isAuthor && $page->username() !== $login->username()) continue;
		} else {
			// Homepage/404 selectors must not offer a nominally published child
			// hidden behind a draft/scheduled/missing ancestor.
			if (!$pages->isPubliclyAccessible($pageKey)) continue;
		}

		$lowerTitle = Text::lowercase($page->title());
		if (Text::stringContains($lowerTitle, $query)) {
			$tmp = array('disabled'=>false);
			$tmp['id'] = $page->key();
			$tmp['text'] = Sanitize::htmlDecode($page->title());
			$tmp['type'] = $page->type();
			$result[] = $tmp;
			if (count($result) >= 100) break;
		}
	} catch (Throwable $e) {
		// Corrupt rows are reported by System Health and are not offered in UI.
	}
}

sendJsonResponse(array('results' => $result), 200, '{"results":[]}');

?>
