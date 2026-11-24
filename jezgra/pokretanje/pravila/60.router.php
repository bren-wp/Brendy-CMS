<?php defined('BRENDY') or die('Brendy CMS.');

// Redirect admin, from /admin to /admin/
if ($url->uri() === HTML_PATH_ROOT . ADMIN_URI_FILTER) {
	Redirect::url(DOMAIN_ADMIN);
}

// Redirect the configured blog filter without its trailing slash. A hard-coded
// "blog" path broke custom and localized routes.
$filter = $url->filters('blog');
if (is_string($filter) && $site->homepage() !== false &&
	$url->uri() === rtrim(HTML_PATH_ROOT . ltrim($filter, '/'), '/')) {
	$finalURL = Text::addSlashes(DOMAIN_BASE.$filter, false, true);
	Redirect::url($finalURL);
}

// Redirect pages, from /my-page/ to /my-page
if ($url->whereAmI() === 'page' && !$url->notFound()) {
	$pageKey = $url->slug();
	if (Text::endsWith($pageKey, '/')) {
		$pageKey = rtrim($pageKey, '/');
		Redirect::url(DOMAIN_PAGES.$pageKey);
	}
}
