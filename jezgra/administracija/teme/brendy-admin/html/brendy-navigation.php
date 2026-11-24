<?php defined('BRENDY') or die('Brendy CMS.');

$canManageContent = checkRole(array('admin', 'editor', 'author'), false);
$primaryItems = array(
	array('label' => $L->g('Dashboard'), 'url' => HTML_PATH_ADMIN_ROOT . 'dashboard', 'icon' => 'bi-speedometer2', 'activeSlugs' => array('dashboard')),
	array('label' => $L->g('Website'), 'url' => HTML_PATH_ROOT, 'icon' => 'bi-box-arrow-up-right', 'target' => '_blank')
);
if ($canManageContent) {
	$primaryItems[] = array('label' => $L->g('New content'), 'url' => HTML_PATH_ADMIN_ROOT . 'new-content', 'icon' => 'bi-plus-circle-fill', 'featured' => true, 'activeSlugs' => array('new-content'));
}
$brendyAdminNavigation = array(array('label' => '', 'items' => $primaryItems));

if (checkRole(array('admin'), false)) {
	$brendyAdminNavigation[] = array(
		'label' => $L->g('Manage'),
		'items' => array(
			array('label' => $L->g('Content'), 'url' => HTML_PATH_ADMIN_ROOT . 'content', 'icon' => 'bi-folder2-open', 'activeSlugs' => array('content', 'edit-content')),
			array('label' => $L->g('Categories'), 'url' => HTML_PATH_ADMIN_ROOT . 'categories', 'icon' => 'bi-bookmarks', 'activeSlugs' => array('categories', 'edit-category')),
			array('label' => $L->g('Users'), 'url' => HTML_PATH_ADMIN_ROOT . 'users', 'icon' => 'bi-people', 'activeSlugs' => array('users', 'new-user', 'edit-user', 'user-password'))
		)
	);
	$brendyAdminNavigation[] = array(
		'label' => $L->g('Settings'),
		'items' => array(
			array('label' => $L->g('General'), 'url' => HTML_PATH_ADMIN_ROOT . 'settings', 'icon' => 'bi-sliders', 'activeSlugs' => array('settings')),
			array('label' => $L->g('Plugins'), 'url' => HTML_PATH_ADMIN_ROOT . 'plugins', 'icon' => 'bi-puzzle', 'activeSlugs' => array('plugins', 'configure-plugin', 'install-plugin')),
			array('label' => $L->g('Themes'), 'url' => HTML_PATH_ADMIN_ROOT . 'themes', 'icon' => 'bi-palette2', 'activeSlugs' => array('themes', 'install-theme')),
			array('label' => $L->g('System health'), 'url' => HTML_PATH_ADMIN_ROOT . 'system-health', 'icon' => 'bi-heart', 'activeSlugs' => array('system-health')),
			array('label' => $L->g('Activity log'), 'url' => HTML_PATH_ADMIN_ROOT . 'audit-log', 'icon' => 'bi-journal-text', 'activeSlugs' => array('audit-log')),
			array('label' => $L->g('About'), 'url' => HTML_PATH_ADMIN_ROOT . 'about', 'icon' => 'bi-info-circle', 'activeSlugs' => array('about'))
		)
	);
} else {
	$manageItems = array();
	if ($canManageContent) {
		$manageItems[] = array('label' => $L->g('Content'), 'url' => HTML_PATH_ADMIN_ROOT . 'content', 'icon' => 'bi-folder2-open', 'activeSlugs' => array('content', 'edit-content'));
	}
	$manageItems[] = array('label' => $L->g('Profile'), 'url' => HTML_PATH_ADMIN_ROOT . 'edit-user/' . rawurlencode($login->username()), 'icon' => 'bi-person-circle', 'activeSlugs' => array('edit-user', 'user-password'));
	$brendyAdminNavigation[] = array('label' => $L->g('Manage'), 'items' => $manageItems);
}

$brendyPluginShortcuts = array();
if (checkRole(array('admin', 'editor'), false) && isset($plugins['adminSidebar']) && is_array($plugins['adminSidebar'])) {
	foreach ($plugins['adminSidebar'] as $pluginSidebar) {
		if (!is_object($pluginSidebar) || !method_exists($pluginSidebar, 'adminSidebar')) continue;
		$shortcut = trim((string)$pluginSidebar->adminSidebar());
		if ($shortcut !== '') $brendyPluginShortcuts[] = $shortcut;
	}
}
