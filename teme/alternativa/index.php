<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<!DOCTYPE html>
<html lang="<?php echo Theme::escape(Theme::lang()) ?>" <?php echo Theme::brendyHtmlAttributes() ?>>

<head>
	<?php echo Theme::publicHead('css/stil.css', true); ?>
	<?php Theme::plugins('siteHead'); ?>
</head>

<body class="<?php echo Theme::brendyBodyClass('alternativa-theme') ?>">

	<!-- Skip to main content link for accessibility -->
	<?php echo Theme::skipLink(); ?>

	<!-- Load Brendy Plugins: Site Body Begin -->
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

	<!-- Load Brendy Plugins: Site Body End -->
	<?php Theme::plugins('siteBodyEnd'); ?>

</body>

</html>
