<?php defined('BRENDY') or die('Brendy CMS.');

$variables = array(
	'HTML_PATH_ROOT' => HTML_PATH_ROOT,
	'HTML_PATH_ADMIN_ROOT' => HTML_PATH_ADMIN_ROOT,
	'HTML_PATH_ADMIN_THEME' => HTML_PATH_ADMIN_THEME,
	'HTML_PATH_CORE_IMG' => HTML_PATH_CORE_IMG,
	'HTML_PATH_UPLOADS' => HTML_PATH_UPLOADS,
	'HTML_PATH_UPLOADS_THUMBNAILS' => HTML_PATH_UPLOADS_THUMBNAILS,
	'BRENDY_VERSION' => BRENDY_VERSION,
	'BRENDY_BUILD' => BRENDY_BUILD,
	'DOMAIN' => DOMAIN,
	'DOMAIN_BASE' => DOMAIN_BASE,
	'DOMAIN_PAGES' => DOMAIN_PAGES,
	'DOMAIN_ADMIN' => DOMAIN_ADMIN,
	'DOMAIN_CONTENT' => DOMAIN_CONTENT,
	'DOMAIN_UPLOADS' => DOMAIN_UPLOADS,
	'DB_DATE_FORMAT' => DB_DATE_FORMAT,
	'AUTOSAVE_INTERVAL' => (int)AUTOSAVE_INTERVAL,
	'PAGE_BREAK' => PAGE_BREAK,
	'tokenCSRF' => (string)$security->getTokenCSRF(),
	'UPLOAD_MAX_FILESIZE' => min((int)Text::toBytes(ini_get('upload_max_filesize')), BRENDY_MAX_IMAGE_UPLOAD_BYTES)
);
$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG |
	JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
foreach ($variables as $name => $value) {
	$json = json_encode($value, $jsonFlags);
	if (!is_string($json)) $json = 'null';
	echo 'var ' . $name . ' = ' . $json . ';' . PHP_EOL;
}
unset($variables, $jsonFlags, $name, $value, $json);

?>
