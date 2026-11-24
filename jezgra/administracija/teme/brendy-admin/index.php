<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<?php
$adminController = isset($GLOBALS['ADMIN_CONTROLLER']) && is_string($GLOBALS['ADMIN_CONTROLLER'])
	? $GLOBALS['ADMIN_CONTROLLER'] : '';
$needsContentEditor = in_array($adminController, array('new-content', 'edit-content'), true);
$needsSelect2 = $needsContentEditor || $adminController === 'settings';
$needsSortable = $adminController === 'plugins-position';
$cspNonceAttr = brendyCspNonceAttribute();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(str_replace('_', '-', $site->language()), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) ?>">
<head>
	<title><?php echo Sanitize::html(isset($layout['title']) && is_scalar($layout['title']) ? $layout['title'] : BRENDY_NAME) ?></title>
	<meta charset="<?php echo CHARSET ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<meta name="robots" content="noindex,nofollow">
	<meta name="generator" content="<?php echo htmlspecialchars(BRENDY_NAME . ' ' . BRENDY_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) ?>">

	<!-- Favicon -->
	<link rel="shortcut icon" type="image/x-icon" href="<?php echo HTML_PATH_CORE_IMG.'favicon.png?version='.BRENDY_BUILD ?>">

	<!-- Apply the saved appearance before styles paint to avoid a light/dark flash. -->
	<?php echo '<script' . $cspNonceAttr . '>(function(){try{var t=localStorage.getItem("brendy-admin-color-scheme");if(t!=="dark"&&t!=="light")t="auto";var d=t==="dark"||(t==="auto"&&window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches);document.documentElement.setAttribute("data-brendy-admin-theme",d?"dark":"light");document.documentElement.setAttribute("data-bs-theme",d?"dark":"light");document.documentElement.style.colorScheme=d?"dark":"light";}catch(e){}}());</script>' . PHP_EOL; ?>

	<!-- Vendor CSS first; Brendy custom layers last so component styling is deterministic. -->
	<?php
		echo Theme::cssBootstrap();
		echo Theme::cssBootstrapIcons();
		if ($needsSelect2) {
			echo Theme::css(array('select2.min.css', 'select2-brendy5.min.css'), DOMAIN_CORE_CSS);
		}
		echo Theme::css(array(
			'brendy.bootstrap.css',
			'brendy.css'
		), DOMAIN_ADMIN_THEME_CSS);
	?>

	<!-- Javascript -->
	<?php
		echo Theme::jquery();
		echo Theme::jsBootstrap();
		if ($needsSortable) echo Theme::js('jquery-ui.min.js', DOMAIN_CORE_JS, null);
		if ($needsSelect2) echo Theme::js('select2.full.min.js', DOMAIN_CORE_JS, null);
		echo Theme::js('functions.js', DOMAIN_CORE_JS, null);
		echo Theme::js('brendy-admin.js', DOMAIN_ADMIN_THEME_JS, 'defer');

		// Brendy core editor: local or explicitly configured Tiny Cloud, only on editing screens.
		if ($needsContentEditor) {
			$editorBase = HTML_PATH_ROOT . 'jezgra/urednik/';
			$editorConfig = array(
				'baseUrl' => $editorBase . 'tinymce',
				'localScript' => $editorBase . 'tinymce/tinymce.min.js?v=' . rawurlencode(BRENDY_BUILD),
				'cloudScript' => isset($brendyEditorCloudUrl) ? $brendyEditorCloudUrl : '',
				'contentCss' => $editorBase . 'sadrzaj.css?v=' . rawurlencode(BRENDY_BUILD),
				'pageBreak' => PAGE_BREAK,
				'build' => BRENDY_BUILD,
				'documentBaseUrl' => IMAGE_RELATIVE_TO_ABSOLUTE ? DOMAIN_UPLOADS : '',
				'maxDraftBytes' => min(BRENDY_MAX_CONTENT_BYTES, 4500000),
				'draftScope' => substr(hash('sha256', (string)Session::get('username')), 0, 16),
				'serverModifiedAt' => (isset($page) && $page instanceof Page && Valid::date($page->dateModified(DB_DATE_FORMAT), DB_DATE_FORMAT)) ? strtotime($page->dateModified(DB_DATE_FORMAT)) : 0,
				'labels' => array(
					'ready' => $L->g('Editor ready'),
					'unsaved' => $L->g('Unsaved changes'),
					'localSaved' => $L->g('Local draft saved'),
					'localSaveFailed' => $L->g('Local draft could not be saved'),
					'draftTooLarge' => $L->g('Local draft is too large to save'),
					'restoreDraft' => $L->g('A local draft was found. Restore it?'),
					'draftRestored' => $L->g('Local draft restored'),
					'saved' => $L->g('Changes saved'),
					'fallback' => $L->g('Visual editor could not start. Safe text mode is active.'),
					'media' => $L->g('Media manager')
				)
			);
			$editorJson = json_encode($editorConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT |
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			echo '<link rel="stylesheet" href="' . Theme::escape($editorBase . 'urednik.css?v=' . BRENDY_BUILD) . '">' . PHP_EOL;
			if (empty($brendyEditorCloudUrl)) echo '<script src="' . Theme::escape($editorBase . 'tinymce/tinymce.min.js?v=' . BRENDY_BUILD) . '"></script>' . PHP_EOL;
			echo '<script' . $cspNonceAttr . '>window.BrendyEditorConfig=' . $editorJson . ';</script>' . PHP_EOL;
			echo '<script src="' . Theme::escape($editorBase . 'urednik.js?v=' . BRENDY_BUILD) . '"></script>' . PHP_EOL;
		}

		if ($needsSelect2) {
		$select2Messages = array(
			'errorLoading' => $L->get('Results could not be loaded'),
			'inputTooLong' => $L->get('Delete %d characters'),
			'inputTooShort' => $L->get('Enter %d more characters'),
			'loadingMore' => $L->get('Loading more results'),
			'maximumSelected' => $L->get('You can select at most %d items'),
			'noResults' => $L->get('No results found'),
			'searching' => $L->get('Searching'),
			'removeAllItems' => $L->get('Remove all items')
		);
		$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT |
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
		$select2Json = json_encode($select2Messages, $jsonFlags);
		echo '<script' . $cspNonceAttr . '>(function($){"use strict";var m=' . $select2Json . ';' .
			'if($.fn.select2&&$.fn.select2.defaults){var number=function(t,n){return t.replace("%d",String(Math.max(0,n)));};' .
			'$.fn.select2.defaults.set("language",{' .
			'errorLoading:function(){return m.errorLoading;},' .
			'inputTooLong:function(a){return number(m.inputTooLong,a.input.length-a.maximum);},' .
			'inputTooShort:function(a){return number(m.inputTooShort,a.minimum-a.input.length);},' .
			'loadingMore:function(){return m.loadingMore;},' .
			'maximumSelected:function(a){return number(m.maximumSelected,a.maximum);},' .
			'noResults:function(){return m.noResults;},searching:function(){return m.searching;},' .
			'removeAllItems:function(){return m.removeAllItems;}});}})(jQuery);</script>' . PHP_EOL;
		}
	?>

	<!-- Plugins -->
	<?php Theme::plugins('adminHead') ?>

</head>
<body class="h-100 brendy-admin" data-brendy-admin-theme="auto">

<!-- Plugins -->
<?php Theme::plugins('adminBodyBegin') ?>

<!-- Javascript dynamic generated by PHP -->
<?php
	echo '<script' . $cspNonceAttr . ' charset="utf-8">'.PHP_EOL;
	include(PATH_CORE_JS.'variables.php');
	echo '</script>'.PHP_EOL;

	echo '<script' . $cspNonceAttr . ' charset="utf-8">'.PHP_EOL;
	include(PATH_CORE_JS.'brendy-ajax.php');
	echo '</script>'.PHP_EOL;
?>

<!-- Overlay background -->
<div id="jsshadow" hidden></div>

<!-- Alert -->
<?php include(__DIR__ . '/html/alert.php'); ?>

<!-- Shared desktop, mobile and command-palette navigation model -->
<?php include(__DIR__ . '/html/brendy-navigation.php'); ?>

<!-- Navbar, only for small devices -->
<?php include(__DIR__ . '/html/navbar.php'); ?>

<div class="container min-vh-100">
	<!-- 25%/75% split on large devices, small, medium devices hide -->
	<div class="row min-vh-100 align-items-stretch">

		<!-- LEFT SIDEBAR - Display only on large devices -->
		<div class="sidebar col-lg-2 d-none d-lg-block">
		<?php include(__DIR__ . '/html/sidebar.php'); ?>
		</div>

		<!-- RIGHT MAIN -->
		<div class="col-lg-10 pt-3 pb-1 brendy-admin-main">
		<header class="brendy-admin-topbar mb-3" aria-label="<?php echo Theme::escape($L->g('Administration toolbar')) ?>">
			<div class="brendy-admin-topbar__title">
				<i class="bi bi-grid-1x2-fill" aria-hidden="true"></i>
				<span><?php echo Theme::escape(isset($layout['title']) ? $layout['title'] : BRENDY_NAME) ?></span>
			</div>
			<div class="brendy-admin-topbar__actions">
				<button type="button" class="btn btn-sm brendy-command-button" data-brendy-command aria-keyshortcuts="Control+K Meta+K">
					<i class="bi bi-search" aria-hidden="true"></i><span><?php $L->p('Quick search') ?></span><kbd>Ctrl/⌘ K</kbd>
				</button>
				<a class="btn btn-sm btn-light" href="<?php echo HTML_PATH_ROOT ?>" target="_blank" rel="noopener noreferrer" title="<?php echo Theme::escape($L->g('View website')) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="visually-hidden"><?php $L->p('View website') ?></span></a>
				<button type="button" class="btn btn-sm btn-light" data-brendy-admin-theme-toggle title="<?php echo Theme::escape($L->g('Toggle color scheme')) ?>"><i class="bi bi-moon-stars" aria-hidden="true"></i><span class="visually-hidden"><?php $L->p('Toggle color scheme') ?></span></button>
				<a class="brendy-admin-user" href="<?php echo HTML_PATH_ADMIN_ROOT . 'edit-user/' . rawurlencode($login->username()) ?>"><i class="bi bi-person-circle" aria-hidden="true"></i><span><?php echo Theme::escape($login->username()) ?></span></a>
			</div>
		</header>
		<?php
			if (Sanitize::pathFile(PATH_ADMIN_VIEWS, $layout['view'].'.php')) {
				include(PATH_ADMIN_VIEWS.$layout['view'].'.php');
			} elseif ($layout['plugin'] && method_exists($layout['plugin'], 'adminView')) {
				echo $layout['plugin']->adminView();
			} else {
				echo '<h1 class="text-center">'.Theme::escape($L->g('Page not found')).'</h1>';
				echo '<h2 class="text-center">'.Theme::escape($L->g('Choose a page from the sidebar.')).'</h2>';
			}
		?>
		</div>
	</div>
</div>

<div class="brendy-command-palette" data-brendy-command-palette hidden>
	<button type="button" class="brendy-command-palette__backdrop" data-brendy-command-close aria-label="<?php echo Theme::escape($L->g('Close')) ?>"></button>
	<div class="brendy-command-palette__dialog" role="dialog" aria-modal="true" aria-labelledby="brendy-command-title">
		<div class="brendy-command-palette__header">
			<i class="bi bi-search" aria-hidden="true"></i>
			<label id="brendy-command-title" class="visually-hidden" for="brendy-command-query"><?php $L->p('Quick search') ?></label>
			<input id="brendy-command-query" type="search" placeholder="<?php echo Theme::escape($L->g('Search administration')) ?>" autocomplete="off">
			<button type="button" data-brendy-command-close aria-label="<?php echo Theme::escape($L->g('Close')) ?>"><kbd>Esc</kbd></button>
		</div>
		<nav class="brendy-command-palette__list" aria-label="<?php echo Theme::escape($L->g('Administration shortcuts')) ?>">
			<?php foreach ($brendyAdminNavigation as $section) : ?>
				<?php foreach ($section['items'] as $item) : ?>
				<a href="<?php echo Theme::escape($item['url']) ?>"<?php if (!empty($item['target'])) : ?> target="_blank" rel="noopener noreferrer"<?php endif ?>><i class="bi <?php echo Theme::escape($item['icon']) ?>" aria-hidden="true"></i><span><?php echo Theme::escape($item['label']) ?></span></a>
				<?php endforeach ?>
			<?php endforeach ?>
		</nav>
		<p class="brendy-command-palette__empty" hidden><?php $L->p('No results found') ?></p>
	</div>
</div>

<!-- Plugins -->
<?php Theme::plugins('adminBodyEnd') ?>

</body>
</html>
