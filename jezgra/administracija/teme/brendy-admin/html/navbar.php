<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<nav class="navbar navbar-expand-lg navbar-dark brendy-mobile-nav d-block d-lg-none" aria-label="<?php echo Theme::escape($L->g('Administration navigation')) ?>">
	<div class="container">
		<a class="navbar-brand" href="<?php echo HTML_PATH_ADMIN_ROOT ?>dashboard"><img src="<?php echo HTML_PATH_CORE_IMG ?>logo.svg" width="24" height="24" alt=""> <span><?php echo Theme::escape(BRENDY_NAME) ?></span></a>
		<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="<?php echo Theme::escape($L->get('Toggle navigation')) ?>">
			<span class="navbar-toggler-icon"></span>
		</button>
		<div class="collapse navbar-collapse" id="navbarNav">
			<?php foreach ($brendyAdminNavigation as $section) : ?>
				<?php if ($section['label'] !== '') : ?><div class="brendy-mobile-nav__heading"><?php echo Theme::escape($section['label']) ?></div><?php endif ?>
				<ul class="navbar-nav">
					<?php foreach ($section['items'] as $item) : ?>
					<?php
					$currentAdminSlug = isset($layout['slug']) && is_string($layout['slug']) ? $layout['slug'] : '';
					$itemActive = isset($item['activeSlugs']) && is_array($item['activeSlugs']) && in_array($currentAdminSlug, $item['activeSlugs'], true);
					?>
					<li class="nav-item"><a class="nav-link<?php echo $itemActive ? ' active' : '' ?>" href="<?php echo Theme::escape($item['url']) ?>"<?php if ($itemActive) : ?> aria-current="page"<?php endif ?><?php if (!empty($item['target'])) : ?> target="_blank" rel="noopener noreferrer"<?php endif ?>><i class="bi <?php echo Theme::escape($item['icon']) ?>" aria-hidden="true"></i><span><?php echo Theme::escape($item['label']) ?></span></a></li>
					<?php endforeach ?>
				</ul>
			<?php endforeach ?>

			<?php if (!empty($brendyPluginShortcuts)) : ?>
				<ul class="navbar-nav brendy-mobile-plugin-links">
					<?php foreach ($brendyPluginShortcuts as $pluginShortcut) : ?>
						<li class="nav-item"><?php echo $pluginShortcut ?></li>
					<?php endforeach ?>
				</ul>
			<?php endif ?>
			<form method="post" action="<?php echo HTML_PATH_ADMIN_ROOT ?>logout" class="mt-2">
				<input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>">
				<button type="submit" class="nav-link btn btn-link"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span><?php $L->p('Logout') ?></span></button>
			</form>
		</div>
	</div>
</nav>
