<?php defined('BRENDY') or die('Brendy CMS.');

echo BrendyAdminUi::pageTitle(array('title'=>$L->g('Developers'), 'icon'=>'gears'));

echo '<h2 class="mb-4 mt-4"><b>' . Theme::escape($L->g('PHP version')) . ': ' .
	Theme::escape(phpversion()) . '</b></h2>';

// PHP Ini
$uploadOptions = array(
	'upload_max_filesize'=>ini_get('upload_max_filesize'),
	'post_max_size'=>ini_get('post_max_size'),
	'upload_tmp_dir'=>ini_get('upload_tmp_dir')
);
printTable($L->g('File uploads'), $uploadOptions);

// Never render credentials, cookies or authorization headers in diagnostics.
$serverInformation = $_SERVER;
foreach ($serverInformation as $serverKey => $serverValue) {
	if (preg_match('/(?:authorization|cookie|password|secret|token|api[_-]?key)/i', (string)$serverKey)) {
		$serverInformation[$serverKey] = '[redacted]';
	}
}
printTable($L->g('Server information') . ' ( $_SERVER )', $serverInformation);

// PHP Ini
printTable($L->g('PHP configuration options') . ' ( ini_get_all() )', ini_get_all());

// Loaded extensions
printTable($L->g('Loaded extensions'), get_loaded_extensions());

// Locales installed
$locales = array();
if (class_exists('ResourceBundle')) {
	$locales = ResourceBundle::getLocales('');
} elseif (function_exists('setlocale')) {
	$currentLocale = setlocale(LC_ALL, 0);
	if (is_string($currentLocale) && $currentLocale !== '') $locales[] = $currentLocale;
}
printTable($L->g('Installed locales'), $locales);

echo '<hr>';
echo '<h2>'.htmlspecialchars(BRENDY_NAME, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET).'</h2>';
echo '<hr>';

// Constants defined by Brendy and its compatibility layer.
$constants = get_defined_constants(true);
printTable($L->g('Brendy constants'), isset($constants['user']) && is_array($constants['user']) ? $constants['user'] : array());

// Site object
printTable($L->g('Site settings database'), $site->db);
