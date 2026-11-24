<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Functions
// ============================================================================
function updateBrendy() {
	global $site;
	global $syslog;
	global $L;

	// Repair or create defense-in-depth files on upgrades and after content
	// replacement. Do not mark a migration complete if this protection fails.
	if (!protectContentDirectories()) {
		Log::set('UPDATE SYSTEM - Unable to protect content directories.', LOG_TYPE_ERROR);
		Alert::set($L->g('Unable to protect the content directories'), ALERT_STATUS_FAIL);
		return false;
	}

	// Apply required migrations only when the stored build is older. A GET
	// parameter must never trigger a state-changing update.
	$currentBuild = (int)$site->currentBuild();
	if ($currentBuild < (int)BRENDY_BUILD) {
		Log::set('UPDATE SYSTEM - Starting.');

		// Brendy 1.4+ migracije žive u klasama koje posjeduju podatke.
		// Ovdje se više ne izvršavaju stare upstream migracije iz 2018. koje
		// mogu promijeniti postavke svježe instalacije.
		if (!migratePageContentHashes()) {
			Log::set('UPDATE SYSTEM - Unable to migrate page content hashes.', LOG_TYPE_ERROR);
			Alert::set($L->g('The system update could not be completed'), ALERT_STATUS_FAIL);
			return false;
		}

		if (!brendyMigrateRetiredStaticPagesToNavigation()) {
			Log::set('UPDATE SYSTEM - Unable to migrate Static Pages settings into Navigation.', LOG_TYPE_ERROR);
			Alert::set($L->g('The system update could not be completed'), ALERT_STATUS_FAIL);
			return false;
		}

		if (!brendyCleanupRetiredBundledPluginResidue()) {
			// Runtime remains safe because the plugin loader denylist blocks these
			// directories even when filesystem permissions prevent physical cleanup.
			Log::set('UPDATE SYSTEM - Retired plugin residue could not be fully cleaned.', LOG_TYPE_WARN);
		}

		// Rebuild compatibility media aliases with the hardened nested-page resolver.
		// UUID upload directories are canonical, so lack of symlink support is a
		// warning rather than a reason to abort an otherwise valid core upgrade.
		global $pages;
		$aliasStats = array();
		if (!isset($pages) || !is_object($pages) || !brendyRebuildPageUploadAliases($pages->db, $aliasStats)) {
			Log::set('UPDATE SYSTEM - Page upload aliases could not be fully rebuilt.', LOG_TYPE_WARN);
		}

		// Refresh the authenticated core-code baseline only while crossing a known
		// release boundary. System Health verifies it later but never auto-repairs
		// a mismatch during normal requests.
		if (!writeBrendyCoreIntegrityBaseline()) {
			Log::set('UPDATE SYSTEM - Unable to create core integrity baseline.', LOG_TYPE_ERROR);
			Alert::set($L->g('The system update could not be completed'), ALERT_STATUS_FAIL);
			return false;
		}

		// Set the current build number only after all required migrations succeed.
		if (!$site->set(array('currentBuild'=>(int)BRENDY_BUILD))) {
			Log::set('UPDATE SYSTEM - Unable to persist the build number.', LOG_TYPE_ERROR);
			Alert::set($L->g('The system update could not be completed'), ALERT_STATUS_FAIL);
			return false;
		}
		Log::set('UPDATE SYSTEM - Finished.');

		// Add to syslog
		$syslog->add(array(
			'dictionaryKey'=>'system-updated',
			'notes'=>BRENDY_RELEASE_NAME.' '.BRENDY_VERSION
		));
	}
	return true;
}

// ============================================================================
// Main before POST
// ============================================================================

// ============================================================================
// POST Method
// ============================================================================
if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'POST' &&
	isset($_POST['applySystemUpdate'])) {
	// Core migrations write files and JSON state, so they must never run on GET and
	// must remain inside the administrator + CSRF boundary. 99.security.php has
	// already validated the POST token before this controller is loaded.
	checkRole(array('admin'));
	updateBrendy();
	Redirect::page('dashboard');
}

// ============================================================================
// Main after POST
// ============================================================================
$updatePending = ((int)$site->currentBuild() < (int)BRENDY_BUILD);

// Title of the page
$layout['title'] .= ' - '.$L->g('Dashboard');
