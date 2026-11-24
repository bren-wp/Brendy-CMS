<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Check role
// ============================================================================

checkRole(array('admin', 'editor', 'author'));

// ============================================================================
// Functions
// ============================================================================

// Return only content owned by the current author. Malformed/stale keys are
// ignored rather than leaking warnings or metadata from another account.
function filterContentOwner($list) {
	global $login;
	global $pages;
	if (!is_array($list)) return array();
	$tmp = array();
	foreach ($list as $pageKey) {
		if (!is_string($pageKey) || !isset($pages->db[$pageKey]) || !is_array($pages->db[$pageKey])) continue;
		if (isset($pages->db[$pageKey]['username']) && is_string($pages->db[$pageKey]['username']) &&
			$pages->db[$pageKey]['username'] === $login->username()) {
			$tmp[] = $pageKey;
		}
	}
	return $tmp;
}

// ============================================================================
// Main before POST
// ============================================================================

// ============================================================================
// POST Method
// ============================================================================

// ============================================================================
// Main after POST
// ============================================================================

$isAuthor = checkRole(array('author'), false);
$published = $pages->getList(
	$url->pageNumber(), ITEMS_PER_PAGE_ADMIN,
	true, false, false, false, false, false,
	$isAuthor ? $login->username() : null
);
$drafts = $pages->getDraftDB(true);
$scheduled = $pages->getScheduledDB(true);
$static = $pages->getStaticDB(true);
$sticky = $pages->getStickyDB(true);
$autosave = $pages->getAutosaveDB(true);

// If the user is an Author filter every non-paginated content bucket as well,
// including autosaves. Published pages were already filtered before pagination.
if ($isAuthor) {
	$drafts = filterContentOwner($drafts);
	$scheduled = filterContentOwner($scheduled);
	$static = filterContentOwner($static);
	$sticky = filterContentOwner($sticky);
	$autosave = filterContentOwner($autosave);
}

// Check if out of range the pageNumber
if (empty($published) && $url->pageNumber()>1) {
	Redirect::page('content');
}

// Title of the page
$layout['title'] .= ' - '.$L->g('Manage content');