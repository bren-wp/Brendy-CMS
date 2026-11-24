<?php defined('BRENDY') or die('Brendy CMS.');

// Start the session
// If the session is not possible to start the admin area is not available
Session::start(HTML_PATH_ROOT, $site->isHTTPS());
if (Session::started()===false) {
	exit('Brendy CMS. Pokretanje sesije nije uspjelo.');
}

$login = new Login();

$layout = array(
	'controller'=>null,
	'view'=>null,
	'template'=>'index.php',
	'slug'=>null,
	'plugin'=>false,
	'parameters'=>null,
	'title'=>BRENDY_NAME
);

// Get the Controller
$explodeSlug = $url->explodeSlug();
$firstAdminSlug = isset($explodeSlug[0]) && is_string($explodeSlug[0]) ? $explodeSlug[0] : '';
$layout['controller'] = $layout['view'] = $layout['slug'] = $firstAdminSlug === '' ? 'dashboard' : $firstAdminSlug;
unset($explodeSlug[0]);

// Get the URL parameters before loading any extension code. Anonymous admin
// requests must never execute plugin PHP: a legitimate third-party plugin has
// the same PHP privileges as core and therefore does not belong on the password
// entry surface. This also protects failed/forged AJAX requests from exposing
// their payload to extension constructors or hooks before authentication.
$layout['parameters'] = implode('/', $explodeSlug);

// Credential surfaces never need large request bodies. Reject oversized
// password/2FA, password-change and step-up POSTs before loading any extension code or
// controller logic. Re-authentication is intentionally subject to the same
// 64 KiB ceiling as the initial login.
if (in_array($layout['slug'], array('login', 'reauth', 'two-factor', 'user-password'), true) && isset($_SERVER['REQUEST_METHOD']) &&
	strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'POST') {
	$credentialContentLength = isset($_SERVER['CONTENT_LENGTH']) && is_scalar($_SERVER['CONTENT_LENGTH'])
		? filter_var($_SERVER['CONTENT_LENGTH'], FILTER_VALIDATE_INT) : false;
	if ($credentialContentLength !== false && ($credentialContentLength < 0 || $credentialContentLength > 65536)) {
		http_response_code(413);
		exit('Payload Too Large');
	}
}
$authenticatedAdminSession = $login->isLogged();

// Keep hook containers defined even when extensions are intentionally isolated
// from the anonymous login surface. Theme::plugins() safely no-ops on missing
// hook names, while login rendering remains independent of plugin execution.
$plugins = array();
$pluginsInstalled = array();

// --- AJAX ---
if ($layout['slug']==='ajax') {
	if (!$authenticatedAdminSession) {
		http_response_code(401);
		ajaxResponse(1, $L->g('User not logged'));
	}

	// Authenticated AJAX may use plugin services, but plugin code is loaded only
	// after the authenticated session has passed Login::isLogged().
	include(PATH_RULES.'60.plugins.php');

	// Rules: Security check CSRF
	include(PATH_RULES.'99.security.php');

	// Load only a regular AJAX endpoint contained in the core AJAX directory.
	if (!Sanitize::pathFile(PATH_AJAX, $layout['parameters'].'.php')) {
		http_response_code(404);
		ajaxResponse(1, $L->g('Page not found'));
	}
	include(PATH_AJAX.$layout['parameters'].'.php');
	// AJAX endpoints should normally terminate through ajaxResponse(). Fail closed
	// if an endpoint returns without emitting a response.
	http_response_code(204);
	exit;
}
// --- ADMIN AREA ---
else
{
	// Decide the authentication boundary before loading plugin PHP. Anonymous
	// requests render the core login surface with an empty extension runtime.
	$isLoginSurface = $url->notFound() || !$authenticatedAdminSession || ($url->slug()==='login');
	// Credential-management surfaces are extension-isolated too. Keep third-party
	// code, page scheduling and public-theme bootstrapping out of password, 2FA and
	// step-up requests so extension PHP cannot observe submitted credentials.
	$isCredentialSurface = $isLoginSurface || ($authenticatedAdminSession && in_array($layout['slug'], array('reauth', 'two-factor', 'user-password'), true));
	if (!$isCredentialSurface) {
		include(PATH_RULES.'60.plugins.php');
		// Check if the authenticated user wants a controller/view from a plugin.
		if ($layout['controller'] === 'plugin' && !empty($explodeSlug)) {
			$pluginName = normalizeBrendyPluginClassName(array_shift($explodeSlug));
			if (isset($pluginsInstalled[$pluginName])) {
				$layout['plugin'] = $pluginsInstalled[$pluginName];
				$layout['parameters'] = implode('/', $explodeSlug);
			}
		}
	}

	// Keep the anonymous authentication surface minimal. Page scheduling, public
	// content construction, public-theme metadata and paginator hooks are unrelated
	// to authentication and may have side effects, so they are booted only after
	// an authenticated administrator session exists. Security headers and CSRF /
	// same-origin enforcement remain active on both sides of the boundary.
	include(PATH_RULES.'99.header.php');
	if (!$isCredentialSurface) {
		include(PATH_RULES.'69.pages.php');
		include(PATH_RULES.'99.paginator.php');
		include(PATH_RULES.'99.themes.php');
	}
	include(PATH_RULES.'99.security.php');

	if ($isLoginSurface) {
		$layout['controller']	= 'login';
		$layout['view']			= 'login';
		$layout['template']		= 'login.php';

		// Generate a fresh anonymous CSRF token. A successful login rotates both
		// the PHP session identifier and this token again.
		$security->generateTokenCSRF();
	}

	// Define variables
	$ADMIN_CONTROLLER 	= $layout['controller'];
	$ADMIN_VIEW 		= $layout['view'];

	// Load plugins before the admin area will be load.
	Theme::plugins('beforeAdminLoad');

	// Load init.php if the theme has one.
	if (Sanitize::pathFile(PATH_ADMIN_THEMES, $site->adminTheme().DS.'init.php')) {
		include(PATH_ADMIN_THEMES.$site->adminTheme().DS.'init.php');
	}

	// Load controller. Core views are never executable on their own: an orphaned
	// view would otherwise bypass the controller's authorization/initialization
	// contract and can reference undefined state. Plugin routes keep supporting
	// view-only plugins because their object itself is the route owner.
	if (Sanitize::pathFile(PATH_ADMIN_CONTROLLERS, $layout['controller'].'.php')) {
		include(PATH_ADMIN_CONTROLLERS.$layout['controller'].'.php');
	} elseif ($layout['plugin']) {
		if (method_exists($layout['plugin'], 'adminController')) $layout['plugin']->adminController();
	} else {
		http_response_code(404);
		$layout['view'] = '__not_found__';
	}

	// Load view and theme.
	if (Sanitize::pathFile(PATH_ADMIN_THEMES, $site->adminTheme().DS.$layout['template'])) {
		include(PATH_ADMIN_THEMES.$site->adminTheme().DS.$layout['template']);
	}

	// Load plugins after the admin area is loaded.
	Theme::plugins('afterAdminLoad');
}
