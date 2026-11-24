<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<!DOCTYPE html>
<html lang="<?php echo Theme::escape(Theme::lang()) ?>" <?php echo Theme::brendyHtmlAttributes() ?>>
<head>
<?php echo Theme::publicHead('css/stil.css', true); ?>
<?php Theme::plugins('siteHead'); ?>
</head>
<body class="<?php echo Theme::brendyBodyClass('novosti-theme where-' . preg_replace('/[^a-z0-9_-]/i', '', (string) $WHERE_AM_I)) ?>">
	<?php echo Theme::skipLink(); ?>

	<?php Theme::plugins('siteBodyBegin'); ?>

	<?php Theme::includePartial('glavno-zaglavlje.php'); ?>
	<?php Theme::includePartial('navigacija.php'); ?>

	<main class="site-main" id="main-content" tabindex="-1">
		<div class="container">
			<?php if ($WHERE_AM_I === 'page') : ?>
				<div class="layout layout--article">
					<article class="article-col">
						<?php Theme::includePartial('stranica.php'); ?>
					</article>
					<aside class="sidebar-col" aria-label="<?php echo Theme::escape($L->get('Navigation')) ?>">
						<?php Theme::includePartial('bocna-traka.php'); ?>
					</aside>
				</div>
			<?php else : ?>
				<div class="layout layout--home">
					<section class="content-col">
						<?php Theme::includePartial('naslovnica.php'); ?>
					</section>
					<aside class="sidebar-col" aria-label="<?php echo Theme::escape($L->get('Navigation')) ?>">
						<?php Theme::includePartial('bocna-traka.php'); ?>
					</aside>
				</div>
			<?php endif; ?>
		</div>
	</main>

	<?php Theme::includePartial('podnozje.php'); ?>

	<?php echo Theme::publicScripts(); ?>

	<?php Theme::plugins('siteBodyEnd'); ?>

</body>
</html>
