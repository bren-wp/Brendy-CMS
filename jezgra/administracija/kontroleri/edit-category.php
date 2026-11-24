<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Check role
// ============================================================================

checkRole(array('admin'));

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
	$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
	$result = false;
	if ($action === 'delete') {
		$result = deleteCategory($_POST);
	} elseif ($action === 'edit') {
		$result = editCategory($_POST);
	} else {
		Alert::set($L->g('Invalid request'), ALERT_STATUS_FAIL);
		Redirect::page('categories');
	}

	if (!$result && !Alert::defined()) {
		Alert::set($L->g('The changes could not be saved'), ALERT_STATUS_FAIL);
	}
	Redirect::page('categories');
}

// ============================================================================
// Main after POST
// ============================================================================
$categoryKey = is_string($layout['parameters']) ? $layout['parameters'] : '';

if (!$categories->exists($categoryKey)) {
	Log::set(__METHOD__.LOG_SEP.'Error occurred when trying to get the category: '.$categoryKey);
	Redirect::page('categories');
}

$categoryMap = $categories->getMap($categoryKey);

// Title of the page
$layout['title'] .= ' - '.$L->g('Edit Category').' [ '.$categoryMap['name'] . ' ] ';
