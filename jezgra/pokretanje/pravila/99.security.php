<?php defined('BRENDY') or die('Brendy CMS.');

// Defense-in-depth same-origin validation for state-changing administrator
// requests. CSRF tokens remain mandatory; Origin/Referer validation blocks an
// additional class of cross-site submission mistakes and token leakage cases.
function brendyAdminSameOriginRequest()
{
	global $security;
	return is_object($security) && method_exists($security, 'sameOriginRequest') && $security->sameOriginRequest();
}

$requestMethod = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
	? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$unsafeMethods = array('POST', 'PUT', 'PATCH', 'DELETE');
if (in_array($requestMethod, $unsafeMethods, true)) {
	if (!brendyAdminSameOriginRequest()) {
		Log::set(__FILE__ . LOG_SEP . 'Rejected cross-origin administrator mutation request.', LOG_TYPE_ERROR);
		http_response_code(403);
		if (isset($layout['slug']) && $layout['slug'] === 'ajax') ajaxResponse(1, $L->g('Forbidden'));
		exit($L->g('Forbidden'));
	}

	$token = isset($_POST['tokenCSRF']) && is_string($_POST['tokenCSRF']) ? $_POST['tokenCSRF'] : false;
	if ($token === false && isset($_SERVER['HTTP_X_BRENDY_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_BRENDY_CSRF_TOKEN'])) {
		$token = $_SERVER['HTTP_X_BRENDY_CSRF_TOKEN'];
	}
	if (!$security->validateTokenCSRF($token)) {
		Log::set(__FILE__.LOG_SEP.'Error occurred when trying to validate the tokenCSRF.', LOG_TYPE_ERROR);
		http_response_code(403);
		if (isset($layout['slug']) && $layout['slug'] === 'ajax') ajaxResponse(1, $L->g('Invalid CSRF token'));
		exit($L->g('Forbidden'));
	}
	unset($_POST['tokenCSRF']);
}
