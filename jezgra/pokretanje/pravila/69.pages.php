<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Variables
// ============================================================================

// Array with pages, each page is a Page Object
// Filtered by pagenumber, number of items per page and sorted by date/position
/*
	array(
		0 => Page Object,
		1 => Page Object,
		...
		N => Page Object
	)
*/
$content = array();

// Page filtered by the user, is a Page Object
$page = false;

// Array with static content, each item is a Page Object
// Order by position
/*
	array(
		0 => Page Object,
		1 => Page Object,
		...
		N => Page Object
	)
*/
$staticContent = $staticPages = buildStaticPages();

// ============================================================================
// Main
// ============================================================================

// Execute due publications through the same content-mutation transaction used
// by create/edit/delete operations, including derived taxonomy/plugin artifacts.
publishScheduledContent();

// Set the configured home page only while it is still publicly accessible.
// If an administrator later drafts/deletes/moves it behind a private ancestor,
// fall back to the regular home listing instead of rendering a blank/404 home.
$configuredHomepage = $site->homepage();
if ($configuredHomepage !== false && $url->whereAmI()==='home' && $pages->isPubliclyAccessible($configuredHomepage)) {
	$url->setSlug($configuredHomepage);
	$content[0] = $page = buildThePage();
}

// Build specific page
elseif ($url->whereAmI()==='page') {
	$content[0] = $page = buildThePage();
}
// Build content by tag
elseif ($url->whereAmI()==='tag') {
	$content = buildPagesByTag();
}
// Build content by category
elseif ($url->whereAmI()==='category') {
	$content = buildPagesByCategory();
}
// Build content for the homepage
elseif ( ($url->whereAmI()==='home') || ($url->whereAmI()==='blog') ) {
        $content = buildPagesForHome();
}

if (isset($content[0])) {
	$page = $content[0];
}

// If set notFound, create the page 404
if ($url->notFound()) {
	$content[0] = $page = buildErrorPage();
}
