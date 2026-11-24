<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<!DOCTYPE html>
<html lang="<?php echo Theme::escape(Theme::lang()) ?>" <?php echo Theme::brendyHtmlAttributes() ?>>

<head>
	<?php echo Theme::publicHead(array('css/01-stil.css', 'css/02-pomocnici.css'), true); ?>
	<?php if ($site->fokusDarkMode()) echo Theme::css('css/99-tamni-nacin.css'); ?>
	<?php Theme::plugins('siteHead'); ?>
</head>

<body class="<?php echo Theme::brendyBodyClass('fokus-theme') ?>">
	<?php echo Theme::skipLink(); ?>

	<!-- Execute Brendy plugins for the hook "Site body begin" -->
	<?php Theme::plugins('siteBodyBegin'); ?>

	<!-- Navbar -->
	<?php Theme::includePartial('navigacija.php'); ?>

	<!-- Content -->
	<main id="main-content" tabindex="-1">
	<?php Theme::includeCurrentContent(); ?>
	</main>

	<?php echo Theme::pluginRegion(); ?>

	<!-- Footer -->
	<?php Theme::includePartial('podnozje.php'); ?>

	<!-- Shared local frontend assets -->
	<?php echo Theme::publicScripts(); ?>

	<!-- Execute Brendy plugins for the hook "Site body end" -->
	<?php Theme::plugins('siteBodyEnd'); ?>

</body>

</html>
