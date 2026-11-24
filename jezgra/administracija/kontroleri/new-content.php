<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Check role
// ============================================================================

checkRole(array('admin', 'editor', 'author'));

// ============================================================================
// Functions
// ============================================================================

// ============================================================================
// Main before POST
// ============================================================================

// ============================================================================
// POST Method
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (array_key_exists('date', $_POST)) {
		$normalizedDate = Date::fromLocalInput($_POST['date']);
		if ($normalizedDate === false) {
			Alert::set($L->g('Invalid request'), ALERT_STATUS_FAIL);
			Redirect::page('content');
		}
		$_POST['date'] = $normalizedDate;
	}
	$result = createPage($_POST);
	if ($result !== false && isset($_POST['uuid']) && is_string($_POST['uuid'])) {
		consumeNewPageUuid($_POST['uuid']);
	}
	Redirect::page('content');
}

// ============================================================================
// Main after POST
// ============================================================================

// UUID of the page is need it for autosave and media manager
$uuid = $pages->generateUUID();
if (!registerNewPageUuid($uuid)) {
	Alert::set($L->g('Unable to prepare a secure content workspace'), ALERT_STATUS_FAIL);
	Redirect::page('content');
}

if (!definePageMediaConstants($uuid)) {
	consumeNewPageUuid($uuid);
	Alert::set($L->g('Unable to prepare a secure content workspace'), ALERT_STATUS_FAIL);
	Redirect::page('content');
}

// Title of the page
$layout['title'] = $L->g('New content').' - '.$layout['title'];
