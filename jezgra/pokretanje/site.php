<?php defined('BRENDY') or die('Brendy CMS.');

// Send baseline security headers before a plugin hook can emit or terminate a
// response. The rule runs again after routing to apply the final status code.
include(PATH_RULES.'99.header.php');

// Maintenance is a core recovery/operational state and must not depend on
// optional extension code being loadable. Admin requests use a separate
// bootstrap and remain available so an administrator can disable maintenance.
brendyServeMaintenanceMode();

// Load plugins only after the core recovery surface has had a chance to serve.
include(PATH_RULES.'60.plugins.php');

// Standard SEO endpoint owned by the core. Keep this after plugin discovery so
// the optional Sitemap plugin can be detected without duplicating its state.
brendyServeCoreRobotsTxt();

// Plugins before all
Theme::plugins('beforeAll');

// Load rules
include(PATH_RULES.'60.router.php');
include(PATH_RULES.'69.pages.php');
include(PATH_RULES.'99.paginator.php');
include(PATH_RULES.'99.header.php');
$robotsDirectives = Theme::robotsDirectives();
if (!empty($robotsDirectives) && empty($GLOBALS['BRENDY_PREVIEW_MODE']) && !headers_sent()) {
	header('X-Robots-Tag: ' . implode(', ', $robotsDirectives));
}
unset($robotsDirectives);
include(PATH_RULES.'99.themes.php');

// Plugins before site loaded
Theme::plugins('beforeSiteLoad');

// Validate the complete active theme source tree before executing any of it.
// This also catches post-install tampering of partials/includes, not only index.php.
$activeThemeDirectory = PATH_THEMES . $site->theme() . DS;
$activeThemeMetadata = brendyLoadThemeJson($activeThemeDirectory . 'metadata.json', $activeThemeDirectory);
$activeThemeSafe = is_array($activeThemeMetadata) &&
	brendyValidateExtensionMetadata($activeThemeMetadata, 'theme') &&
	brendyValidateExtensionPhpTreeSecurity($activeThemeDirectory);
if (!$activeThemeSafe) {
	Log::set(__FILE__ . LOG_SEP . 'Blocked unsafe, malformed or modified active theme ' . $site->theme(), LOG_TYPE_ERROR);
	if (!headers_sent()) {
		http_response_code(503);
		header('Cache-Control: no-store, max-age=0');
		header('Retry-After: 3600');
		header('X-Robots-Tag: noindex, nofollow');
	}
	$L->p('Please check your theme configuration in the admin panel. Check for an active theme.');
} else {
	// Theme init.php
	if (Sanitize::pathFile(PATH_THEMES, $site->theme().DS.'init.php')) {
		include(PATH_THEMES.$site->theme().DS.'init.php');
	}

	// Theme HTML
	if (Sanitize::pathFile(PATH_THEMES, $site->theme().DS.'index.php')) {
		include(PATH_THEMES.$site->theme().DS.'index.php');
	} else {
		$L->p('Please check your theme configuration in the admin panel. Check for an active theme.');
	}
}

// Plugins after site loaded
Theme::plugins('afterSiteLoad');

// Plugins after all
Theme::plugins('afterAll');
