<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<!DOCTYPE html>
<html lang="<?php echo Theme::escape(Theme::lang()) ?>" <?php echo Theme::brendyHtmlAttributes() ?>>
<head>
<?php echo Theme::publicHead(array('css/osnova.css', 'css/stil.css'), false); ?>
<?php Theme::plugins('siteHead'); ?>
</head>
<body class="<?php echo Theme::brendyBodyClass('okus-theme') ?> bg-white dark:bg-gray-950 text-gray-900 dark:text-gray-100 antialiased">
	<?php echo Theme::skipLink(); ?>

	<!-- Load Brendy Plugins: Site Body Begin -->
	<?php Theme::plugins('siteBodyBegin'); ?>

	<!-- Navbar -->
	<?php Theme::includePartial('navigacija.php'); ?>

	<!-- Content -->
	<main id="main-content" tabindex="-1" class="max-w-2xl mx-auto px-4 py-8">
		<?php Theme::includeCurrentContent('404.php'); ?>
	</main>

	<?php echo Theme::pluginRegion(); ?>

	<!-- Footer -->
	<?php Theme::includePartial('podnozje.php'); ?>

	<!-- Load shared local frontend assets -->
	<?php echo Theme::publicScripts(array(), false); ?>

	<!-- Load Brendy Plugins: Site Body End -->
	<?php Theme::plugins('siteBodyEnd'); ?>

</body>
</html>
