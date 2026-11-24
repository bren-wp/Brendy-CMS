<?php defined('BRENDY') or die('Brendy CMS.');

http_response_code((int)$url->httpCode());
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()');
header('X-Permitted-Cross-Domain-Policies: none');
header('X-Download-Options: noopen');

$isAdminResponse = $url->whereAmI() === 'admin';
if ($isAdminResponse) {
	// Administrator resources are first-party and local, so the admin area can
	// use a materially stricter CSP than public themes/plugins that may embed
	// explicitly configured third-party services.
	require_once PATH_KERNEL . 'urednik/cloud.php';
	$brendyEditorCloudUrl = brendyTinyCloudUrl(isset($authenticatedAdminSession) && $authenticatedAdminSession === true, $layout['controller'] ?? '', PATH_CONTENT, isset($_GET['brendy_editor']) && $_GET['brendy_editor'] === 'local');
	$cloudSources = $brendyEditorCloudUrl !== '' ? ' https://*.tiny.cloud https://*.tinymce.com' : '';
	$cspNonce = brendyCspNonce();
	header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . $cspNonce . "'" . $cloudSources . "; style-src 'self' 'unsafe-inline'" . $cloudSources . "; img-src 'self' data: blob:" . $cloudSources . "; font-src 'self' data:" . $cloudSources . "; connect-src 'self'" . $cloudSources . "; media-src 'self' blob:; frame-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'; manifest-src 'self'");
	header('Cache-Control: no-store, max-age=0, private');
	header('Pragma: no-cache');
	header('Expires: 0');
	header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
	header('Cross-Origin-Opener-Policy: same-origin');
	header('Cross-Origin-Resource-Policy: same-origin');
	header('Origin-Agent-Cluster: ?1');
} else {
	$cspNonce = brendyCspNonce();
	header("Content-Security-Policy: script-src 'self' https: 'nonce-" . $cspNonce . "'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
}
if (isset($site) && method_exists($site, 'isHTTPS') && $site->isHTTPS()) {
	header('Strict-Transport-Security: max-age=63072000');
}
if (!empty($GLOBALS['BRENDY_PREVIEW_MODE'])) {
	header('Cache-Control: no-store, max-age=0, private');
	header('Pragma: no-cache');
	header('Expires: 0');
	header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
}
