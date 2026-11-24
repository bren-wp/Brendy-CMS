<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Check role
// ============================================================================

if (checkRole(array('author'), false)) {
	try {
		$pageKey = isset($_POST['key']) ? $_POST['key'] : $layout['parameters'];
		if (!is_string($pageKey) || $pageKey === '') {
			throw new Exception('Invalid page key.');
		}
		$page = new Page($pageKey);
	} catch (Exception $e) {
		Alert::set($L->g('You do not have sufficient permissions'));
		Redirect::page('dashboard');
	}

	if ($page->username()!==$login->username()) {
		// Add to syslog
		$syslog->add(array(
			'dictionaryKey'=>'access-denied',
			'notes'=>$login->username()
		));

		Alert::set($L->g('You do not have sufficient permissions'));
		Redirect::page('dashboard');
	}
}

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
	$postType = isset($_POST['type']) && is_string($_POST['type']) ? $_POST['type'] : '';
	if ($postType === 'restore-revision') {
		$key = isset($_POST['key']) && is_string($_POST['key']) ? $_POST['key'] : '';
		$revisionId = isset($_POST['revisionId']) && is_string($_POST['revisionId']) ? $_POST['revisionId'] : '';
		$result = restorePageRevision($key, $revisionId);
		if ($result !== false) {
			Alert::set($L->g('Revision restored'));
			$resultPath = brendyPageKeyUrlPath($result);
			Redirect::page($resultPath === false ? 'content' : 'edit-content/' . $resultPath);
		}
		if (!Alert::defined()) Alert::set($L->g('Revision could not be restored'), ALERT_STATUS_FAIL);
		$keyPath = brendyPageKeyUrlPath($key);
		Redirect::page($pages->exists($key) && $keyPath !== false ? 'edit-content/' . $keyPath : 'content');
	}
	if ($postType === 'delete') {
		if (!isset($_POST['key']) || !is_string($_POST['key']) || $_POST['key'] === '') {
			Alert::set($L->g('Invalid request'), ALERT_STATUS_FAIL);
			Redirect::page('content');
		}
		// Get the page type before deleting to redirect to the correct tab
		if (empty($_POST['tab'])) {
			try {
				$pageToDelete = new Page($_POST['key']);
				$pageType = $pageToDelete->type();
				if ($pageType === 'autosave') {
					$_POST['tab'] = 'autosave';
				} elseif ($pageType === 'draft') {
					$_POST['tab'] = 'draft';
				} elseif ($pageType === 'scheduled') {
					$_POST['tab'] = 'scheduled';
				} elseif ($pageType === 'static') {
					$_POST['tab'] = 'static';
				} else {
					$_POST['tab'] = 'pages';
				}
			} catch (Throwable $e) {
				$_POST['tab'] = 'pages';
			}
		}

		if (deletePage($_POST['key'])) {
			Alert::set($L->g('The changes have been saved'));
		} elseif (!Alert::defined()) {
			Alert::set($L->g('The changes could not be saved'), ALERT_STATUS_FAIL);
		}
	} else {
		if (array_key_exists('date', $_POST)) {
			$normalizedDate = Date::fromLocalInput($_POST['date']);
			if ($normalizedDate === false) {
				Alert::set($L->g('Invalid request'), ALERT_STATUS_FAIL);
				Redirect::page('content');
			}
			$_POST['date'] = $normalizedDate;
		}
		$key = editPage($_POST);
		if ($key !== false) {
			Alert::set($L->g('The changes have been saved'));
			$keyPath = brendyPageKeyUrlPath($key);
			Redirect::page($keyPath === false ? 'content' : 'edit-content/' . $keyPath);
		}
		if (!Alert::defined()) {
			Alert::set($L->g('The changes could not be saved'), ALERT_STATUS_FAIL);
		}
	}

	if (isset($_POST['tab']) && is_string($_POST['tab'])) {
		$tab = strtolower(trim($_POST['tab']));
		if (in_array($tab, array('pages', 'static', 'scheduled', 'draft', 'autosave'), true)) {
			Redirect::page('content#' . $tab);
		}
	}
	Redirect::page('content');
}

// ============================================================================
// Main after POST
// ============================================================================
try {
	$pageKey = $layout['parameters'];
	$page = new Page($pageKey);
} catch (Throwable $e) {
	Log::set(__METHOD__.LOG_SEP.'Error occurred when trying to get the page: '.$pageKey, LOG_TYPE_ERROR);
	Redirect::page('content');
}

if (!definePageMediaConstants($page->uuid())) {
	Log::set(__METHOD__ . LOG_SEP . 'Invalid media workspace for content', LOG_TYPE_ERROR);
	Alert::set($L->g('Unable to prepare a secure content workspace'), ALERT_STATUS_FAIL);
	Redirect::page('content');
}

// Title of the page
$layout['title'] .= ' - '.$L->g('Edit content').' - '.$page->title();
