<?php defined('BRENDY') or die('Brendy CMS.');
requireAjaxMethod('GET');
requireAjaxRole(array('admin', 'editor', 'author'));

// $_GET
// ----------------------------------------------------------------------------
// (string) $_GET['query']
$query = isset($_GET['query']) && is_string($_GET['query']) ? Text::lowercase(trim($_GET['query'])) : false;
// ----------------------------------------------------------------------------
if ($query === false || Text::length($query) < 1 || Text::length($query) > 100) {
	ajaxResponse(1, $L->g('Invalid query'));
}

$result = array();

// MENU
if (Text::stringContains(Text::lowercase($L->g('New content')), $query)) {
	$tmp = array('disabled'=>true, 'icon'=>'plus-circle', 'type'=>'menu');
	$tmp['text'] = $L->g('New content');
	$tmp['url'] = HTML_PATH_ADMIN_ROOT.'new-content';
	array_push($result, $tmp);
}
if (checkRole(array('admin'), false) && Text::stringContains(Text::lowercase($L->g('New category')), $query)) {
	$tmp = array('disabled'=>true, 'icon'=>'tag', 'type'=>'menu');
	$tmp['text'] = $L->g('New category');
	$tmp['url'] = HTML_PATH_ADMIN_ROOT.'new-category';
	array_push($result, $tmp);
}
if (checkRole(array('admin'), false) && Text::stringContains(Text::lowercase($L->g('New user')), $query)) {
	$tmp = array('disabled'=>true, 'icon'=>'person', 'type'=>'menu');
	$tmp['text'] = $L->g('New user');
	$tmp['url'] = HTML_PATH_ADMIN_ROOT.'new-user';
	array_push($result, $tmp);
}
if (checkRole(array('admin'), false) && Text::stringContains(Text::lowercase($L->g('Categories')), $query)) {
	$tmp = array('disabled'=>true, 'icon'=>'tags', 'type'=>'menu');
	$tmp['text'] = $L->g('Categories');
	$tmp['url'] = HTML_PATH_ADMIN_ROOT.'categories';
	array_push($result, $tmp);
}
if (checkRole(array('admin'), false) && Text::stringContains(Text::lowercase($L->g('Users')), $query)) {
	$tmp = array('disabled'=>true, 'icon'=>'people', 'type'=>'menu');
	$tmp['text'] = $L->g('Users');
	$tmp['url'] = HTML_PATH_ADMIN_ROOT.'users';
	array_push($result, $tmp);
}


// PAGES
$pagesKey = $pages->getDB();
foreach ($pagesKey as $pageKey) {
	if (count($result) >= 100) break;
	try {
		$page = new Page($pageKey);
		if (checkRole(array('author'), false) && $page->username() !== $login->username()) {
			continue;
		}
		$lowerTitle = Text::lowercase($page->title());
		if (Text::stringContains($lowerTitle, $query)) {
			$tmp = array('disabled'=>true);
			$tmp['id'] = $page->key();
			$tmp['text'] = $page->title();
			$tmp['type'] = $page->type();
			array_push($result, $tmp);
		}
	} catch (Throwable $e) {
		// continue
	}
}

sendJsonResponse(array('results' => $result), 200, '{"results":[]}');

?>
