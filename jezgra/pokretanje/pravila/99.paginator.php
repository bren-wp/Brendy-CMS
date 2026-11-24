<?php defined('BRENDY') or die('Brendy CMS.');

// Current page number
$currentPage = $url->pageNumber();
$whereAmI = $url->whereAmI();
Paginator::set('currentPage', $currentPage);

if ($whereAmI === 'admin') {
	$itemsPerPage = ITEMS_PER_PAGE_ADMIN;
	if (isset($layout['slug']) && $layout['slug'] === 'content' && checkRole(array('author'), false)) {
		$numberOfItems = $pages->countByType(true, false, false, false, false, false, $login->username());
	} else {
		$numberOfItems = $pages->count(true);
	}
} elseif ($whereAmI === 'page' || ($whereAmI === 'home' && is_object($page) && $site->homepage() === $page->key())) {
	$itemsPerPage = 1;
	$numberOfItems = 1;
} elseif ($whereAmI === 'tag') {
	$itemsPerPage = $site->itemsPerPage();
	$tagKey = $url->slug();
	$numberOfItems = $tags->numberOfPages($tagKey);
} elseif ($whereAmI === 'category') {
	$itemsPerPage = $site->itemsPerPage();
	$categoryKey = $url->slug();
	$numberOfItems = $categories->numberOfPages($categoryKey);
} else {
	$itemsPerPage = $site->itemsPerPage();
	$numberOfItems = $pages->countPublic(true);
}

// Execute hook from plugins
Theme::plugins('paginator');

// Normalize paginator inputs before any arithmetic.
$itemsPerPage = max(1, (int)$itemsPerPage);
$numberOfItems = max(0, (int)$numberOfItems);
Paginator::set('itemsPerPage', $itemsPerPage);
Paginator::set('numberOfItems', $numberOfItems);

// Amount of pages
$numberOfPages = (int) max(ceil($numberOfItems / $itemsPerPage), 1);
Paginator::set('numberOfPages', $numberOfPages);

// Out-of-range pagination is a real 404, not an empty duplicate page.
if ($whereAmI !== 'admin' && $currentPage > $numberOfPages) {
	$url->setNotFound();
	$content = array();
	$content[0] = $page = buildErrorPage();
}

// TRUE if exists a next page to show
$showNext = $numberOfPages > $currentPage;
Paginator::set('showNext', $showNext);

// TRUE if exists a previous page to show
$showPrev = $currentPage > Paginator::firstPage();
Paginator::set('showPrev', $showPrev);

// TRUE if exists a next and previous page to show
$showNextPrev = $showNext && $showPrev;
Paginator::set('showNextPrev', $showNextPrev);

// Integer with the next page
$nextPage = min($numberOfPages, $currentPage + 1);
Paginator::set('nextPage', $nextPage);

// Integer with the previous page
$prevPage = max(Paginator::firstPage(), $currentPage - 1);
Paginator::set('prevPage', $prevPage);
