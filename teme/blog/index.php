<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<!DOCTYPE html>
<html lang="<?php echo Theme::escape(Theme::lang()) ?>" <?php echo Theme::brendyHtmlAttributes() ?>>
<head>
<?php echo Theme::publicHead('css/stil.css', true); ?>
<?php Theme::plugins('siteHead'); ?>
</head>
<body class="<?php echo Theme::brendyBodyClass('blog-theme') ?>">
	<?php echo Theme::skipLink(); ?>

	<!-- Load Brendy Plugins: Site Body Begin -->
	<?php Theme::plugins('siteBodyBegin'); ?>

	<!-- Navbar -->
	<?php Theme::includePartial('navigacija.php'); ?>

	<!-- Content -->
	<main class="container" id="main-content" tabindex="-1">
		<div class="row">

			<!-- Blog Posts -->
			<div class="col-md-8">
			<?php Theme::includeCurrentContent(); ?>
			</div>

			<!-- Right Sidebar -->
			<aside class="col-md-3 offset-md-1" aria-label="<?php echo Theme::escape($L->get('Navigation')) ?>">
				<div class="sidebar-container">
					<?php Theme::plugins('siteSidebar'); ?>
				</div>
			</aside>

		</div>
	</main>

	<!-- Footer -->
	<?php Theme::includePartial('podnozje.php'); ?>

	<!-- Shared local frontend assets -->
	<?php echo Theme::publicScripts(); ?>

	<!-- Load Brendy Plugins: Site Body End -->
	<?php Theme::plugins('siteBodyEnd'); ?>

</body>
</html>
